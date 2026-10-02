<?php

namespace XerAds\Laravel\Content\Receivers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ArticleReference;
use XerAds\Laravel\Content\Data\DeliveryContext;
use XerAds\Laravel\Content\Data\Receipt;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;
use XerAds\Laravel\Content\Exceptions\WriteConflict;
use XerAds\Laravel\Content\Media\MediaMirror;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Content\Models\Media;
use XerAds\Laravel\Content\Models\Tag;
use XerAds\Laravel\Seo\Redirects\Redirect;
use XerAds\Laravel\Seo\SeoMetaWriter;
use XerAds\Laravel\Support\Tables;

/**
 * The package's own blog: deliveries become `xerads_articles` rows, served
 * at `/{prefix}/{slug}`.
 *
 * The ordering, duplicate and ledger rules are the handlers', as for the
 * mapped receiver. What this receiver decides:
 *
 * - **The row:** the one the content map points at, else the one with this
 *   XerAds id, trashed rows included (XerAds sending a deleted article again
 *   brings it back).
 * - **The slug:** XerAds' offer, made unique, followed while the article has
 *   never been public. Once it has, the address is kept
 *   (`content.slug.freeze_after_publish`): a retitled article must not break
 *   links and rankings. A site that turns freezing off gets the new address,
 *   and the old one answers 301 to it from `xerads_redirects`.
 * - **Status:** `publish` makes it public (first publish sets `published_at`),
 *   `draft` takes it back to a draft; `article.unpublish` marks it
 *   unpublished. Either way the page answers 404.
 * - **Delete:** soft-deleted, and an address that was ever public answers
 *   410 (`content.turnkey.deleted`), so search engines drop it quickly. One
 *   that never was is released for other articles.
 * - **Taxonomy:** categories and tags are found or created by slug and
 *   attached as XerAds lists them; the first category is the primary one.
 * - **Images:** ones copied before show their copy at once; new ones are
 *   copied after the response (MirrorArticleMedia), and the page shows
 *   XerAds' address until then.
 */
final class TurnkeyReceiver implements ContentReceiver
{
    /** Room for a `-2`… suffix inside the 191-character column. */
    private const SLUG_LENGTH = 180;

    private const TABLES = ['articles', 'categories', 'tags', 'article_category', 'article_tag'];

    public function __construct(
        private readonly Tables $tables,
        private readonly SeoMetaWriter $seo,
        private readonly MediaMirror $media,
        private readonly Repository $config,
    ) {}

    public function receive(ArticlePayload $article, DeliveryContext $context): Receipt
    {
        $this->ensureTables();

        $record = $this->locate($article->xeradsId, $context);
        $creating = $record === null;
        $record ??= new Article(['xerads_id' => $article->xeradsId]);
        $contentChanged = $creating || ! $context->statusOnly;

        try {
            if ($record->exists && $record->trashed()) {
                $record->restore();
                Redirect::forgetAuto(Article::pathFor($record->slug));
            }

            $previousSlug = $record->exists ? $record->slug : null;
            $everPublic = $this->everPublic($record, $article, $context);
            $categories = $contentChanged ? $this->termIds(Category::class, $article->taxonomy, 'categories') : null;
            $tags = $contentChanged ? $this->termIds(Tag::class, $article->taxonomy, 'tags') : null;

            if ($contentChanged) {
                $this->applyContent($record, $article, $context);
            }

            if ($categories !== null) {
                $record->primary_category_id = $categories[0] ?? null;
            }

            $this->applySlug($record, $article, $creating, $everPublic);
            $this->applyStatus($record, $article);
            $record->save();

            if ($categories !== null) {
                $record->categories()->sync($categories);
            }

            if ($tags !== null) {
                $record->tags()->sync($tags);
            }

            if ($contentChanged) {
                $this->seo->write($record, $article, $context->content);
            }

            if ($previousSlug !== null && $previousSlug !== $record->slug) {
                $this->moved($previousSlug, $record->slug, $everPublic);
            } elseif ($record->status === Article::PUBLISHED) {
                // The address is served again: a 301 or 410 the package left
                // for it earlier would only answer if the page went away.
                Redirect::forgetAuto(Article::pathFor($record->slug));
            }
        } catch (UniqueConstraintViolationException $exception) {
            throw new WriteConflict('Another delivery took the same slug a moment ago. Retry and the next free one is used.', 0, $exception);
        }

        $published = $record->status === Article::PUBLISHED;

        return new Receipt(
            id: $record->getKey(),
            state: $published ? Receipt::PUBLISHED : Receipt::DRAFT,
            url: $published ? $record->url() : null,
            previewUrl: $published ? null : $record->previewUrl(),
            created: $creating,
            model: $record,
        );
    }

