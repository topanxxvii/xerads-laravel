<?php

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use XerAds\CmsBridge\CmsBridgeServiceProvider;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Receivers\EloquentArticleReceiver;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\XeradsServiceProvider;

it('merges a published config group by group, keeping keys it does not set', function () {
    // A config/xerads.php from an older release: partial groups, in place
    // before the package registers.
    $this->rebootAsInstall([], ['xerads' => [
        'legacy' => ['secret' => 'from-an-old-file', 'middleware' => ['web']],
        'content' => ['mapped' => ['fields' => ['title' => 'judul']]],
    ]]);

    expect(config('xerads.legacy.secret'))->toBe('from-an-old-file')
        ->and(config('xerads.legacy.route'))->toBe('/api/xerads/articles')
        ->and(config('xerads.legacy.match_existing_by_slug'))->toBeTrue()
        // Lists are replaced, never merged.
        ->and(config('xerads.legacy.middleware'))->toBe(['web'])
        ->and(config('xerads.content.mapped.fields.title'))->toBe('judul')
        ->and(config('xerads.content.mapped.fields.content'))->toBe('content')
        ->and(config('xerads.content.mapped.status_map'))->toBe(['draft' => 'draft', 'publish' => 'published'])
        ->and(config('xerads.webhook.timestamp_tolerance'))->toBe(300)
        ->and(config('xerads.database.table_prefix'))->toBe('xerads_');
});

it('answers version, contract and features through the facade', function () {
    expect(Xerads::version())->toBe(Version::VERSION)
        ->and(Xerads::contract())->toBe(2)
        ->and(Xerads::features())->toBe(['articles']);

    config(['xerads.content.mode' => 'off']);

    expect(Xerads::features())->toBe([]);
});

it('binds the legacy receiver so an app can replace it', function () {
    expect(app(ArticleReceiver::class))->toBeInstanceOf(EloquentArticleReceiver::class);
});

it('resolves the credentials once per request scope', function () {
    $first = app(CredentialsResolver::class);

    expect(app(CredentialsResolver::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(CredentialsResolver::class))->not->toBe($first);
});

it('refuses a credentials resolver that is not a CredentialsResolver', function () {
    config(['xerads.credentials.resolver' => stdClass::class]);
    app()->forgetScopedInstances();

    app(CredentialsResolver::class);
})->throws(InvalidArgumentException::class);

it('publishes the new config, the legacy config and the migrations', function () {
    $paths = fn (string $tag) => array_map(
        fn (string $path) => basename($path),
        array_keys(ServiceProvider::pathsToPublish(XeradsServiceProvider::class, $tag)),
    );

    expect($paths('xerads-config'))->toBe(['xerads.php'])
        ->and($paths('xerads-cms-config'))->toBe(['xerads-cms.php'])
        ->and($paths('xerads-migrations'))->toBe(['core']);
});

it('keeps the deprecated bridge provider registrable', function () {
    /** @var Application $app */
    $app = app();

    $app->register(CmsBridgeServiceProvider::class);

    expect($app->getProviders(XeradsServiceProvider::class))->toHaveCount(1)
        ->and($app->getProviders(CmsBridgeServiceProvider::class))->toHaveCount(1);
});
