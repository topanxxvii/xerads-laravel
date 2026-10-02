<?php

namespace XerAds\Laravel\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use XerAds\Laravel\Seo\HeadManager;

/**
 * `<x-xerads::head :for="$post" />`, in the layout's `<head>`: the title,
 * description, canonical, robots, Open Graph, `twitter:*` and verification tags
 * and the JSON-LD for the page, each printed once.
 *
 * `only` and `except` take groups, comma-separated (title, description,
 * canonical, robots, verification, og, twitter, jsonld), for a layout that
 * prints some of these itself: `<x-xerads::head except="title" />`.
 */
final class Head extends Component
{
    /** @var list<string> */
    public array $onlyGroups;

    /** @var list<string> */
    public array $exceptGroups;

    /*
     * `except` cannot be a promoted property: Laravel's Component has an
     * `$except` of its own.
     */
    public function __construct(
        public mixed $for = null,
        ?string $only = null,
        ?string $except = null,
    ) {
        $this->onlyGroups = self::groups($only);
        $this->exceptGroups = self::groups($except);
    }

    public function render(): Htmlable
    {
        $head = app(HeadManager::class);

        if ($this->for !== null) {
            $head->for($this->for);
        }

        return new HtmlString($head->toHtml($this->onlyGroups, $this->exceptGroups));
    }

    /** @return list<string> */
    private static function groups(?string $groups): array
    {
        $groups = array_map('trim', explode(',', strtolower((string) $groups)));

        return array_values(array_filter(array_map(fn (string $group) => $group === 'schema' ? 'jsonld' : $group, $groups), fn (string $group) => $group !== ''));
    }
}
