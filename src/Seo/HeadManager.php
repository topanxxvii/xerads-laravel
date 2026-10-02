<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use XerAds\Laravel\Seo\Breadcrumbs\BreadcrumbTrail;
use XerAds\Laravel\Seo\Schema\Graph;
use XerAds\Laravel\Support\UrlPath;

/**
 * Decides the current page's head: title, description, canonical, robots,
 * Open Graph and `twitter:*` tags, verification tags and one JSON-LD `@graph`.
 *
 * One per request (a scoped binding, no static state), so under a
 * long-running worker one page's title never reaches the next.
 *
 * ── Who wins ────────────────────────────────────────────────────────────────
 * Each layer overrides the ones before it, value by value:
 * 1. the package's defaults,
 * 2. the settings from the XerAds dashboard,
 * 3. the site's `xerads.seo.overrides`,
 * 4. `pages[]` in the settings, for the exact path,
 * 5. the route's `xerads.robots` default (robots only),
 * 6. the page's model or article (`for($post)`),
 * 7. calls made while handling the request (`Xerads::head()->title(…)`).
 * Some noindex decisions are forced whatever the layers say: outside
 * production (`xerads.seo.noindex_non_production`), on previews, when the
 * settings keep the whole site or this kind of page out of search engines.
 *
 * ── Addresses ───────────────────────────────────────────────────────────────
 * The canonical address, `og:url` and every address in the structured data
 * start from `site.url` in the settings, else `app.url`, never from the
 * request's Host header, which a client chooses. Only the `page` query
 * parameter survives, from page 2 on; tracking parameters never do.
 */
final class HeadManager
{
    /** Where a route keeps its robots choice: `Route::get(…)->xeradsRobots('noindex')`. */
    public const ROUTE_ROBOTS = 'xerads_robots';

    /** Lists, whose `?page=` is part of their address. */
    private const LIST_KINDS = ['archive', 'category', 'tag', 'search'];

    /** Page kinds with a title template of their own. */
    public const KINDS = ['article', 'home', 'category', 'tag', 'archive', 'search', 'not_found', 'page'];

    private const VERIFICATION_TAGS = [
        'google' => 'google-site-verification',
        'bing' => 'msvalidate.01',
        'yandex' => 'yandex-verification',
        'baidu' => 'baidu-site-verification',
        'pinterest' => 'p:domain_verify',
    ];

    private const LOCALES = ['id' => 'id_ID', 'en' => 'en_US'];

    private ?SeoSubject $subject = null;

    private ?string $kind = null;

    private ?string $term = null;

    private ?string $query = null;

    private bool $preview = false;

    /** Set by `paginated()`; list kinds are paginated without it. */
    private bool $paginated = false;

    /** The last resolved head, so the robots header reuses its decision. */
    private ?Head $resolved = null;

    /** Has anything said what this page is? */
    private bool $used = false;

    /** @var array{title?: string, full_title?: string, description?: string, canonical?: string, image?: array{url: string, width: int|null, height: int|null, alt: string|null}, type?: string} */
    private array $runtime = [];

    /** @var list<string|array<string, mixed>> */
    private array $runtimeRobots = [];

    /** @var list<callable(Graph, Head): void> */
    private array $schema = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly BreadcrumbTrail $breadcrumbs,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    /* ── What the page is ───────────────────────────────────────────────── */

    /**
     * The page's model: a turnkey article, a category or tag, any model with
     * XerAds SEO data (`ProvidesSeo`), or an array of values.
     */
    public function for(mixed $subject): self
    {
        $this->used = true;
        $resolved = SeoSubject::from($subject);

        if ($resolved !== null) {
            $this->subject = $resolved;
            $this->kind ??= $resolved->kind;
        }

        return $this;
    }

    /**
     * The kind of page, for its title template and noindex group:
     * article, home, category, tag, archive, search, not_found or page.
     */
    public function page(string $kind, ?string $term = null, ?string $query = null): self
    {
        $this->used = true;
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : 'page';
        $this->term = $term ?? $this->term;
        $this->query = $query ?? $this->query;

        return $this;
    }

    /**
     * The page is one page of a list, so `?page=` is part of its address and
     * title. Lists (archive, category, tag, search) are without saying so;
     * everywhere else `?page=` is ignored, so `/post?page=7` is no page of
     * its own.
     */
    public function paginated(bool $paginated = true): self
    {
        $this->used = true;
        $this->paginated = $paginated;

        return $this;
    }

