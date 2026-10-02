<?php

/**
 * A client chooses the Host header. Nothing in the head may be built from it:
 * a forged host would make the page declare another site canonical.
 *
 * The host is forged through the request's address (http://evil.example/…):
 * a `Host` header passed to a test request is overwritten by the address.
 */

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use XerAds\Laravel\Facades\Xerads;

/** Every absolute address in the head. */
function addressesIn(string $head): array
{
    preg_match_all('#https?://[^"\s\\\\<]+#', str_replace('\/', '/', $head), $matches);

    return array_values(array_unique($matches[0]));
}

function forged(string $head): array
{
    return collect(addressesIn($head))->filter(fn ($url) => str_contains($url, 'evil.example'))->values()->all();
}

it('reaches the application with the forged host', function () {
    Route::get('/whoami', fn () => request()->getHost());

    expect($this->get('http://evil.example/whoami')->getContent())->toBe('evil.example');
});

it('builds the canonical, og:url and structured data from the site url, never the Host header', function () {
    holdSettings(['site' => ['name' => 'Toko', 'url' => 'https://toko.test']]);

    $head = headOf(headPage($this, 'http://evil.example/about', fn () => Xerads::breadcrumbs()->push('Bagian', url('/bagian'))->push('Tentang')));

    expect($head)->toContain('<link rel="canonical" href="https://toko.test/about">')
        ->and(metaContent($head, 'og:url'))->toBe(['https://toko.test/about'])
        ->and(jsonLd($head)['@graph'][0]['@id'])->toBe('https://toko.test/#organization')
        // An address url() built from the forged host lands back on the site.
        ->and(collect(jsonLd($head)['@graph'])->firstWhere('@type', 'BreadcrumbList')['itemListElement'][1]['item'])->toBe('https://toko.test/bagian')
        ->and(addressesIn($head))->not->toBeEmpty()
        ->and(forged($head))->toBe([]);
});

it('falls back to app.url, still never the Host header', function () {
    $head = headOf(headPage($this, 'http://evil.example/about'));

    expect($head)->toContain('<link rel="canonical" href="http://localhost/about">')
        ->and(forged($head))->toBe([]);
});

it('leaves an address on another domain alone, whatever the Host header says', function () {
    holdSettings(['site' => ['url' => 'https://toko.test']]);

    $prepare = fn () => Xerads::head()->canonical('https://partner.test/original')->image('https://partner.test/og.png');

    foreach (['/syndicated', 'http://partner.test/syndicated'] as $uri) {
        $head = headOf(headPage($this, $uri, $prepare));

        expect($head)->toContain('<link rel="canonical" href="https://partner.test/original">')
            ->and(metaContent($head, 'og:image'))->toBe(['https://partner.test/og.png']);
    }

    // An address on the site's own hosts is put on its base.
    $own = headOf(headPage($this, '/own', fn () => Xerads::head()->canonical('http://localhost/elsewhere')));

    expect($own)->toContain('<link rel="canonical" href="https://toko.test/elsewhere">');
});

it('keeps only the page number in a list\'s canonical, and only past page 1', function () {
    $list = fn () => Xerads::head()->page('archive');

    expect(headOf(headPage($this, '/blog?page=3&utm_source=news&ref=x', $list)))->toContain('<link rel="canonical" href="http://localhost/blog?page=3">')
        ->and(headOf(headPage($this, '/blog?page=1&fbclid=abc', $list)))->toContain('<link rel="canonical" href="http://localhost/blog">');
});

it('ignores ?page= on a page that is not a list', function () {
    $head = headOf(headPage($this, '/post?page=7', fn () => Xerads::head()->for(['title' => 'Satu artikel', 'type' => 'article'])));

    expect($head)->toContain('<link rel="canonical" href="http://localhost/post">')
        ->and($head)->toContain('<title>Satu artikel - Laravel</title>')
        ->and(collect(jsonLd($head)['@graph'])->pluck('@id')->filter()->implode(' '))->not->toContain('page=');

    // Unless the page says it is one page of several.
    $paged = headOf(headPage($this, '/post?page=7', fn () => Xerads::head()->title('Komentar')->paginated()));

    expect($paged)->toContain('<link rel="canonical" href="http://localhost/post?page=7">')
        ->and($paged)->toContain('<title>Komentar - Laravel - Page 7</title>');
});

it('puts a turnkey article\'s addresses on the site url under a forged host', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
    deliver($this, upsertEnvelope(['taxonomy' => ['categories' => [['name' => 'Keuangan', 'slug' => 'keuangan']], 'tags' => []]], ['delivery_id' => (string) Str::ulid()]));

    $response = $this->get('http://evil.example/blog/panduan-kpr-2026');
    $head = headOf($response);

    expect($head)->toContain('<link rel="canonical" href="http://localhost/blog/panduan-kpr-2026">')
        ->and(forged($head))->toBe([])
        ->and(collect(jsonLd($head)['@graph'])->firstWhere('@type', 'BreadcrumbList')['itemListElement'][2]['item'])->toBe('http://localhost/blog/category/keuangan')
        // The visible trail too.
        ->and((string) $response->getContent())->toContain('<a href="http://localhost/blog/category/keuangan">Keuangan</a></li>');
});
