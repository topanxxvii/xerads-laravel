<?php

/**
 * Who wins, lowest first: the package's defaults, the settings from XerAds,
 * the site's `xerads.seo.overrides`, `pages[]` for the exact path, the
 * route's `xerads.robots`, the page's model, calls made in the request.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Sync\RemoteState;

function titleOf(string $head): ?string
{
    preg_match('#<title>(.*?)</title>#', $head, $match);

    return isset($match[1]) ? html_entity_decode($match[1]) : null;
}

beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');
});

it('starts from the package\'s defaults, filled from the application', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->get('site.name'))->toBe('Laravel')
        ->and($settings->get('site.url'))->toBe('http://localhost')
        ->and($settings->get('titles.article'))->toBe('{title} {sep} {site}')
        ->and(titleOf(headOf(headPage($this, '/'))))->toBe('Laravel');
});

it('runs on local config alone while the site is not paired', function () {
    config(['xerads.seo.overrides' => ['site' => ['name' => 'Toko Lokal']]]);

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Toko Lokal')
        ->and(titleOf(headOf(headPage($this, '/'))))->toBe('Toko Lokal');
});

it('lets the settings from XerAds win over the defaults, and the site\'s overrides win over them', function () {
    holdSettings(['site' => ['name' => 'Toko', 'tagline' => 'Rumah impian'], 'titles' => ['separator' => '|']]);

    expect(titleOf(headOf(headPage($this, '/'))))->toBe('Toko | Rumah impian');

    config(['xerads.seo.overrides' => ['site' => ['tagline' => 'Dari kode']]]);

    expect(titleOf(headOf(headPage($this, '/'))))->toBe('Toko | Dari kode');
});

it('replaces lists whole rather than merging them item by item', function () {
    holdSettings(['organization' => ['same_as' => ['https://a.test', 'https://b.test']]]);
    config(['xerads.seo.overrides' => ['organization' => ['same_as' => ['https://c.test']]]]);

    expect(app(SettingsRepository::class)->get('organization.same_as'))->toBe(['https://c.test']);
});

it('ignores a copy held for another site', function () {
    holdSettings(['site' => ['name' => 'Toko']]);

    $held = app(RemoteState::class)->get('settings');
    app(RemoteState::class)->put('settings', ['site_id' => 'site_01jb2n0a1b2c3d4e5f6g7h8j9k'] + $held);
    app(SettingsRepository::class)->forget();
    app()->forgetScopedInstances();

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Laravel');
});

it('ignores the settings from XerAds with seo.remote off', function () {
    holdSettings(['site' => ['name' => 'Toko']]);
    config(['xerads.seo.remote' => false]);

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Laravel');
});

it('reads the database once, then the cache, and a new pull replaces both', function () {
    holdSettings(['site' => ['name' => 'Toko']]);

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Toko');

    // Changed under it: the cached copy still answers.
    $held = app(RemoteState::class)->get('settings');
    $held['data']['site']['name'] = 'Toko Baru';
    app(RemoteState::class)->put('settings', $held);
    app()->forgetScopedInstances();

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Toko');

    // A pull of new settings forgets it.
    fakeXerads(['settings' => Http::response(['site' => ['name' => 'Toko Ditarik']] + settingsDocument(4), 200, ['ETag' => '"s4"'])]);
    Artisan::call('xerads:sync', ['--settings' => true]);
    app()->forgetScopedInstances();

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Toko Ditarik');
});

it('lets pages[] win over the templates for its path', function () {
    holdSettings(['site' => ['name' => 'Toko'], 'pages' => [['path' => '/about', 'title' => 'Tentang kami', 'description' => 'Siapa kami.']]]);

    $head = headOf(headPage($this, '/about'));

    expect(titleOf($head))->toBe('Tentang kami')
        ->and(metaContent($head, 'description'))->toBe(['Siapa kami.']);
});

it('lets a route\'s default win over pages[] for robots', function () {
    holdSettings(['pages' => [['path' => '/members', 'robots' => ['index' => true, 'follow' => true]]]]);

    Route::middleware('web')->get('/members', fn () => Blade::render('<html><head>@xeradsHead</head></html>'))->xeradsRobots('noindex');

    expect(metaContent(headOf($this->get('/members')), 'robots')[0])->toBe('noindex, follow');
});

it('lets the page\'s model win over pages[], except that it may only restrict robots', function () {
    holdSettings(['pages' => [['path' => '/post', 'title' => 'Dari settings', 'description' => 'Dari settings.', 'robots' => ['index' => false, 'follow' => true]]]]);

    $head = headOf(headPage($this, '/post', fn () => Xerads::head()->for([
        'title' => 'Dari model',
        'description' => 'Dari model.',
        'robots' => ['index' => true],
    ])));

    expect(titleOf($head))->toBe('Dari model - Toko Rumah')
        ->and(metaContent($head, 'description'))->toBe(['Dari model.'])
        // A model's `index: true` does not lift the page's noindex.
        ->and(metaContent($head, 'robots')[0])->toBe('noindex, follow');

    $restricted = headOf(headPage($this, '/other', fn () => Xerads::head()->for(['title' => 'Model', 'robots' => ['follow' => false]])));

    expect(metaContent($restricted, 'robots')[0])->toStartWith('index, nofollow');
});

it('lets calls made in the request win over everything below them', function () {
    holdSettings(['pages' => [['path' => '/post', 'title' => 'Dari settings']]]);

    $head = headOf(headPage($this, '/post', fn () => Xerads::head()
        ->for(['title' => 'Dari model', 'description' => 'Dari model.', 'canonical' => 'https://kanonik.test/model'])
        ->title('Dari kode')
        ->description('Dari kode.')
        ->canonical('/kanonik')
        ->robots('noindex')));

    expect(titleOf($head))->toBe('Dari kode - Toko Rumah')
        ->and(metaContent($head, 'description'))->toBe(['Dari kode.'])
        ->and($head)->toContain('<link rel="canonical" href="http://localhost/kanonik">')
        ->and(metaContent($head, 'robots')[0])->toBe('noindex, follow');

    $full = headOf(headPage($this, '/post', fn () => Xerads::head()->fullTitle('Persis seperti ini')));

    expect(titleOf($full))->toBe('Persis seperti ini');
});

it('never serves a copy a request read just before a pull, once the pull is done', function () {
    holdSettings(['site' => ['name' => 'Lama']]);

    // A request reads the old document and is slow to cache it.
    $staleKey = app(SettingsRepository::class)->cacheKey();
    $stale = ['site_id' => TEST_SITE_ID, 'data' => app(RemoteState::class)->document('settings')['data']];

    // Meanwhile a pull stores the new document.
    $held = app(RemoteState::class)->get('settings');
    $held['data']['site']['name'] = 'Baru';
    app(RemoteState::class)->put('settings', $held);
    app(SettingsRepository::class)->forget();

    // The slow request writes what it read.
    Cache::put($staleKey, $stale, 600);
    app()->forgetScopedInstances();

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Baru');
});

it('forgets the cached settings when the site is paired', function () {
    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Laravel');

    // Unpaired, that answer is cached; pairing must not keep it. (The first
    // sync fails here, so only the pairing itself can have forgotten it.)
    fakeXerads(['settings' => fn () => Http::response('', 503)]);
    Artisan::call('xerads:pair', ['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']);
    app(RemoteState::class)->put('settings', ['site_id' => TEST_SITE_ID, 'version' => 1, 'data' => SettingsRepository::merge(siteSettingsFixture(), ['site' => ['name' => 'Toko']])]);
    app()->forgetScopedInstances();

    expect(app(SettingsRepository::class)->get('site.name'))->toBe('Toko');
});
