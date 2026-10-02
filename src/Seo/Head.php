<?php

namespace XerAds\Laravel\Seo;

use XerAds\Laravel\Seo\Schema\Graph;

/**
 * A page's head, resolved: what HeadManager decided, ready to print.
 *
 * Each tag belongs to a group (`title`, `description`, `canonical`,
 * `robots`, `verification`, `og`, `twitter`, `jsonld`), which `only` and
 * `except` select by, for a layout that prints some tags itself. Every value
 * is escaped when printed; the JSON-LD is encoded so it cannot close its
 * `<script>`.
 */
final class Head
{
    public const GROUPS = ['title', 'description', 'canonical', 'robots', 'verification', 'og', 'twitter', 'jsonld'];

    /**
     * @param  list<array{group: string, attribute: string, key: string, content: string}>  $meta  `<meta>` tags in order
     * @param  list<array{name: string, url: string}>  $breadcrumbs  home first, absolute addresses
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $canonical,
        public readonly RobotsDirectives $robots,
        public readonly array $meta,
        public readonly Graph $graph,
        public readonly array $breadcrumbs,
    ) {}

    /**
     * For headless and Inertia front ends.
     *
     * @return array{title: string, meta: list<array<string, string>>, link: list<array{rel: string, href: string}>, jsonld: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'meta' => array_map(fn (array $tag) => [$tag['attribute'] => $tag['key'], 'content' => $tag['content']], $this->meta),
            'link' => $this->canonical !== null ? [['rel' => 'canonical', 'href' => $this->canonical]] : [],
            'jsonld' => $this->graph->isEmpty() ? [] : [$this->graph->toArray()],
        ];
    }

    /**
     * The tags, one per line, in the order of GROUPS.
     *
     * @param  list<string>  $only  groups to print; empty for all
     * @param  list<string>  $except  groups to leave out
     */
    public function toHtml(array $only = [], array $except = []): string
    {
        $lines = [];

        foreach (self::GROUPS as $group) {
            if (($only !== [] && ! in_array($group, $only, true)) || in_array($group, $except, true)) {
                continue;
            }

            if ($group === 'title') {
                $lines[] = '<title>'.e($this->title).'</title>';
            } elseif ($group === 'canonical') {
                if ($this->canonical !== null) {
                    $lines[] = '<link rel="canonical" href="'.e($this->canonical).'">';
                }
            } elseif ($group === 'jsonld') {
                $json = $this->graph->isEmpty() ? null : $this->graph->toJson();

                if ($json !== null) {
                    $lines[] = '<script type="application/ld+json">'.$json.'</script>';
                }
            } else {
                foreach ($this->meta as $tag) {
                    if ($tag['group'] === $group) {
                        $lines[] = '<meta '.$tag['attribute'].'="'.e($tag['key']).'" content="'.e($tag['content']).'">';
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> the `content` of every meta tag with this name or property */
    public function metaContent(string $key): array
    {
        return array_values(array_map(fn (array $tag) => $tag['content'], array_filter($this->meta, fn (array $tag) => $tag['key'] === $key)));
    }
}
