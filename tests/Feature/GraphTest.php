<?php

/**
 * The page's one JSON-LD @graph: valid, linked by @id, without empty values,
 * and unable to break out of its <script>.
 */

use Illuminate\Support\Str;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Seo\Schema\Graph;
use XerAds\Laravel\Tests\TestCase;

/** Every @id a node defines, and every @id something points at. */
function graphIds(array $graph): array
{
    $defined = [];
    $referenced = [];

    $walk = function (mixed $value, bool $top) use (&$walk, &$defined, &$referenced) {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['@id'])) {
            if (count($value) === 1) {
                $referenced[] = $value['@id'];
            } else {
                $defined[] = $value['@id'];
            }
        }

        foreach ($value as $item) {
            $walk($item, false);
        }
    };

    $walk($graph['@graph'], true);

    return [array_values(array_unique($defined)), array_values(array_unique($referenced))];
}

function turnkeyArticleHead(TestCase $test, array $article = [], array $settings = []): string
{
    $test->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
    app()->detectEnvironment(fn () => 'production');

    if ($settings !== []) {
        holdSettings($settings);
    }

    deliver($test, upsertEnvelope(array_merge(['taxonomy' => ['categories' => [['name' => 'Keuangan', 'slug' => 'keuangan']], 'tags' => []]], $article), ['delivery_id' => (string) Str::ulid()]));

    return headOf($test->get('/blog/'.($article['slug'] ?? 'panduan-kpr-2026')));
}

