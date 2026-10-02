<?php

namespace XerAds\Laravel\View\Components;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;
use XerAds\Laravel\Widgets\Runtime;
use XerAds\Laravel\Widgets\WidgetAssets;
use XerAds\Laravel\Widgets\WidgetExpander;

/**
 * `<x-xerads::scripts />`, before `</body>` in the layout.
 *
 * Prints the widget loader once, and only when the page rendered a widget.
 * With `spa`, the loader is always printed, plus a few lines that ask it to
 * look for new widgets after Inertia or Livewire navigate: those swap the
 * page without a full load, so widgets on the new page would otherwise wait
 * forever. Every script carries the page's CSP nonce when it has one.
 */
final class Scripts extends Component
{
    public function __construct(public bool $spa = false) {}

    public function render(): Htmlable
    {
        if (! app(WidgetExpander::class)->enabled()) {
            return new HtmlString('');
        }

        $assets = app(WidgetAssets::class);
        $nonce = Vite::cspNonce();
        $html = '';

        if (($this->spa || $assets->loaderNeeded()) && ! $assets->loaderPrinted()) {
            $html .= app(Runtime::class)->loaderTag($nonce);
            $assets->markLoaderPrinted();
        }

        if ($this->spa) {
            $html .= '<script'.($nonce !== null && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '').'>'
                .trim((string) file_get_contents(__DIR__.'/../../../resources/js/xerads-spa.js'))
                .'</script>';
        }

        return new HtmlString($html);
    }
}