    /** A preview of something unpublished: never indexed, never followed. */
    public function preview(bool $preview = true): self
    {
        $this->used = true;
        $this->preview = $preview;

        return $this;
    }

    /* ── Calls made while handling the request (the last layer) ──────────── */

    /** The `{title}` of the title template. */
    public function title(string $title): self
    {
        $this->used = true;
        $this->runtime['title'] = $title;

        return $this;
    }

    /** The whole `<title>`, as it is: no template. */
    public function fullTitle(string $title): self
    {
        $this->used = true;
        $this->runtime['full_title'] = $title;

        return $this;
    }

    public function description(string $description): self
    {
        $this->used = true;
        $this->runtime['description'] = $description;

        return $this;
    }

    /** A path or an address on this site. */
    public function canonical(string $url): self
    {
        $this->used = true;
        $this->runtime['canonical'] = $url;

        return $this;
    }

    /**
     * `noindex`, `index, nofollow`, or `['index' => false]`.
     *
     * @param  string|array<string, mixed>  $directives
     */
    public function robots(string|array $directives): self
    {
        $this->used = true;
        $this->runtimeRobots[] = $directives;

        return $this;
    }

    public function noindex(): self
    {
        return $this->robots('noindex');
    }

    public function image(string $url, ?int $width = null, ?int $height = null, ?string $alt = null): self
    {
        $this->used = true;
        $this->runtime['image'] = ['url' => $url, 'width' => $width, 'height' => $height, 'alt' => $alt];

        return $this;
    }

    /** `og:type`: article or website. */
    public function type(string $type): self
    {
        $this->used = true;
        $this->runtime['type'] = $type;

        return $this;
    }

    /**
     * Add to or change the structured data: `fn (Graph $graph, Head $head) => …`.
     *
     * @param  callable(Graph, Head): void  $callback
     */
    public function schema(callable $callback): self
    {
        $this->used = true;
        $this->schema[] = $callback;

        return $this;
    }

    public function breadcrumbs(): BreadcrumbTrail
    {
        return $this->breadcrumbs;
    }

    /* ── Output ──────────────────────────────────────────────────────────── */

    /**
     * @param  list<string>  $only
     * @param  list<string>  $except
     */
    public function toHtml(array $only = [], array $except = []): string
    {
        return $this->resolve()->toHtml($only, $except);
    }

    /** @return array{title: string, meta: list<array<string, string>>, link: list<array{rel: string, href: string}>, jsonld: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return $this->resolve()->toArray();
    }

    /**
     * The robots decision alone, for the `X-Robots-Tag` header: the head's
     * own when the page printed one, else decided from the settings.
     */
    public function robotsDirectives(): RobotsDirectives
    {
        if ($this->resolved !== null) {
            return $this->resolved->robots;
        }

        return $this->decideRobots($this->path(), $this->kind(), $this->matchingPage($this->path()), $this->pageNumber());
    }

    /**
     * Is the page kept out of search engines whatever the settings say,
     * because this is not production? Known without reading the settings.
     */
    public function forcedOutsideProduction(): bool
    {
        return $this->config->get('xerads.seo.noindex_non_production', true) && ! $this->isProduction();
    }

    /** Did the request say anything about the page (a model, a kind, a robots call)? */
    public function isUsed(): bool
    {
        return $this->used || $this->resolved !== null;
    }

    /**
     * Does a `robots.paths[]` rule, or `index_site: false`, apply to this
     * path? Decides whether a response without a head needs the header.
     */
    public function pathIsRuled(): bool
    {
        return $this->settings->get('robots.index_site', true) === false || $this->matchingPathRule($this->path()) !== null;
    }

