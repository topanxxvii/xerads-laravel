<?php

namespace XerAds\Laravel\Seo\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Response;
use Throwable;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Seo\ContentVersion;
use XerAds\Laravel\Seo\Models\SeoMeta;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Support\Tables;

/**
 * `/llms.txt`: the site in a few lines of Markdown for language models, from
 * the settings (`llms_txt`): its name, the summary, the latest 100 articles
 * and, for the blog, its categories. Off unless the settings (or
 * `xerads.llms_txt.enabled`) turn it on. Cached per content version.
 */
final class LlmsTxtController
{
    private const ARTICLES = 100;

    public function __invoke(SettingsRepository $settings, SiteAddress $address, ContentVersion $version, Tables $tables, CacheFactory $cache, Repository $config): Response
    {
        $configured = $config->get('xerads.llms_txt.enabled');
        $enabled = is_bool($configured) ? $configured : $settings->get('llms_txt.enabled', false) === true;

        if (! $enabled) {
            abort(404);
        }

        $store = $config->get('xerads.cache.store');
        $key = implode(':', [(string) $config->get('xerads.cache.prefix', 'xerads'), 'llms', $settings->cacheKey(), $version->current()]);

        $repository = $cache->store(is_string($store) && $store !== '' ? $store : null);

        try {
            $body = $repository->get($key);
        } catch (Throwable) {
            // Without a working cache, built for every request.
            $body = null;
            $repository = null;
        }

        if (! is_string($body)) {
            $body = $this->build($settings, $address, $tables, $config);

            try {
                $repository?->put($key, $body, 3600);
            } catch (Throwable) {
                // Built again next time.
            }
        }

        return new Response((string) $body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function build(SettingsRepository $settings, SiteAddress $address, Tables $tables, Repository $config): string
    {
        $lines = ['# '.$this->line($settings->string('site.name') ?? (string) $config->get('app.name'))];
        $summary = $settings->string('llms_txt.summary') ?? $settings->string('site.tagline');

        if ($summary !== null) {
            $lines[] = '';
            $lines[] = '> '.$this->line($summary);
        }

        $articles = $this->articles($address, $tables, $config);

        if ($articles !== []) {
            $lines[] = '';
            $lines[] = '## Articles';
            $lines[] = '';
            array_push($lines, ...$articles);
        }

        if ($config->get('xerads.content.mode') === 'turnkey' && $tables->exists('categories')) {
            $categories = Category::query()
                ->whereHas('articles', fn ($query) => $query->published())
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => '- ['.$this->label($category->name).']('.$address->url('/'.Article::prefix().'/category/'.$category->slug).')')
                ->all();

            if ($categories !== []) {
                $lines[] = '';
                $lines[] = '## Categories';
                $lines[] = '';
                array_push($lines, ...$categories);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function articles(SiteAddress $address, Tables $tables, Repository $config): array
    {
        $entries = [];

        if ($config->get('xerads.content.mode') === 'turnkey' && $tables->exists('articles')) {
            foreach (Article::query()->published()->orderByDesc('published_at')->limit(self::ARTICLES)->get() as $article) {
                $entries[] = $this->entry($article->title, $address->url(Article::pathFor($article->slug)), $article->excerpt);
            }

            return $entries;
        }

        if (! $tables->exists('content_map')) {
            return [];
        }

        $published = ContentMapEntry::query()->where('state', 'published')->whereNotNull('last_url')->orderByDesc('updated_at')->limit(self::ARTICLES)->get();

        foreach ($published as $entry) {
            $meta = $entry->model_type !== null ? SeoMeta::query()->where('seoable_type', $entry->model_type)->where('seoable_id', $entry->model_id)->first() : null;
            $title = $meta?->extra('headline') ?? $meta?->title;

            if (is_string($title) && $title !== '') {
                $entries[] = $this->entry($title, $address->url((string) $entry->last_url), $meta?->description);
            }
        }

        return $entries;
    }

    private function entry(string $title, string $url, ?string $excerpt): string
    {
        $excerpt = $excerpt !== null ? $this->line($excerpt) : '';

        return '- ['.$this->label($title).']('.str_replace([' ', ')'], ['%20', '%29'], $url).')'.($excerpt !== '' ? ': '.$excerpt : '');
    }

    /** Link text: brackets escaped, one line. */
    private function label(string $text): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $this->line($text));
    }

    private function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    }
}
