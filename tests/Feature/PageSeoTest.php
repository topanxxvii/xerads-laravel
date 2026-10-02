<?php

/**
 * `pages[]` in the settings: a title, description, canonical, share image and
 * robots for one exact path of the site's own.
 */

use XerAds\Laravel\Facades\Xerads;

beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');

    holdSettings(['site' => ['name' => 'Toko', 'url' => 'https://toko.test'], 'pages' => [
        [
            'path' => '/pricing',
            'title' => 'Harga paket',
            'description' => 'Semua paket dan harganya.',
            'canonical' => 'https://toko.test/harga',
            'og_image' => 'https://cdn.toko.test/harga.png',
            'robots' => ['index' => false, 'follow' => true],
        ],
        ['path' => '/', 'title' => 'Toko rumah', 'description' => 'Beranda toko.'],
    ]]);
});

it('gives its exact path everything it sets', function () {
    $head = headOf(headPage($this, '/pricing'));

    expect($head)->toContain('<title>Harga paket</title>')
        ->and(metaContent($head, 'description'))->toBe(['Semua paket dan harganya.'])
        ->and($head)->toContain('<link rel="canonical" href="https://toko.test/harga">')
        ->and(metaContent($head, 'og:url'))->toBe(['https://toko.test/harga'])
        ->and(metaContent($head, 'og:image'))->toBe(['https://cdn.toko.test/harga.png'])
        ->and(metaContent($head, 'twitter:card'))->toBe(['summary_large_image'])
        ->and(metaContent($head, 'robots'))->toBe(['noindex, follow']);
});

it('matches the path with or without a trailing slash, and ignores the query', function () {
    expect(headOf(headPage($this, '/pricing/?utm_source=x')))->toContain('<title>Harga paket</title>');
});

it('gives the home page its own entry', function () {
    $head = headOf(headPage($this, '/'));

    expect($head)->toContain('<title>Toko rumah</title>')
        ->and(metaContent($head, 'description'))->toBe(['Beranda toko.']);
});

it('leaves every other path alone', function () {
    $head = headOf(headPage($this, '/pricing/team'));

    expect($head)->toContain('<title>Toko</title>')
        ->and(metaContent($head, 'description'))->toBe([])
        ->and($head)->toContain('<link rel="canonical" href="https://toko.test/pricing/team">')
        ->and(metaContent($head, 'robots')[0])->toStartWith('index, follow');
});

it('yields to the page\'s own model', function () {
    $head = headOf(headPage($this, '/pricing', fn () => Xerads::head()->for(['title' => 'Paket Pro', 'canonical' => 'https://toko.test/pro'])));

    expect($head)->toContain('<title>Paket Pro - Toko</title>')
        ->and($head)->toContain('<link rel="canonical" href="https://toko.test/pro">')
        // What the model does not say, the page entry still does.
        ->and(metaContent($head, 'description'))->toBe(['Semua paket dan harganya.']);
});

it('matches a path however its characters are encoded', function () {
    holdSettings(['pages' => [['path' => '/tentang-café', 'title' => 'Tentang kafe']], 'robots' => ['paths' => [['pattern' => '/menu-%C3%A9t%C3%A9', 'index' => false, 'follow' => true]]]]);

    $head = headOf(headPage($this, '/tentang-caf%C3%A9'));

    expect($head)->toContain('<title>Tentang kafe</title>')
        // The canonical keeps the address as it is written on the web.
        ->and($head)->toContain('<link rel="canonical" href="http://localhost/tentang-caf%C3%A9">')
        ->and(metaContent(headOf(headPage($this, '/menu-été/minuman')), 'robots'))->toBe(['noindex, follow']);
});

it('falls back to the default description on every page without one of its own', function () {
    holdSettings(['site' => ['url' => 'https://toko.test'], 'meta' => ['default_description' => 'Toko rumah terpercaya.'], 'pages' => [['path' => '/pricing', 'description' => 'Semua paket.']]]);

    expect(metaContent(headOf(headPage($this, '/')), 'description'))->toBe(['Toko rumah terpercaya.'])
        ->and(metaContent(headOf(headPage($this, '/about')), 'description'))->toBe(['Toko rumah terpercaya.'])
        ->and(metaContent(headOf(headPage($this, '/pricing')), 'description'))->toBe(['Semua paket.'])
        ->and(metaContent(headOf(headPage($this, '/post', fn () => Xerads::head()->for(['title' => 'A', 'description' => 'Milik artikel.']))), 'description'))->toBe(['Milik artikel.']);
});