    public function resolve(): Head
    {
        $path = $this->path();
        $kind = $this->kind();
        $number = $this->pageNumber();
        $entry = $this->matchingPage($path);
        $subject = $this->subject;
        $siteName = $this->settings->string('site.name') ?? 'Site';

        $title = $this->decideTitle($kind, $entry, $number, $siteName);
        $description = $this->text($this->runtime['description'] ?? null)
            ?? $subject->description
            ?? $this->text($entry['description'] ?? null)
            ?? $this->settings->string('meta.default_description');
        $canonical = $this->absolute($this->runtime['canonical'] ?? $subject->canonical ?? $this->text($entry['canonical'] ?? null))
            ?? $this->currentUrl($path, $number);
        $robots = $this->decideRobots($path, $kind, $entry, $number);
        $image = $this->decideImage($entry);
        $language = $subject->language ?? $this->settings->string('site.default_language') ?? 'id';
        $isArticle = $subject !== null && $subject->isArticle;
        $breadcrumbs = $this->trail($canonical, $language);

        $meta = [];
        $add = function (string $group, string $attribute, string $key, ?string $content) use (&$meta): void {
            if ($content !== null && trim($content) !== '') {
                $meta[] = ['group' => $group, 'attribute' => $attribute, 'key' => $key, 'content' => trim($content)];
            }
        };

        $add('description', 'name', 'description', $description);
        $add('robots', 'name', 'robots', $robots->toString());

        if ($kind === 'home') {
            foreach (self::VERIFICATION_TAGS as $service => $name) {
                $add('verification', 'name', $name, $this->settings->string('verification.'.$service));
            }
        }

        $ogTitle = $this->text($this->runtime['title'] ?? null) ?? $subject->ogTitle ?? $subject->title ?? $this->text($entry['title'] ?? null) ?? $title;

        $add('og', 'property', 'og:locale', self::LOCALES[$language] ?? null);
        $add('og', 'property', 'og:type', $this->runtime['type'] ?? ($isArticle ? 'article' : 'website'));
        $add('og', 'property', 'og:title', $ogTitle);
        $add('og', 'property', 'og:description', $subject->ogDescription ?? $description);
        $add('og', 'property', 'og:url', $canonical);
        $add('og', 'property', 'og:site_name', $siteName);

        if ($image !== null) {
            $add('og', 'property', 'og:image', $image['url']);
            $add('og', 'property', 'og:image:width', $image['width'] !== null ? (string) $image['width'] : null);
            $add('og', 'property', 'og:image:height', $image['height'] !== null ? (string) $image['height'] : null);
            $add('og', 'property', 'og:image:alt', $image['alt']);
        }

        if ($isArticle) {
            $add('og', 'property', 'article:published_time', $subject->publishedAt);
            $add('og', 'property', 'article:modified_time', $this->modifiedTime($subject));
            $add('og', 'property', 'article:section', $subject->section);

            foreach ($subject->tags as $tag) {
                $add('og', 'property', 'article:tag', $tag);
            }
        }

        $add('twitter', 'name', 'twitter:card', $image !== null ? 'summary_large_image' : 'summary');
        $add('twitter', 'name', 'twitter:site', $this->settings->string('meta.twitter_site'));

        $graph = new Graph;
        $head = new Head($title, $description, $canonical, $robots, $meta, $graph, $breadcrumbs);

        $this->buildGraph($graph, $head, $kind, $language, $image, $ogTitle);

        return $this->resolved = $head;
    }

    /* ── Decisions ───────────────────────────────────────────────────────── */

    /** @param  array<string, mixed>|null  $entry */
    private function decideTitle(string $kind, ?array $entry, int $number, string $siteName): string
    {
        $full = $this->text($this->runtime['full_title'] ?? null);

        if ($full !== null) {
            return $full;
        }

        $known = $this->text($this->runtime['title'] ?? null) ?? $this->subject?->title;

        // A page's title in the settings is the whole title.
        if ($known === null && $this->text($entry['title'] ?? null) !== null) {
            return (string) $this->text($entry['title'] ?? null);
        }

        $template = match (true) {
            $kind === 'category' || $kind === 'tag' => $this->settings->string('titles.'.$kind),
            in_array($kind, ['home', 'archive', 'search', 'not_found'], true) && $known === null => $this->settings->string('titles.'.$kind),
            $known !== null => $this->settings->string('titles.article'),
            default => '{site}',
        } ?? '{title} {sep} {site}';

        // Page 2 gets a title of its own, or every page of a list shares one.
        if ($number > 1 && ! str_contains($template, '{page}')) {
            $template .= ' {sep} {page}';
        }

        return TitleTemplate::render($template, $this->settings->string('titles.separator') ?? '-', [
            'title' => $known,
            'site' => $siteName,
            'tagline' => $this->settings->string('site.tagline'),
            'term' => $this->term ?? $this->subject?->name,
            'page' => $number > 1 ? (string) __('xerads::seo.page', ['page' => $number]) : null,
            'query' => $this->query,
        ]);
    }

