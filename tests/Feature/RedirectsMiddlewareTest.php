<?php

/**
 * The redirects: applied only where the site answers 404, exact rules before
 * the longest prefix, the query carried over, 410 and 451 empty; and the
 * rules from XerAds applied without loops and without touching the
 * package's own.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Seo\NotFound\NotFoundEntry;
use XerAds\Laravel\Seo\Redirects\Redirect;
use XerAds\Laravel\Seo\Redirects\RedirectSync;

/** A rule as the site, XerAds or the package stores one. */
function redirectRule(string $source, ?string $target, array $attributes = []): Redirect
{
    $insensitive = $attributes['case_insensitive'] ?? false;

    $redirect = Redirect::query()->create(array_merge([
        'origin' => Redirect::ORIGIN_XERADS,
        'match' => 'exact',
        'source' => $source,
        'source_hash' => Redirect::hashOf($insensitive ? mb_strtolower($source) : $source, $insensitive),
        'case_insensitive' => false,
        'target' => $target,
        'status' => 301,
        'preserve_query' => true,
        'active' => true,
    ], $attributes));

    return $redirect;
}

/** @param  list<array<string, mixed>>  $items */
function applyRedirects(array $items): array
{
    app()->forgetScopedInstances();

    return app(RedirectSync::class)->apply($items);
}

function xeradsRule(string $source, ?string $target, array $overrides = []): array
{
    return array_merge(['id' => crc32($source), 'match' => 'exact', 'source' => $source, 'target' => $target, 'status' => 301, 'preserve_query' => true, 'case_insensitive' => false], $overrides);
}

/** The rules in the table, by origin then source. */
function redirectRows(): array
{
    return Redirect::query()->orderBy('origin')->orderBy('source')->get()
        ->map(fn (Redirect $redirect) => "{$redirect->origin} {$redirect->match} {$redirect->source} -> ".($redirect->target ?? '-')." {$redirect->status}")
        ->all();
}

it('redirects only where the site answers 404', function () {
    Route::get('lama', fn () => 'halaman yang masih ada');
    redirectRule('/lama', '/baru');
    redirectRule('/hilang', '/baru');

    $this->get('/lama')->assertOk()->assertContent('halaman yang masih ada');
    $this->get('/hilang')->assertStatus(301)->assertHeader('Location', '/baru');
});

it('takes the exact rule before any prefix, then the longest prefix', function () {
    redirectRule('/promo', '/semua-promo', ['match' => 'prefix']);
    redirectRule('/promo/lebaran', '/lebaran');
    redirectRule('/promo/2025', '/arsip-promo', ['match' => 'prefix']);

    $this->get('/promo/lebaran')->assertRedirect('/lebaran');
    $this->get('/promo/natal/hari-1')->assertRedirect('/semua-promo');
    $this->get('/promo/2025/natal')->assertRedirect('/arsip-promo');
    $this->get('/promo')->assertRedirect('/semua-promo');
    // A prefix covers whole segments only.
    $this->get('/promotion')->assertNotFound();
});

it('carries the query over unless the rule says not to', function () {
    redirectRule('/lama', '/baru');
    redirectRule('/kampanye', '/promo?sumber=lama');
    redirectRule('/tanpa', '/baru', ['preserve_query' => false]);

    $this->get('/lama?utm_source=x&b=2')->assertHeader('Location', '/baru?utm_source=x&b=2');
    $this->get('/kampanye?utm_source=x')->assertHeader('Location', '/promo?sumber=lama&utm_source=x');
    $this->get('/tanpa?utm_source=x')->assertHeader('Location', '/baru');
});

it('sends visitors on to a path on whatever host they asked for', function () {
    redirectRule('/lama', '/baru');

    // Relative: the Host header is never written into the address.
    $this->get('http://evil.example/lama')->assertHeader('Location', '/baru');
});

it('answers 410 and 451 with the site\'s own error page, and HEAD with no body', function (int $status) {
    redirectRule('/ditarik', null, ['status' => $status]);
    // The handler looks for errors/{status} and errors/4xx under view.paths.
    $views = scratchDirectory('error-views');
    @mkdir($views.'/errors', 0777, true);
    file_put_contents($views.'/errors/4xx.blade.php', 'Halaman ini sudah tidak ada ({{ $exception->getStatusCode() }}).');
    config(['view.paths' => [$views]]);

    $this->get('/ditarik')->assertStatus($status)->assertSee("Halaman ini sudah tidak ada ({$status}).");
    $this->call('HEAD', '/ditarik')->assertStatus($status)->assertContent('');
})->with([410, 451]);

it('answers 410 with the framework\'s page where the site has none', function () {
    redirectRule('/ditarik', null, ['status' => 410]);

    expect((string) $this->get('/ditarik')->assertStatus(410)->getContent())->not->toBe('');
});

it('keeps each redirect status, and sends visitors to an https address elsewhere', function (int $status) {
    redirectRule('/pindah', 'https://toko-baru.example/halaman', ['status' => $status]);

    $this->get('/pindah')->assertStatus($status)->assertHeader('Location', 'https://toko-baru.example/halaman');
})->with([301, 302, 307, 308]);

