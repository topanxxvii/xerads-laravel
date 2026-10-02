<?php

namespace XerAds\Laravel\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;

/**
 * `<x-xerads::toc :for="$post" />`: the article's table of contents, every
 * H2 and H3 linked by the id the body carries. From a turnkey article, any
 * model with XerAds SEO data, or a list given as `:items`. Nothing when there
 * are no headings.
 */
final class Toc extends Component
{
    /** @param  list<array{id: string, text: string, level: int}>|null  $items */
    public function __construct(
        public mixed $for = null,
        public ?array $items = null,
        public ?string $title = null,
        public ?string $lang = null,
    ) {}

    public function render(): Htmlable
    {
        $entries = [];

        foreach ($this->entries() as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && $entry['id'] !== '') {
                $level = is_int($entry['level'] ?? null) ? $entry['level'] : 2;
                $entries[] = '<li class="xerads-toc-level-'.$level.'"><a href="#'.e($entry['id']).'">'.e((string) ($entry['text'] ?? '')).'</a></li>';
            }
        }

        if ($entries === []) {
            return new HtmlString('');
        }

        $title = $this->title ?? (string) __('xerads::blog.toc', [], $this->language());

        return new HtmlString('<nav class="xerads-toc" aria-labelledby="xerads-toc-title"><p id="xerads-toc-title"><strong>'.e($title).'</strong></p><ol>'.implode('', $entries).'</ol></nav>');
    }

    /** @return array<mixed> */
    private function entries(): array
    {
        return match (true) {
            $this->items !== null => $this->items,
            $this->for instanceof Article => $this->for->toc ?? [],
            $this->for instanceof ProvidesSeo => (array) ($this->for->xeradsSeo()?->extra('toc') ?? []),
            default => [],
        };
    }

    private function language(): ?string
    {
        return $this->lang ?? ($this->for instanceof Article ? $this->for->language : null);
    }
}