    /**
     * Off the site, kept: the page answers 404 until XerAds publishes the
     * article again. A trashed article stays in the trash.
     */
    public function unpublish(ArticleReference $article, DeliveryContext $context): Receipt
    {
        $this->ensureTables();

        $record = $this->locate($article->xeradsId, $context);

        if ($record === null) {
            return Receipt::unknown();
        }

        if (! $record->trashed()) {
            $record->status = Article::UNPUBLISHED;
            $record->save();
        }

        return new Receipt($record->getKey(), Receipt::UNPUBLISHED, model: $record);
    }

    /**
     * Soft-deleted, so a mistaken delete in XerAds can be undone by sending
     * the article again. An address that was ever public answers 410.
     */
    public function delete(ArticleReference $article, DeliveryContext $context): Receipt
    {
        $this->ensureTables();

        $record = $this->locate($article->xeradsId, $context);

        if ($record === null) {
            return Receipt::unknown();
        }

        if (! $record->trashed()) {
            if ($record->published_at === null) {
                // Never public, so nothing links to its address: a new
                // article may have it. Sent again, the article asks for its
                // slug anew.
                $record->slug = $this->releasedSlug($record);
                $record->save();
            }

            $record->delete();

            if ($record->published_at !== null && (int) $this->config->get('xerads.content.turnkey.deleted', 410) === 410) {
                Redirect::auto(Article::pathFor($record->slug), null, 410);
            }
        }

        return new Receipt($record->getKey(), Receipt::DELETED, model: $record);
    }

    public function validateConfiguration(): ConfigurationReport
    {
        $missing = array_values(array_filter(self::TABLES, fn (string $table) => ! $this->tables->exists($table)));

        if ($missing !== []) {
            return new ConfigurationReport([$this->missingTablesMessage()]);
        }

        $warnings = [];

        if (! app(Router::class)->has('xerads.blog.show')) {
            $warnings[] = 'The blog routes are not registered. XERADS_CONTENT_MODE must be turnkey when the application boots; after changing it, run `php artisan config:clear` and `php artisan route:clear` (or cache both again).';
        }

        $layout = (string) $this->config->get('xerads.content.turnkey.layout', 'xerads::layouts.blog');

        if (! app(ViewFactory::class)->exists($layout)) {
            $warnings[] = "The blog layout {$layout} (xerads.content.turnkey.layout) does not exist, so blog pages cannot render.";
        }

        return new ConfigurationReport([], $warnings);
    }

