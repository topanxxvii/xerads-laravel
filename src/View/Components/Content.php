<?php

namespace XerAds\Laravel\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;
use XerAds\Laravel\Widgets\WidgetExpander;

/**
 * `<x-xerads::content :html="$post->body" />`: an article body with its
 * `[xerads_widget]` placeholders expanded as the page renders.
 *
 * For sites storing placeholders (`content.mapped.content_format =
 * shortcode`). Pass the model as `:for` and the widget heights XerAds sent
 * with the article are used, so the page needs no request to the widget
 * runtime to lay itself out.
 *
 * The body is printed as it is otherwise: it was sanitised when it arrived.
 */
final class Content extends Component
{
    public function __construct(
        public ?string $html = null,
        public mixed $for = null,
        public ?string $lang = null,
    ) {}

    public function render(): Htmlable
    {
        $meta = $this->for instanceof ProvidesSeo ? $this->for->xeradsSeo() : null;
        $language = $this->lang ?? (is_string($meta?->extra('language')) ? $meta->extra('language') : null);

        return new HtmlString(app(WidgetExpander::class)->expand(
            (string) $this->html,
            $language,
            $meta?->widgetHeights() ?? [],
        ));
    }
}
