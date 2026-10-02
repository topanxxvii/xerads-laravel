<?php

/**
 * The 404 monitor: paths counted after the response, never the visitor
 * (no IP address, no user agent), scanners and assets left out, rate-limited
 * and capped, and reported to XerAds in batches of what is new.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Seo\NotFound\NotFoundEntry;
use XerAds\Laravel\Seo\NotFound\NotFoundRecorder;
use XerAds\Laravel\Seo\Redirects\Redirect;

/** @return array<string, int> hits by path */
function notFoundHits(): array
{
    return NotFoundEntry::query()->orderBy('path')->pluck('hits', 'path')->map(fn ($hits) => (int) $hits)->all();
}

/** The reports the fake API received, item lists in order. */
function notFoundReports(): array
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/not-found'))
        ->map(fn (array $pair) => $pair[0]->data()['items'])
        ->values()
        ->all();
}

function fakeNotFoundEndpoint(): void
{
    fakeXerads(['not-found' => fn (Request $request) => Http::response(['accepted' => count($request->data()['items'])], 202)]);
}

/** Rows as the monitor writes them, without going through requests. */
function seedNotFound(int $count, int $hits = 1): void
{
    $now = Carbon::now();

    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        NotFoundEntry::query()->insert(array_map(fn (int $i) => [
            'path' => "/hilang-{$i}",
            'path_hash' => sha1("/hilang-{$i}"),
            'hits' => $hits,
            'reported_hits' => 0,
            'status' => NotFoundEntry::OPEN,
            'first_seen_at' => $now,
            'last_seen_at' => $now->copy()->subSeconds($i),
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }
}

it('counts GET and HEAD requests that answer 404, by path, without the query', function () {
    $this->get('/hilang?utm_source=x');
    $this->get('/hilang/');
    $this->call('HEAD', '/hilang');
    $this->get('/lain', ['Referer' => 'https://Mesin-Cari.example/hasil?q=rahasia']);

    expect(notFoundHits())->toBe(['/hilang' => 3, '/lain' => 1])
        ->and(NotFoundEntry::query()->where('path', '/lain')->value('last_referrer_host'))->toBe('mesin-cari.example')
        ->and(NotFoundEntry::query()->where('path', '/hilang')->first())->toMatchArray(['status' => 'open', 'reported_hits' => 0]);
});

it('counts an encoded path and its decoded form as one', function () {
    $this->get('/kaf%C3%A9');
    $this->get('/kafé');

    expect(NotFoundEntry::query()->count())->toBe(1)
        ->and(NotFoundEntry::query()->value('hits'))->toBe(2);
});

it('leaves out pages that exist, other methods and redirected paths', function () {
    Route::get('ada', fn () => 'ada');
    Redirect::query()->create(['origin' => 'local', 'match' => 'exact', 'source' => '/lama', 'source_hash' => Redirect::hashOf('/lama'), 'target' => '/ada', 'status' => 301]);
    Redirect::changed();

    $this->get('/ada')->assertOk();
    $this->post('/hilang')->assertNotFound();
    $this->get('/lama')->assertRedirect('/ada');

    expect(notFoundHits())->toBe([]);
});

it('leaves out what scanners probe for and missing assets', function (string $path) {
    $this->get($path)->assertNotFound();

    expect(notFoundHits())->toBe([]);
})->with([
    '/wp-login.php', '/wp-admin/install.php', '/.env', '/.env.backup', '/.git/config', '/xmlrpc.php',
    '/phpmyadmin/index.php', '/backup.sql', '/situs.zip', '/WP-LOGIN.PHP', '/old/info.php',
    '/build/app.js', '/images/logo.png', '/fonts/inter.woff2', '/favicon.ico', '/apple-touch-icon.png',
]);

it('leaves out the paths the site lists in monitor_404.ignore', function () {
    config(['xerads.monitor_404.ignore' => ['/internal/*', 'healthz']]);

    $this->get('/internal/probe');
    $this->get('/healthz');
    $this->get('/hilang');

    expect(notFoundHits())->toBe(['/hilang' => 1]);
});

it('counts at most 30 404s a minute from one visitor', function () {
    foreach (range(1, 35) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get("/coba-{$i}");
    }

    expect(NotFoundEntry::query()->count())->toBe(30);

    // Another visitor still counts.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/coba-35');
    expect(NotFoundEntry::query()->count())->toBe(31);

    // A minute later, the first visitor counts again.
    Carbon::setTestNow(now()->addSeconds(61));
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/coba-36');
    expect(NotFoundEntry::query()->count())->toBe(32);
});

it('honours a different limit per visitor', function () {
    config(['xerads.monitor_404.per_ip_per_minute' => 2]);

    foreach (range(1, 4) as $i) {
        $this->get("/coba-{$i}");
    }

    expect(notFoundHits())->toBe(['/coba-1' => 1, '/coba-2' => 1]);
});

it('stops adding paths at max_rows, and keeps counting the ones listed', function () {
    config(['xerads.monitor_404.max_rows' => 3]);
    seedNotFound(3);

    $this->get('/baru-sekali');
    $this->get('/hilang-1');

    expect(NotFoundEntry::query()->count())->toBe(3)
        ->and(notFoundHits()['/hilang-1'])->toBe(2);
});

it('never stores the visitor\'s address or user agent', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/hilang', ['User-Agent' => 'Peramban-Uji/9.9', 'Referer' => 'https://203.0.113.50/halaman']);

    $stored = json_encode(DB::table('xerads_not_found')->get());
    $cached = json_encode((fn () => $this->storage)->call(Cache::store('array')->getStore()));

    expect(NotFoundEntry::query()->count())->toBe(1)
        ->and($stored)->not->toContain('203.0.113.9')
        ->and($stored)->not->toContain('Peramban-Uji')
        ->and($cached)->not->toContain('203.0.113.9')
        ->and(DB::getSchemaBuilder()->getColumnListing('xerads_not_found'))->not->toContain('ip')
        ->and(DB::getSchemaBuilder()->getColumnListing('xerads_not_found'))->not->toContain('user_agent');
});

it('stops counting when the settings or the config turn the monitor off', function () {
    holdSettings(['monitor_404' => ['enabled' => false, 'report' => true]]);
    $this->get('/hilang');

    holdSettings();
    config(['xerads.monitor_404.enabled' => false]);
    $this->get('/hilang');

    expect(notFoundHits())->toBe([]);
});

it('can be taken out of the global middleware', function (array $config) {
    $this->bootTurnkey($config);

    $this->get('/hilang')->assertNotFound();

    expect(notFoundHits())->toBe([]);
})->with([
    'its own switch' => [['xerads.middleware.not_found' => false]],
    'all of them' => [['xerads.middleware.global' => false]],
]);

it('reports what is new in batches of 500, once', function () {
    storeTestKey();
    fakeNotFoundEndpoint();
    seedNotFound(501, hits: 3);

    $this->artisan('xerads:sync', ['--report-404' => true])
        ->expectsOutput('Reported 501 path(s) that answered 404.')
        ->assertSuccessful();

    $reports = notFoundReports();

    expect($reports)->toHaveCount(2)
        ->and($reports[0])->toHaveCount(500)
        ->and($reports[1])->toHaveCount(1)
        ->and($reports[0][0])->toBe([
            'path' => '/hilang-1',
            'hits' => 3,
            'first_seen_at' => NotFoundEntry::query()->where('path', '/hilang-1')->first()->first_seen_at->toIso8601String(),
            'last_seen_at' => NotFoundEntry::query()->where('path', '/hilang-1')->first()->last_seen_at->toIso8601String(),
            'referrer_host' => null,
        ]);

    // Nothing new: nothing sent.
    $this->artisan('xerads:sync', ['--report-404' => true])->expectsOutput('No new 404s to report.')->assertSuccessful();
    expect(notFoundReports())->toHaveCount(2);

    // Only the hits counted since.
    $this->get('/hilang-7?utm=x');
    $this->get('/hilang-7');
    $this->artisan('xerads:sync', ['--report-404' => true])->assertSuccessful();

    expect(notFoundReports()[2])->toHaveCount(1)
        ->and(notFoundReports()[2][0])->toMatchArray(['path' => '/hilang-7', 'hits' => 2]);
});

it('keeps unreported hits when XerAds refuses a report', function () {
    storeTestKey();
    fakeXerads(['not-found' => Http::response(['error' => 'RATE_LIMITED', 'message' => 'Too many reports.'], 429)]);
    seedNotFound(2);

    $this->artisan('xerads:sync', ['--report-404' => true])->assertFailed();

    expect(NotFoundEntry::query()->sum('reported_hits'))->toBe(0);
});

it('reports nothing while the settings say not to', function () {
    holdSettings(['monitor_404' => ['enabled' => true, 'report' => false]]);
    fakeNotFoundEndpoint();
    seedNotFound(2);

    $this->artisan('xerads:sync', ['--report-404' => true])->assertSuccessful();

    expect(notFoundReports())->toBe([]);
});

it('reports with the routine sync and counts open paths in the heartbeat', function () {
    storeTestKey();
    fakeNotFoundEndpoint();
    seedNotFound(3);
    NotFoundEntry::query()->where('path', '/hilang-1')->update(['status' => NotFoundEntry::RESOLVED]);

    $this->artisan('xerads:sync')->assertSuccessful();

    $heartbeat = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/heartbeat'))->first()[0];

    expect(notFoundReports())->toHaveCount(1)
        ->and($heartbeat->data()['counts']['not_found_open'])->toBe(2);
});

it('treats paths no one would keep as not worth counting', function () {
    expect(NotFoundRecorder::path('/'.str_repeat('a', 600)))->toBeNull()
        ->and(NotFoundRecorder::path('/a<b'))->toBeNull()
        ->and(NotFoundRecorder::path('/hilang/?x=1'))->toBe('/hilang')
        ->and(NotFoundRecorder::path('/hilang#bagian'))->toBe('/hilang')
        ->and(NotFoundRecorder::path('/caf%FF'))->toBeNull();
});

it('counts a path with a colon in it as itself', function () {
    $this->get('/blog/promo:2025')->assertNotFound();
    $this->get('/blog/lain:2026')->assertNotFound();

    // Read as host and port, both used to be counted as `/`.
    expect(notFoundHits())->toBe(['/blog/lain:2026' => 1, '/blog/promo:2025' => 1]);
});

it('leaves out a path that is not UTF-8, so the report still goes out', function () {
    storeTestKey();
    fakeNotFoundEndpoint();

    // A 404 before Laravel 13, which answers 400 for it itself.
    expect($this->get("/caf\xff")->getStatusCode())->toBeIn([400, 404])
        ->and(app(NotFoundRecorder::class)->record(Illuminate\Http\Request::create("/caf\xff")))->toBeFalse();
    $this->get('/hilang')->assertNotFound();

    $this->artisan('xerads:sync', ['--report-404' => true])->assertSuccessful();

    expect(notFoundHits())->toBe(['/hilang' => 1])
        ->and(notFoundReports()[0][0]['path'])->toBe('/hilang');
});