it('is valid JSON-LD whose references all point at nodes of the graph', function () {
    $graph = jsonLd(turnkeyArticleHead($this));

    expect($graph['@context'])->toBe('https://schema.org')
        ->and(collect($graph['@graph'])->pluck('@type')->all())->toBe(['Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'ImageObject', 'BlogPosting']);

    [$defined, $referenced] = graphIds($graph);

    expect($defined)->toContain('http://localhost/#organization', 'http://localhost/#website', 'http://localhost/blog/panduan-kpr-2026#webpage', 'http://localhost/blog/panduan-kpr-2026#breadcrumb', 'http://localhost/blog/panduan-kpr-2026#primaryimage', 'http://localhost/blog/panduan-kpr-2026#article')
        ->and(array_diff($referenced, $defined))->toBe([]);

    $article = collect($graph['@graph'])->firstWhere('@type', 'BlogPosting');

    expect($article)->toMatchArray([
        'headline' => 'Panduan KPR 2026: Syarat dan Bunga',
        'datePublished' => '2026-10-01T09:30:00+00:00',
        // Written at 08:00, published unchanged at 09:30: never earlier.
        'dateModified' => '2026-10-01T09:30:00+00:00',
        'author' => ['@id' => 'http://localhost/#organization'],
        'publisher' => ['@id' => 'http://localhost/#organization'],
        'articleSection' => 'Keuangan',
        'inLanguage' => 'id',
    ]);
});

it('leaves out empty values', function () {
    $json = json_encode(jsonLd(turnkeyArticleHead($this)));

    expect($json)->not->toContain('null')
        ->and($json)->not->toContain('""')
        ->and($json)->not->toContain('[]');
});

it('cannot be closed from the content it carries', function () {
    $evil = 'Judul </script><script>alert(1)</script> & <!-- komentar';

    $head = turnkeyArticleHead($this, ['seo' => array_merge(upsertFixture()['data']['article']['seo'], ['title' => $evil, 'description' => $evil]), 'headline' => $evil]);

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $head, $match);

    expect($match[1])->not->toContain('<')
        ->and($match[1])->not->toContain('>')
        ->and($match[1])->not->toContain('&')
        ->and($match[1])->toContain('\\u003C/script\\u003E')
        // Decoded, the text is exactly what was sent.
        ->and(collect(jsonLd($head)['@graph'])->firstWhere('@type', 'BlogPosting')['headline'])->toBe(mb_substr($evil, 0, 110))
        // The rest of the head escapes it as HTML.
        ->and($head)->not->toContain('<script>alert(1)</script>')
        ->and(substr_count($head, '<script'))->toBe(1);
});

it('uses the settings\' article type and default author', function () {
    $graph = jsonLd(turnkeyArticleHead($this, settings: ['articles' => ['schema_type' => 'NewsArticle', 'default_author' => ['name' => 'Redaksi Toko', 'url' => 'https://toko.test/redaksi']]]));

    expect(collect($graph['@graph'])->firstWhere('@id', 'http://localhost/blog/panduan-kpr-2026#article'))->toMatchArray([
        '@type' => 'NewsArticle',
        'author' => ['@type' => 'Person', 'name' => 'Redaksi Toko', 'url' => 'https://toko.test/redaksi'],
    ]);
});

it('describes the organisation from the settings', function () {
    holdSettings(['organization' => ['type' => 'LocalBusiness', 'name' => 'PT Toko', 'legal_name' => 'PT Toko Sejahtera', 'logo' => ['url' => 'https://cdn.toko.test/logo.png'], 'same_as' => ['https://instagram.com/toko']]]);

    $organization = jsonLd(headOf(headPage($this, '/')))['@graph'][0];

    expect($organization)->toBe([
        '@type' => 'LocalBusiness',
        '@id' => 'http://localhost/#organization',
        'name' => 'PT Toko',
        'legalName' => 'PT Toko Sejahtera',
        'url' => 'http://localhost/',
        'logo' => ['@type' => 'ImageObject', '@id' => 'http://localhost/#logo', 'url' => 'https://cdn.toko.test/logo.png', 'contentUrl' => 'https://cdn.toko.test/logo.png'],
        'sameAs' => ['https://instagram.com/toko'],
    ]);
});

it('declares search only when the site has a search url', function () {
    $website = fn () => collect(jsonLd(headOf(headPage($this, '/')))['@graph'])->firstWhere('@type', 'WebSite');

    expect($website())->not->toHaveKey('potentialAction');

    config(['xerads.seo.search_url' => '/search?q={search_term_string}']);

    expect($website()['potentialAction'])->toBe([
        '@type' => 'SearchAction',
        'target' => ['@type' => 'EntryPoint', 'urlTemplate' => 'http://localhost/search?q={search_term_string}'],
        'query-input' => 'required name=search_term_string',
    ]);
});

it('takes pieces the site adds, in config and while handling the request', function () {
    config(['xerads.schema.pieces' => [fn (Graph $graph) => $graph->add(['@type' => 'Thing', '@id' => '#extra', 'name' => 'Tambahan'])]]);

    $graph = jsonLd(headOf(headPage($this, '/about', fn () => Xerads::head()->schema(fn (Graph $graph, $head) => $graph->add(['@type' => 'FAQPage', '@id' => $head->canonical.'#faq', 'name' => 'Tanya'])))));

    expect(collect($graph['@graph'])->pluck('@type')->all())->toContain('Thing', 'FAQPage');
});

it('never says an article was modified before it was published', function () {
    $later = '2026-10-03T10:00:00Z';
    $head = turnkeyArticleHead($this, ['dates' => ['created_at' => null, 'content_updated_at' => $later, 'published_at' => '2026-10-01T09:30:00Z']]);
    $article = collect(jsonLd($head)['@graph'])->firstWhere('@type', 'BlogPosting');

    expect($article['dateModified'])->toBe('2026-10-03T10:00:00+00:00')
        ->and(metaContent($head, 'article:modified_time'))->toBe(['2026-10-03T10:00:00+00:00']);

    $earlier = headOf(headPage($this, '/mapped', fn () => Xerads::head()->for(['title' => 'A', 'type' => 'article', 'published_at' => '2026-10-01T09:30:00Z', 'modified_at' => '2026-10-01T08:00:00Z'])));

    expect(metaContent($earlier, 'article:modified_time'))->toBe(['2026-10-01T09:30:00Z']);
});

it('calls the organisation what the site is called, unless it has a name of its own', function () {
    holdSettings(['site' => ['name' => 'Toko'], 'organization' => ['name' => null]]);

    expect(jsonLd(headOf(headPage($this, '/')))['@graph'][0]['name'])->toBe('Toko');

    holdSettings(['site' => ['name' => 'Toko'], 'organization' => ['name' => 'PT Toko']]);

    expect(jsonLd(headOf(headPage($this, '/')))['@graph'][0]['name'])->toBe('PT Toko');
});

it('gives the logo the site logo\'s dimensions only when it is the site logo', function () {
    holdSettings(['site' => ['logo' => ['url' => 'https://cdn.toko.test/site.png', 'width' => 600, 'height' => 60]], 'organization' => ['logo' => ['url' => 'https://cdn.toko.test/org.png']]]);

    expect(jsonLd(headOf(headPage($this, '/')))['@graph'][0]['logo'])->toBe(['@type' => 'ImageObject', '@id' => 'http://localhost/#logo', 'url' => 'https://cdn.toko.test/org.png', 'contentUrl' => 'https://cdn.toko.test/org.png']);

    holdSettings(['site' => ['logo' => ['url' => 'https://cdn.toko.test/site.png', 'width' => 600, 'height' => 60]], 'organization' => ['logo' => null]]);

    expect(jsonLd(headOf(headPage($this, '/')))['@graph'][0]['logo'])->toMatchArray(['url' => 'https://cdn.toko.test/site.png', 'width' => 600, 'height' => 60]);
});

it('survives a broken UTF-8 byte in a value', function () {
    $head = headOf(headPage($this, '/bad', fn () => Xerads::head()->description("Rusak \xB1 teks")));

    expect($head)->not->toContain('<script type="application/ld+json"></script>')
        ->and(collect(jsonLd($head)['@graph'])->firstWhere('@type', 'WebPage')['description'])->toBe("Rusak \u{FFFD} teks");
});
