<?php

namespace XerAds\Laravel\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use XerAds\Laravel\Seo\HeadManager;

/**
 * `<x-xerads::breadcrumbs />`: the page's trail, home first, from the same
 * list the structured data's `BreadcrumbList` is built from. Nothing when
 * the trail is empty or the settings turn breadcrumbs off.
 */
final class Breadcrumbs extends Component
{
    /**
     * @param  mixed  $for  the page's model, when the layout's head (which
     *                      renders after the page body) is where it is
     *                      given: `<x-xerads::breadcrumbs :for="$post" />`
     */
    public function __construct(public mixed $for = null) {}

    public function render(): Htmlable
    {
        $head = app(HeadManager::class);

        if ($this->for !== null) {
            $head->for($this->for);
        }

        $trail = $head->resolve()->breadcrumbs;

        if ($trail === []) {
            return new HtmlString('');
        }

        $items = [];
        $last = count($trail) - 1;

        foreach ($trail as $position => $crumb) {
            $items[] = $position === $last
                ? '<li aria-current="page">'.e($crumb['name']).'</li>'
                : '<li><a href="'.e($crumb['url']).'">'.e($crumb['name']).'</a></li>';
        }

        return new HtmlString('<nav class="xerads-breadcrumbs" aria-label="Breadcrumb"><ol>'.implode('', $items).'</ol></nav>');
    }
}
