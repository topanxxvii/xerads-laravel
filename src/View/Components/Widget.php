<?php

namespace XerAds\Laravel\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use XerAds\Laravel\Widgets\ShortcodeParser;
use XerAds\Laravel\Widgets\WidgetExpander;

/**
 * `<x-xerads::widget id="w_…" lang="id" />`: one widget, anywhere in a view.
 *
 * Renders the §3.1 container and records that the page needs the loader.
 * An invalid id renders nothing for visitors; with `editor` set, a short
 * notice instead, so the person placing it sees why it is missing.
 */
final class Widget extends Component
{
    public function __construct(
        public string $id,
        public ?string $lang = null,
        public ?int $height = null,
        public bool $editor = false,
    ) {}

    public function render(): Htmlable
    {
        $expander = app(WidgetExpander::class);

        if (! $expander->enabled()) {
            return new HtmlString('');
        }

        if (! ShortcodeParser::validId($this->id)) {
            return new HtmlString($this->editor
                ? '<p class="xerads-widget-notice">'.e(__('xerads::widgets.invalid_id', ['id' => $this->id])).'</p>'
                : '');
        }

        $heights = $this->height !== null && $this->height > 0 ? [$this->id => $this->height] : [];

        return new HtmlString($expander->render($this->id, $this->lang, $heights));
    }
}