    /**
     * Whether the settings let search engines index the page at this path:
     * the layers the head applies that do not depend on the request
     * (`robots.default`, the path rule, `pages[]`, the model's own noindex,
     * `index_site` and the noindex groups). The sitemap asks this for every
     * address it lists, so it never offers a page whose head says noindex.
     *
     * @param  array<string, bool>|null  $modelRobots  the page's own `robots` (index, follow)
     */
    public function indexes(string $path, string $kind = 'page', ?array $modelRobots = null): bool
    {
        $robots = $this->restrictedByModel($this->settingsRobots($path, $this->matchingPage($path)), $modelRobots);

        return $robots->index && ! $this->forcedBySettings($kind, 1);
    }

    /** @param  array<string, mixed>|null  $entry */
    private function decideRobots(string $path, string $kind, ?array $entry, int $number): RobotsDirectives
    {
        $robots = $this->settingsRobots($path, $entry);
        $routeRobots = $this->route()?->getAction(self::ROUTE_ROBOTS);

        if (is_string($routeRobots) || is_array($routeRobots)) {
            $robots = $robots->with($routeRobots);
        }

        $robots = $this->restrictedByModel($robots, $this->subject?->robots);

        foreach ($this->runtimeRobots as $directives) {
            $robots = $robots->with($directives);
        }

        // Forced, whatever the layers above said.
        if ($this->preview) {
            return $robots->with('noindex, nofollow');
        }

        $forced = ($this->config->get('xerads.seo.noindex_non_production', true) && ! $this->isProduction())
            || $kind === 'not_found'
            || $this->forcedBySettings($kind, $number);

        return $forced ? $robots->noindex() : $robots;
    }

    /**
     * `robots.default`, then the path rule, then the `pages[]` entry.
     *
     * @param  array<string, mixed>|null  $entry
     */
    private function settingsRobots(string $path, ?array $entry): RobotsDirectives
    {
        $default = $this->settings->get('robots.default');
        $robots = RobotsDirectives::fromSettings(is_array($default) ? $default : []);

        if (($rule = $this->matchingPathRule($path)) !== null) {
            $robots = $robots->with($rule);
        }

        if (is_array($entry['robots'] ?? null)) {
            $robots = $robots->with($entry['robots']);
        }

        return $robots;
    }

    /**
     * The model may only restrict. Every XerAds article says `index: true`
     * unless told otherwise, and that must not lift a noindex the settings or
     * the route put on its path.
     *
     * @param  array<string, bool>|null  $modelRobots
     */
    private function restrictedByModel(RobotsDirectives $robots, ?array $modelRobots): RobotsDirectives
    {
        return $modelRobots === null ? $robots : $robots->with(array_filter($modelRobots, fn (bool $value) => $value === false));
    }

