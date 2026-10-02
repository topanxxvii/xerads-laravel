<?php

namespace XerAds\CmsBridge;

use Illuminate\Support\ServiceProvider;
use XerAds\Laravel\XeradsServiceProvider;

/**
 * The original package's provider, kept so an app that registered it by hand
 * (in `bootstrap/providers.php` or `config/app.php`) still boots.
 *
 * It does nothing itself: the package is wired by `XeradsServiceProvider`,
 * which package discovery registers on its own. Registering both is harmless;
 * a provider is only ever registered once.
 *
 * @deprecated Remove it from your providers list; `XerAds\Laravel\XeradsServiceProvider` is discovered automatically.
 */
class CmsBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A no-op when discovery already registered it: the container returns
        // the registered instance instead of registering a second one.
        $this->app->register(XeradsServiceProvider::class);
    }
}