    private function ensureTables(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tables->exists($table)) {
                throw new ReceiverMisconfigured($this->missingTablesMessage());
            }
        }
    }

    private function missingTablesMessage(): string
    {
        return 'The turnkey blog tables do not exist yet. Run `php artisan migrate` with XERADS_CONTENT_MODE=turnkey set, or publish them first with `php artisan vendor:publish --tag=xerads-turnkey-migrations`.';
    }

    /** The row this article became, trashed or not. */
    private function locate(string $xeradsId, DeliveryContext $context): ?Article
    {
        $previous = $context->previous;

        if ($previous !== null && $previous->model_type === (new Article)->getMorphClass() && $previous->model_id !== null && ctype_digit($previous->model_id)) {
            $record = Article::withTrashed()->find((int) $previous->model_id);

            if ($record instanceof Article && ($record->xerads_id === null || $record->xerads_id === $xeradsId)) {
                return $record;
            }
        }

        return Article::withTrashed()->where('xerads_id', $xeradsId)->first();
    }

    /**
     * Has the article ever been public here? Any one sign is enough: the row
     * was published, the content map recorded a publish, or XerAds says it
     * was published on this site.
     */
    private function everPublic(Article $record, ArticlePayload $article, DeliveryContext $context): bool
    {
        return ($record->exists && $record->published_at !== null)
            || $context->previous?->wasEverPublished() === true
            || $article->dates['published_at'] !== null;
    }

    private function applyContent(Article $record, ArticlePayload $article, DeliveryContext $context): void
    {
        $content = $context->content;
        $source = $content !== null ? $content->sourceHtml : $article->html;
        $compiled = $content !== null ? $content->compiledHtml : $article->html;

        $record->forceFill([
            'language' => $article->language !== null ? mb_substr($article->language, 0, 8) : null,
            'title' => mb_substr($article->title !== '' ? $article->title : $article->xeradsId, 0, 512),
            'headline' => $article->headline !== null ? mb_substr($article->headline, 0, 512) : null,
            'excerpt' => $content !== null ? $content->excerpt : $article->excerpt,
            // Images copied for an earlier revision show their copy at once.
            'body_source' => $this->media->rewriteKnown($source),
            'body_html' => $this->media->rewriteKnown($compiled),
            'toc' => $content !== null ? $content->toc : $article->toc,
            'word_count' => $content !== null ? $content->wordCount : ($article->wordCount ?? 0),
            'reading_time' => $content !== null ? $content->readingTimeMinutes : ($article->readingTimeMinutes ?? 0),
            'revision' => $article->revision,
            'widgets' => $article->widgets,
            'author' => $article->author,
            'content_updated_at' => $this->date($article->dates['content_updated_at']) ?? Carbon::now(),
        ]);

        $this->applyImage($record, $article);
    }

    /** The featured image: its copy when there is one, else XerAds' address. */
    private function applyImage(Article $record, ArticlePayload $article): void
    {
        $image = $article->image;

        // Printed into `src`: an address that is not a web address is no image.
        if ($image !== null && preg_match('#^https?://#i', $image['url']) !== 1) {
            $image = null;
        }

        if ($image === null) {
            $record->forceFill([
                'featured_image_url' => null,
                'featured_image_alt' => null,
                'featured_image_caption' => null,
                'featured_image_width' => null,
                'featured_image_height' => null,
                'featured_image_mime' => null,
                'featured_image_path' => null,
            ]);

            return;
        }

        $copy = $this->media->enabled() ? ($this->media->known([$image['url']])[$image['url']] ?? null) : null;

        $record->forceFill([
            'featured_image_url' => $copy instanceof Media ? $copy->url() : $image['url'],
            'featured_image_alt' => $image['alt'],
            'featured_image_caption' => $image['caption'],
            'featured_image_width' => $image['width'] ?? $copy?->width,
            'featured_image_height' => $image['height'] ?? $copy?->height,
            'featured_image_mime' => $image['mime'] ?? $copy?->mime,
            'featured_image_path' => $copy?->path,
        ]);
    }

    private function applySlug(Article $record, ArticlePayload $article, bool $creating, bool $everPublic): void
    {
        $offered = $this->offeredSlug($article);

        if ($creating || (string) $record->getAttribute('slug') === '') {
            $record->slug = $this->availableSlug($record, $offered);

            return;
        }

        if ($offered === $record->slug || ($everPublic && $this->config->get('xerads.content.slug.freeze_after_publish', true))) {
            return;
        }

        $record->slug = $this->availableSlug($record, $offered);
    }

    private function applyStatus(Article $record, ArticlePayload $article): void
    {
        if (! $article->isPublished()) {
            // Back to a draft; `published_at` stays, as the record of the
            // first publish that keeps the slug frozen.
            $record->status = Article::DRAFT;

            return;
        }

        $record->status = Article::PUBLISHED;
        $record->published_at ??= $this->date($article->dates['published_at']) ?? Carbon::now();
    }

    /**
     * The address moved: a page that was public keeps answering, with a 301
     * to the new one. One that never was has nothing to keep.
     */
    private function moved(string $from, string $to, bool $everPublic): void
    {
        Redirect::forgetAuto(Article::pathFor($to));

        if ($everPublic) {
            Redirect::auto(Article::pathFor($from), Article::pathFor($to), 301);
        }
    }

    /**
     * The ids of the terms XerAds lists for the article, found or created.
     * Null when the list is not there at all (an older XerAds): the
     * article's terms are then left as they are. An empty list empties them.
     *
     * @param  class-string<Category>|class-string<Tag>  $class
     * @param  array<string, mixed>  $taxonomy
     * @return list<int>|null
     */
    private function termIds(string $class, array $taxonomy, string $key): ?array
    {
        if (! array_key_exists($key, $taxonomy) || ! is_array($taxonomy[$key])) {
            return null;
        }

        return $this->terms($class, $taxonomy[$key]);
    }

    /**
     * Find or create each term by slug, keeping XerAds' name for it.
     * Items are `{name, slug}`, or a bare name.
     *
     * @param  class-string<Category>|class-string<Tag>  $class
     * @param  array<mixed>  $items
     * @return list<int>
     */
    private function terms(string $class, array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : $item;
            $name = is_string($name) ? trim($name) : '';
            $slug = is_array($item) && is_string($item['slug'] ?? null) && trim($item['slug']) !== '' ? $item['slug'] : $name;
            $slug = mb_substr(Str::slug($slug), 0, 191);

            if ($slug === '') {
                continue;
            }

            $term = $class::query()->firstOrNew(['slug' => $slug]);

            if (! $term->exists || ($name !== '' && $term->name !== $name)) {
                $term->name = mb_substr($name !== '' ? $name : $slug, 0, 255);
                $term->save();
            }

            if (! in_array($term->id, $ids, true)) {
                $ids[] = $term->id;
            }
        }

        return $ids;
    }

    private function offeredSlug(ArticlePayload $article): string
    {
        foreach ([$article->slug, $article->title] as $candidate) {
            $slug = mb_substr(Str::slug($candidate), 0, self::SLUG_LENGTH);

            if ($slug !== '') {
                return trim($slug, '-');
            }
        }

        return 'article-'.strtolower($article->xeradsId);
    }

    /**
     * The slug, or the slug with a `-2`, `-3`… suffix while it is not free:
     * another article has it (a deleted one included, when its address
     * answers 410), its address still redirects to another page, which a new
     * article must not take over, or a route of the site's own answers it.
     */
    private function availableSlug(Article $record, string $slug): string
    {
        $candidate = $slug;

        for ($suffix = 2; $this->slugTaken($record, $candidate); $suffix++) {
            $candidate = $suffix <= 50 ? $slug.'-'.$suffix : $slug.'-'.Str::lower(Str::random(6));
        }

        return $candidate;
    }

    /** A slug no article would be offered, for a deleted article that never was public. */
    private function releasedSlug(Article $record): string
    {
        $slug = mb_substr('deleted-'.$record->getKey().'-'.$record->slug, 0, 191);

        return $this->slugTaken($record, $slug) ? mb_substr('deleted-'.$record->getKey().'-'.Str::lower(Str::random(12)), 0, 191) : $slug;
    }

    private function slugTaken(Article $record, string $slug): bool
    {
        $query = Article::withTrashed()->where('slug', $slug);

        if ($record->exists) {
            $query->whereKeyNot($record->getKey());
        }

        if ($query->exists()) {
            return true;
        }

        // An article moving back to one of its own earlier addresses may.
        $own = $record->exists ? Article::pathFor((string) $record->getOriginal('slug')) : null;

        if ($this->tables->exists('redirects') && Redirect::movesAway(Article::pathFor($slug), $own)) {
            return true;
        }

        return $this->routedElsewhere(Article::pathFor($slug));
    }

    /**
     * Does one of the site's own routes answer this path? Those are matched
     * before the blog's, so an article there would never be shown.
     */
    private function routedElsewhere(string $path): bool
    {
        try {
            $route = app(Router::class)->getRoutes()->match(Request::create($path));
        } catch (Throwable) {
            return false;
        }

        return $route->getName() !== 'xerads.blog.show';
    }

    /**
     * A date from XerAds (UTC, `…Z`) in the application's timezone. Eloquent
     * stores a date's wall-clock time as it is, so a UTC date stored on a
     * Jakarta site would read back seven hours late, and an article just
     * published would be "scheduled" for later.
     */
    private function date(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $timezone = $this->config->get('app.timezone');

        try {
            return Carbon::parse($value)->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : date_default_timezone_get());
        } catch (Throwable) {
            return null;
        }
    }
}