    /** `index_site: false`, the noindex group of this kind of page, and pages after the first of a list. */
    private function forcedBySettings(string $kind, int $number): bool
    {
        $noindexGroup = match ($kind) {
            'search' => 'search',
            'category' => 'categories',
            'tag' => 'tags',
            default => null,
        };

        return $this->settings->get('robots.index_site', true) === false
            || ($noindexGroup !== null && $this->settings->get('robots.noindex.'.$noindexGroup) === true)
            || ($number > 1 && $this->settings->get('robots.noindex.paginated') === true);
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @return array{url: string, width: int|null, height: int|null, alt: string|null}|null
     */
    private function decideImage(?array $entry): ?array
    {
        $image = $this->runtime['image'] ?? $this->subject?->image;

        if ($image === null && $this->text($entry['og_image'] ?? null) !== null) {
            $image = ['url' => (string) $this->text($entry['og_image'] ?? null), 'width' => null, 'height' => null, 'alt' => null];
        }

        if ($image === null) {
            $default = $this->settings->get('meta.default_og_image');

            if (is_array($default) && is_string($default['url'] ?? null) && $default['url'] !== '') {
                $image = [
                    'url' => $default['url'],
                    'width' => is_int($default['width'] ?? null) ? $default['width'] : null,
                    'height' => is_int($default['height'] ?? null) ? $default['height'] : null,
                    'alt' => null,
                ];
            }
        }

        if ($image === null) {
            return null;
        }

        $url = $this->absolute($image['url']);

        return $url !== null ? ['url' => $url] + $image : null;
    }

    /**
     * The trail with the home page first, every address absolute; empty when
     * the settings turn breadcrumbs off or the page is the home page.
     *
     * @return list<array{name: string, url: string}>
     */
    private function trail(string $canonical, string $language): array
    {
        if ($this->settings->get('breadcrumbs.enabled', true) === false || $this->breadcrumbs->isEmpty()) {
            return [];
        }

        $trail = [[
            'name' => $this->settings->string('breadcrumbs.home_label') ?? (string) __('xerads::blog.home', [], $language),
            'url' => $this->base().'/',
        ]];

        foreach ($this->breadcrumbs->items() as $item) {
            $trail[] = ['name' => $item['name'], 'url' => $item['url'] !== null && trim($item['url']) !== '' ? $this->onBase(trim($item['url'])) : $canonical];
        }

        return $trail;
    }

    /**
     * @param  array{url: string, width: int|null, height: int|null, alt: string|null}|null  $image
     */
    private function buildGraph(Graph $graph, Head $head, string $kind, string $language, ?array $image, string $headline): void
    {
        $home = $this->base().'/';
        $url = (string) $head->canonical;
        $organization = $home.'#organization';
        $website = $home.'#website';
        $webpage = $url.'#webpage';
        // The organisation's own logo has no dimensions in the settings; the
        // site logo's are used only with the site logo.
        $organizationLogo = $this->settings->string('organization.logo.url');
        $logo = $organizationLogo ?? $this->settings->string('site.logo.url');
        $logoFromSite = $organizationLogo === null;

        $graph->add([
            '@type' => $this->settings->string('organization.type') ?? 'Organization',
            '@id' => $organization,
            'name' => $this->settings->string('organization.name') ?? $this->settings->string('site.name') ?? (string) $this->config->get('app.name'),
            'legalName' => $this->settings->string('organization.legal_name'),
            'url' => $home,
            'logo' => $logo !== null ? [
                '@type' => 'ImageObject',
                '@id' => $home.'#logo',
                'url' => $logo,
                'contentUrl' => $logo,
                'width' => $logoFromSite ? $this->settings->get('site.logo.width') : null,
                'height' => $logoFromSite ? $this->settings->get('site.logo.height') : null,
            ] : null,
            'sameAs' => array_values(array_filter((array) $this->settings->get('organization.same_as', []), 'is_string')),
        ]);

        $search = $this->config->get('xerads.seo.search_url');

        $graph->add([
            '@type' => 'WebSite',
            '@id' => $website,
            'url' => $home,
            'name' => $this->settings->string('site.name'),
            'alternateName' => $this->settings->string('website.alternate_name'),
            'description' => $this->settings->string('site.tagline'),
            'publisher' => Graph::ref($organization),
            'inLanguage' => $language,
            'potentialAction' => is_string($search) && str_contains($search, '{search_term_string}') ? [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->absolute($search)],
                'query-input' => 'required name=search_term_string',
            ] : null,
        ]);

        $subject = $this->subject;
        $isArticle = $subject !== null && $subject->isArticle;

        $graph->add([
            '@type' => match ($kind) {
                'category', 'tag', 'archive' => 'CollectionPage',
                'search' => 'SearchResultsPage',
                default => 'WebPage',
            },
            '@id' => $webpage,
            'url' => $url,
            'name' => $head->title,
            'description' => $head->description,
            'isPartOf' => Graph::ref($website),
            'primaryImageOfPage' => $image !== null ? Graph::ref($url.'#primaryimage') : null,
            'breadcrumb' => $head->breadcrumbs !== [] ? Graph::ref($url.'#breadcrumb') : null,
            'datePublished' => $isArticle ? $subject->publishedAt : null,
            'dateModified' => $isArticle ? $this->modifiedTime($subject) : null,
            'inLanguage' => $language,
        ]);

        if ($head->breadcrumbs !== []) {
            $items = [];

            foreach ($head->breadcrumbs as $position => $crumb) {
                $items[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
            }

            $graph->add(['@type' => 'BreadcrumbList', '@id' => $url.'#breadcrumb', 'itemListElement' => $items]);
        }

        if ($image !== null) {
            $graph->add([
                '@type' => 'ImageObject',
                '@id' => $url.'#primaryimage',
                'url' => $image['url'],
                'contentUrl' => $image['url'],
                'width' => $image['width'],
                'height' => $image['height'],
                'caption' => $image['alt'],
                'inLanguage' => $language,
            ]);
        }

        if ($isArticle) {
            $author = $subject->author ?? $this->defaultAuthor();

            $graph->add([
                '@type' => $this->schemaType($subject->schemaType),
                '@id' => $url.'#article',
                'isPartOf' => Graph::ref($webpage),
                'mainEntityOfPage' => Graph::ref($webpage),
                'headline' => mb_substr($subject->name ?? $headline, 0, 110),
                'description' => $head->description,
                'image' => $image !== null ? Graph::ref($url.'#primaryimage') : null,
                'datePublished' => $subject->publishedAt,
                'dateModified' => $this->modifiedTime($subject),
                'author' => $author !== null
                    ? ['@type' => 'Person', 'name' => $author['name'], 'url' => $author['url']]
                    : Graph::ref($organization),
                'publisher' => Graph::ref($organization),
                'articleSection' => $subject->section,
                'keywords' => $subject->keywords !== [] ? implode(', ', $subject->keywords) : null,
                'wordCount' => $subject->wordCount,
                'inLanguage' => $language,
            ]);
        }

        foreach ((array) $this->config->get('xerads.schema.pieces', []) as $piece) {
            $piece = is_string($piece) ? $this->container->make($piece) : $piece;

            if (is_callable($piece)) {
                $piece($graph, $head);
            }
        }

        foreach ($this->schema as $callback) {
            $callback($graph, $head);
        }
    }

    /**
     * When the article last changed, never before it was published: an
     * article written, then published later unchanged, was modified when it
     * was published as far as readers can tell.
     */
    private function modifiedTime(SeoSubject $subject): ?string
    {
        if ($subject->modifiedAt === null || $subject->publishedAt === null) {
            return $subject->modifiedAt ?? $subject->publishedAt;
        }

        $modified = strtotime($subject->modifiedAt);
        $published = strtotime($subject->publishedAt);

        return $modified !== false && $published !== false && $modified < $published ? $subject->publishedAt : $subject->modifiedAt;
    }

    /** `articles.schema_type` in the settings, else the article's, else BlogPosting. */
    private function schemaType(?string $articleType): string
    {
        $allowed = ['BlogPosting', 'Article', 'NewsArticle'];
        $configured = $this->settings->string('articles.schema_type');

        foreach ([$configured, $articleType] as $type) {
            if ($type !== null && in_array($type, $allowed, true)) {
                return $type;
            }
        }

        return 'BlogPosting';
    }

    /** @return array{name: string, url: string|null}|null */
    private function defaultAuthor(): ?array
    {
        $name = $this->settings->string('articles.default_author.name');

        return $name !== null ? ['name' => $name, 'url' => $this->settings->string('articles.default_author.url')] : null;
    }

    /* ── The request ─────────────────────────────────────────────────────── */

    private function kind(): string
    {
        return $this->kind ?? ($this->path() === '/' ? 'home' : ($this->subject?->isArticle === true ? 'article' : 'page'));
    }

    private function request(): ?Request
    {
        return $this->container->bound('request') ? $this->container->make('request') : null;
    }

    private function route(): ?Route
    {
        $route = $this->request()?->route();

        return $route instanceof Route ? $route : null;
    }

    /** The current path, `/` for the home page, without a trailing slash otherwise. */
    private function path(): string
    {
        // As requested, still percent-encoded: it goes into the canonical
        // address. Comparisons decode it (normalizePath()).
        return '/'.trim((string) $this->request()?->path(), '/');
    }

    private function pageNumber(): int
    {
        if (! $this->paginated && ! in_array($this->kind(), self::LIST_KINDS, true)) {
            return 1;
        }

        $page = $this->request()?->query('page');

        return is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
    }

    /** @return array<string, mixed>|null the `pages[]` entry for this exact path */
    private function matchingPage(string $path): ?array
    {
        foreach ((array) $this->settings->get('pages', []) as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null) && $this->samePath($entry['path'], $path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The `robots.paths[]` rule for this path. A pattern covers its path and
     * everything under it (`/account` covers `/account/settings`, not
     * `/accounts`), as the dashboard promises; `*` matches any characters,
     * for the rest. The longest matching pattern wins.
     *
     * @return array{index?: bool, follow?: bool}|null
     */
    private function matchingPathRule(string $path): ?array
    {
        $best = null;
        $length = -1;

        foreach ((array) $this->settings->get('robots.paths', []) as $rule) {
            $pattern = is_array($rule) && is_string($rule['pattern'] ?? null) ? $rule['pattern'] : null;

            if ($pattern === null || ! self::patternCovers($pattern, $path) || strlen($pattern) <= $length) {
                continue;
            }

            $best = array_filter([
                'index' => is_bool($rule['index'] ?? null) ? $rule['index'] : null,
                'follow' => is_bool($rule['follow'] ?? null) ? $rule['follow'] : null,
            ], fn ($value) => $value !== null);
            $length = strlen($pattern);
        }

        return $best;
    }

    /** Does a `robots.paths[]` pattern cover this (normalised) path? */
    public static function patternCovers(string $pattern, string $path): bool
    {
        $path = self::normalizePath($path);

        if (str_contains($pattern, '*')) {
            $regex = implode('.*', array_map(fn (string $part) => preg_quote($part, '#'), explode('*', rawurldecode($pattern))));

            return preg_match('#^'.$regex.'$#u', $path) === 1;
        }

        $pattern = self::normalizePath($pattern);

        return $pattern === '/' || $path === $pattern || str_starts_with($path, $pattern.'/');
    }

    private function samePath(string $a, string $b): bool
    {
        return self::normalizePath($a) === self::normalizePath($b);
    }

    /**
     * A path as compared: decoded (`/tentang-caf%C3%A9` is `/tentang-café`),
     * without its query, without a trailing slash, `/` for the home page.
     */
    public static function normalizePath(string $path): string
    {
        return '/'.trim(rawurldecode(UrlPath::of($path)), '/');
    }

    /** The current page's address: the site's own base, this path, and `page` from 2 on. */
    private function currentUrl(string $path, int $number): string
    {
        $url = $this->base().($path === '/' ? '/' : $path);
        $allowed = (array) $this->config->get('xerads.seo.canonical_query_allowlist', ['page']);
        $query = [];

        foreach ($allowed as $parameter) {
            $value = is_string($parameter) ? $this->request()?->query($parameter) : null;

            if (! is_string($value) || $value === '' || $parameter === 'page' && $number <= 1) {
                continue;
            }

            $query[$parameter] = $parameter === 'page' ? (string) $number : $value;
        }

        return $query !== [] ? $url.'?'.http_build_query($query) : $url;
    }

    /**
     * An absolute address: a path is put under the site's own base, and so is
     * an address on one of the site's own hosts (`site.url`, `app.url`).
     * Anything else is kept as it is: a canonical on a partner's domain stays
     * on that domain. The request's Host header plays no part; a client
     * chooses it.
     */
    private function absolute(?string $url): ?string
    {
        $url = $this->text($url);

        if ($url === null) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            return $this->base().$url;
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, $this->ownHosts(), true) ? $this->onBase($url) : $url;
    }

    /**
     * An address of a page on this site, whatever host it was written with:
     * its path and query under the site's own base. For the breadcrumb trail,
     * whose items are this site's pages by definition, so an address
     * `url()` built from a forged Host header lands back on the site.
     */
    private function onBase(string $url): string
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $this->base().$url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return $this->base().($path !== '' ? $path : '/').(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    /** @return list<string> the hosts of `site.url` and `app.url` */
    private function ownHosts(): array
    {
        $hosts = [];

        foreach ([$this->settings->string('site.url'), (string) $this->config->get('app.url', '')] as $url) {
            $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /** `site.url` from the settings, else `app.url`, without a trailing slash. */
    private function base(): string
    {
        $base = $this->settings->string('site.url') ?? (string) $this->config->get('app.url', '');

        return rtrim($base, '/');
    }

    private function isProduction(): bool
    {
        return $this->container instanceof Application && $this->container->isProduction();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
