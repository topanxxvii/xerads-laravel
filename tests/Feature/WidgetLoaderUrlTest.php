<?php

/**
 * One answer to "which widget loader", for every place that prints it: the
 * site's config when it names one, else the dashboard settings, else the
 * default address.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Seo\Inertia\ShareHead;
use XerAds\Laravel\Widgets\Runtime;

const STAGING_LOADER = 'https://staging-widgets.xerads.id/v1/loader.js';

function loaderUrl(): string
{
    app()->forgetScopedInstances();

    return app(Runtime::class)->loaderUrl();
}

it('uses the default address with nothing configured', function () {
    expect(loaderUrl())->toBe('https://widgets.xerads.id/v1/loader.js');
});

it('uses the loader the settings name, everywhere it is printed', function () {
    holdSettings(['widgets' => ['loader_url' => STAGING_LOADER]]);

    expect(loaderUrl())->toBe(STAGING_LOADER)
        ->and(app(Runtime::class)->documentUrl('w_k3v9q2m8x1c4b7na'))->toBe('https://staging-widgets.xerads.id/w/w_k3v9q2m8x1c4b7na.json')
        ->and(ShareHead::props()['widgets']['loader_url'])->toBe(STAGING_LOADER);

    // The scripts component, and the middleware that adds the loader.
    Route::middleware('web')->get('/with-widget', fn () => Blade::render('<html><body>@xeradsWidget("w_k3v9q2m8x1c4b7na")<x-xerads::scripts /></body></html>'));
    Route::middleware('web')->get('/pasted', fn () => '<html><body><div data-xerads-widget="w_k3v9q2m8x1c4b7na"></div></body></html>');

    expect((string) $this->get('/with-widget')->getContent())->toContain('<script src="'.STAGING_LOADER.'" async></script>')
        ->and((string) $this->get('/pasted')->getContent())->toContain('src="'.STAGING_LOADER.'"');
});

it('lets the site\'s config win over the settings', function () {
    holdSettings(['widgets' => ['loader_url' => STAGING_LOADER]]);

    config(['xerads.widgets.runtime_url' => 'https://widgets.toko.test']);
    expect(loaderUrl())->toBe('https://widgets.toko.test/v1/loader.js');

    config(['xerads.widgets.loader_url' => 'https://cdn.toko.test/loader.js']);
    expect(loaderUrl())->toBe('https://cdn.toko.test/loader.js');
});

it('ignores a loader in the settings that is not an https address', function () {
    holdSettings(['widgets' => ['loader_url' => 'javascript:alert(1)']]);

    expect(loaderUrl())->toBe('https://widgets.xerads.id/v1/loader.js');
});
