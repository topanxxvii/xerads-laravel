<?php

use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Support\Version;

it('says what it is, and nothing more', function () {
    $response = $this->getJson('/xerads/v1/status')->assertOk();

    $response->assertExactJson([
        'plugin' => 'xerads',
        'platform' => 'laravel',
        'version' => Version::VERSION,
        'contract' => 2,
        'features' => ['articles', 'widgets'],
    ]);

    expect((string) $response->getContent())->not->toContain(PHP_VERSION)
        ->and((string) $response->getContent())->not->toContain(app()->version())
        ->and($response->headers->getCookies())->toBe([]);
});

it('can be switched off', function () {
    $this->rebootWith(['xerads.routes.status' => false]);

    $this->getJson('/xerads/v1/status')->assertNotFound();
});

it('moves with the configured prefix', function () {
    $this->rebootWith(['xerads.routes.prefix' => 'hooks/xerads']);

    $this->getJson('/hooks/xerads/status')->assertOk();

    expect(route('xerads.webhook', absolute: false))->toBe('/hooks/xerads/webhook');
});

it('registers nothing when the sync module is off', function () {
    $this->rebootAsInstall([], ['xerads.modules.sync' => false]);

    expect(Route::has('xerads.webhook'))->toBeFalse()
        ->and(Route::has('xerads.status'))->toBeFalse();
});