it('matches regardless of case only where the rule says so', function () {
    redirectRule('/Katalog', '/produk', ['case_insensitive' => true]);
    redirectRule('/Harga', '/daftar-harga');
    redirectRule('/Merek', '/semua-merek', ['match' => 'prefix', 'case_insensitive' => true]);

    $this->get('/KATALOG')->assertRedirect('/produk');
    $this->get('/katalog/')->assertRedirect('/produk');
    $this->get('/Harga')->assertRedirect('/daftar-harga');
    $this->get('/harga')->assertNotFound();
    $this->get('/merek/ABC')->assertRedirect('/semua-merek');
});

it('lets the site\'s own rule win, then XerAds\', then the package\'s', function () {
    redirectRule('/lama', '/dari-paket', ['origin' => Redirect::ORIGIN_AUTO]);
    redirectRule('/lama', '/dari-xerads');

    $this->get('/lama')->assertRedirect('/dari-xerads');

    redirectRule('/lama', '/dari-situs', ['origin' => Redirect::ORIGIN_LOCAL]);

    $this->get('/lama')->assertRedirect('/dari-situs');
});

it('leaves other methods, inactive rules and a switched-off feature alone', function () {
    redirectRule('/lama', '/baru');
    redirectRule('/nonaktif', '/baru', ['active' => false]);

    $this->post('/lama')->assertNotFound();
    $this->get('/nonaktif')->assertNotFound();

    config(['xerads.redirects.enabled' => false]);

    $this->get('/lama')->assertNotFound();
});

it('can be taken out of the global middleware', function (array $config) {
    $this->bootTurnkey($config);
    redirectRule('/lama', '/baru');

    $this->get('/lama')->assertNotFound();
})->with([
    'its own switch' => [['xerads.middleware.redirects' => false]],
    'all of them' => [['xerads.middleware.global' => false]],
]);

it('writes nothing to the database while redirecting', function () {
    redirectRule('/lama', '/baru');
    $this->get('/lama')->assertRedirect('/baru');

    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        $writes += preg_match('/^\s*(insert|update|delete)/i', $query->sql);
    });

    $this->get('/lama')->assertRedirect('/baru');

    expect($writes)->toBe(0);
});

it('sees a rule saved or deleted through the model at once', function () {
    // The rules are cached by the first 404.
    $this->get('/lama')->assertNotFound();

    $rule = Redirect::query()->create(['origin' => 'local', 'match' => 'exact', 'source' => '/lama', 'source_hash' => Redirect::hashOf('/lama'), 'target' => '/baru', 'status' => 301]);
    $this->get('/lama')->assertRedirect('/baru');

    $rule->update(['target' => '/lebih-baru']);
    $this->get('/lama')->assertRedirect('/lebih-baru');

    $rule->delete();
    $this->get('/lama')->assertNotFound();
});

it('puts the query before the target\'s fragment', function () {
    redirectRule('/lama', '/baru#bagian');
    redirectRule('/kampanye', '/promo?sumber=lama#harga');

    $this->get('/lama?utm_source=x')->assertHeader('Location', '/baru?utm_source=x#bagian');
    $this->get('/kampanye?utm_source=x')->assertHeader('Location', '/promo?sumber=lama&utm_source=x#harga');
    $this->get('/lama')->assertHeader('Location', '/baru#bagian');
});

it('matches a path with a colon in it', function () {
    redirectRule('/blog/promo:2025', '/promo');

    expect(Redirect::normalize('/blog/promo:2025?x=1'))->toBe('/blog/promo:2025');

    $this->get('/blog/promo:2025')->assertRedirect('/promo');
    // Read as host and port, every such path used to be `/`.
    $this->get('/blog/lain:2026')->assertNotFound();
});

it('matches the decoded path', function () {
    redirectRule('/promo lebaran', '/lebaran');

    $this->get('/promo%20lebaran')->assertRedirect('/lebaran');
});

it('replaces XerAds\' rules and keeps the package\'s and the site\'s own', function () {
    redirectRule('/artikel-lama', '/artikel-baru', ['origin' => Redirect::ORIGIN_AUTO]);
    redirectRule('/milik-situs', '/tujuan', ['origin' => Redirect::ORIGIN_LOCAL]);

    applyRedirects([xeradsRule('/satu', '/tujuan-satu'), xeradsRule('/dua', null, ['status' => 410])]);
    $result = applyRedirects([xeradsRule('/tiga', '/tujuan-tiga', ['match' => 'prefix', 'status' => 308])]);

    expect($result)->toBe(['applied' => 1, 'rejected' => []])
        ->and(redirectRows())->toBe([
            'auto exact /artikel-lama -> /artikel-baru 301',
            'local exact /milik-situs -> /tujuan 301',
            'xerads prefix /tiga -> /tujuan-tiga 308',
        ]);

    // The matcher sees the new rules straight away.
    $this->get('/satu')->assertNotFound();
    $this->get('/tiga/x')->assertStatus(308)->assertHeader('Location', '/tujuan-tiga');
    $this->get('/artikel-lama')->assertRedirect('/artikel-baru');
});

