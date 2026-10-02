<?php

namespace XerAds\Laravel\Widgets;

/**
 * What the current page needs from the widget runtime.
 *
 * Request-scoped: a widget rendered anywhere on the page records that the
 * loader is needed, and the scripts component (or the response middleware)
 * prints it once. Under a long-running worker each request starts clean.
 */
final class WidgetAssets
{
    private bool $loaderNeeded = false;

    private bool $loaderPrinted = false;

    public function requireLoader(): void
    {
        $this->loaderNeeded = true;
    }

    public function loaderNeeded(): bool
    {
        return $this->loaderNeeded;
    }

    public function markLoaderPrinted(): void
    {
        $this->loaderPrinted = true;
    }

    public function loaderPrinted(): bool
    {
        return $this->loaderPrinted;
    }
}