it('rejects rules that loop, and applies the rest', function () {
    redirectRule('/x', '/y', ['origin' => Redirect::ORIGIN_AUTO]);
    $warnings = $this->captureWarnings();

    $result = applyRedirects([
        xeradsRule('/sendiri', '/sendiri/'),
        xeradsRule('/awalan', '/awalan/anak', ['match' => 'prefix']),
        xeradsRule('/BESAR', '/besar', ['case_insensitive' => true]),
        xeradsRule('/a', '/b'),
        xeradsRule('/b', '/a'),
        xeradsRule('/y', '/x'),
        xeradsRule('/c', '/d/e'),
        xeradsRule('/d', '/c', ['match' => 'prefix']),
        xeradsRule('/baik', '/tujuan'),
    ]);

    expect($result['applied'])->toBe(3)
        ->and($result['rejected'])->toBe([
            '/sendiri: it would send visitors back to itself.',
            '/awalan: it would send visitors back to itself.',
            '/BESAR: it would send visitors back to itself.',
            '/b: /a already redirects back to it.',
            '/y: /x already redirects back to it.',
            '/d: /c already redirects back to it.',
        ])
        ->and(Redirect::query()->where('origin', 'xerads')->orderBy('source')->pluck('source')->all())->toBe(['/a', '/baik', '/c'])
        ->and($warnings->getArrayCopy())->toHaveCount(6);
});

it('rejects rules that are not what XerAds sends', function () {
    $result = applyRedirects([
        xeradsRule('//evil.example/x', '/baru'),
        xeradsRule('https://toko.test/lama', '/baru'),
        xeradsRule('/lama', 'http://toko-lain.example/'),
        xeradsRule('/lama', 'javascript:alert(1)'),
        xeradsRule('/lama', '//evil.example'),
        xeradsRule('/lama', '/baru', ['status' => 418]),
        xeradsRule('/lama', '/baru', ['match' => 'regex']),
        xeradsRule('/lama', null),
        xeradsRule('/ditarik', 'https://diabaikan.example', ['status' => 451]),
    ]);

    expect($result['applied'])->toBe(1)
        ->and($result['rejected'])->toHaveCount(8)
        ->and(redirectRows())->toBe(['xerads exact /ditarik -> - 451']);
});

it('applies the document `xerads:sync` pulls, once per version, and closes the 404s it covers', function () {
    storeTestKey();
    NotFoundEntry::query()->create(['path' => '/lama', 'path_hash' => sha1('/lama'), 'hits' => 4, 'reported_hits' => 4, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    fakeXerads();

    $this->artisan('xerads:sync', ['--redirects' => true])->assertSuccessful();

    expect(redirectRows())->toBe(['xerads exact /lama -> /baru 301'])
        ->and(NotFoundEntry::query()->value('status'))->toBe(NotFoundEntry::RESOLVED);

    $this->get('/lama?a=1')->assertRedirect('/baru?a=1');

    // The same version again changes nothing, even a rule edited by hand.
    Redirect::query()->update(['target' => '/disunting']);
    fakeXerads(['redirects' => Http::response('', 304, ['ETag' => '"r1"'])]);
    $this->artisan('xerads:sync', ['--redirects' => true])->assertSuccessful();

    expect(Redirect::query()->value('target'))->toBe('/disunting');

    // A new version replaces the rules.
    fakeXerads(['redirects' => Http::response(['version' => 2, 'data' => [xeradsRule('/lain', null, ['status' => 410])]], 200, ['ETag' => '"r2"'])]);
    $this->artisan('xerads:sync', ['--redirects' => true])->assertSuccessful();

    expect(redirectRows())->toBe(['xerads exact /lain -> - 410']);
});

describe('under the turnkey blog\'s prefix', function () {
    beforeEach(function () {
        $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
        applyRedirects([
            xeradsRule('/blog/ditarik', null, ['status' => 451]),
            xeradsRule('/blog/lama', '/blog/baru'),
        ]);
    });

    it('answers as everywhere else: 451 kept, Location relative, query as sent', function () {
        $this->get('/blog/ditarik')->assertStatus(451);
        $this->get('http://evil.example/blog/lama?b=2&a=1')->assertStatus(301)->assertHeader('Location', '/blog/baru?b=2&a=1');
    });

    it('follows xerads.redirects.enabled', function () {
        config(['xerads.redirects.enabled' => false]);

        $this->get('/blog/lama')->assertNotFound();
        $this->get('/blog/ditarik')->assertNotFound();
    });

    it('answers from the blog itself where the middleware is not global', function () {
        $this->bootTurnkey(['xerads.credentials.key' => testSiteKey(), 'xerads.middleware.redirects' => false]);
        applyRedirects([xeradsRule('/blog/ditarik', null, ['status' => 451]), xeradsRule('/blog/lama', '/blog/baru')]);

        $this->get('/blog/ditarik')->assertStatus(451);
        $this->get('/blog/lama?b=2&a=1')->assertHeader('Location', '/blog/baru?b=2&a=1');
        // Elsewhere nothing answers for it.
        applyRedirects([xeradsRule('/lain', '/baru')]);
        $this->get('/lain')->assertNotFound();
    });
});
