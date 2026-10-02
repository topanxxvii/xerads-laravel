<?php

/**
 * What the head prints, compared with saved snapshots (tests/Fixtures/head),
 * and the rules every page keeps: one of each tag, everything escaped, no
 * page indexed outside production.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Workbench\App\Models\Post;
use XerAds\Laravel\Facades\Xerads;

/** Compare with tests/Fixtures/head/{name}.html; XERADS_UPDATE_SNAPSHOTS=1 rewrites it. */
function assertHeadSnapshot(string $name, string $head): void
{
    $file = __DIR__.'/../Fixtures/head/'.$name.'.html';

    if (getenv('XERADS_UPDATE_SNAPSHOTS') === '1') {
        file_put_contents($file, $head."\n");
    }

    expect($head."\n")->toBe((string) file_get_contents($file));
}

/** Every tag that must appear once at most, with how often it does. */
function tagCounts(string $head): array
{
    $counts = ['<title>' => substr_count($head, '<title>'), 'ld+json' => substr_count($head, 'application/ld+json'), 'canonical' => substr_count($head, 'rel="canonical"')];

    foreach (['description', 'robots', 'og:locale', 'og:type', 'og:title', 'og:description', 'og:url', 'og:site_name', 'og:image', 'twitter:card'] as $key) {
        $counts[$key] = count(metaContent($head, $key));
    }

    return $counts;
}

beforeEach(function () {
    app()->detectEnvironment(fn () => 'production');
});

it('prints a turnkey article\'s whole head', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
    app()->detectEnvironment(fn () => 'production');
    holdSettings(['site' => ['name' => 'Toko', 'tagline' => 'Rumah impian', 'url' => 'https://toko.test', 'default_language' => 'id'], 'meta' => ['twitter_site' => '@toko_contoh']]);

    deliver($this, upsertEnvelope([
        'taxonomy' => ['categories' => [['name' => 'Keuangan', 'slug' => 'keuangan']], 'tags' => [['name' => 'KPR', 'slug' => 'kpr']]],
        'author' => ['name' => 'Rina', 'url' => 'https://toko.test/rina'],
    ], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

    // The package's tags, without the layout's own around them.
    preg_match('#<title>.*?application/ld\+json">.*?</script>#s', headOf($this->get('/blog/panduan-kpr-2026')), $match);
    $head = $match[0];

    assertHeadSnapshot('turnkey-article', $head);
    expect(array_values(array_unique(tagCounts($head))))->toBe([1]);
});

it('prints a mapped post\'s stored SEO through <x-xerads::head :for>', function () {
    config(['xerads.credentials.key' => testSiteKey()]);
    deliver($this, upsertEnvelope([
        'seo' => array_merge(upsertFixture()['data']['article']['seo'], [
            'canonical_url' => 'https://toko.test/kanonik/kpr',
            'robots' => ['index' => true, 'follow' => false],
            'og' => ['title' => 'KPR untuk dibagikan', 'description' => 'Ringkas untuk media sosial.'],
        ]),
    ], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

    $post = Post::sole();
    $head = headOf(headPage($this, '/artikel/panduan-kpr-2026', null, '<x-xerads::head :for="\\Workbench\\App\\Models\\Post::sole()" />'));

    assertHeadSnapshot('mapped-post', $head);
    expect(array_values(array_unique(tagCounts($head))))->toBe([1])
        ->and($post->xeradsSeo()?->title)->toBe('Panduan KPR 2026: Syarat, Bunga, Simulasi');
});

it('prints a plain page\'s head, with the verification tags on the home page only', function () {
    holdSettings(['site' => ['name' => 'Toko', 'tagline' => 'Rumah impian', 'url' => 'https://toko.test'], 'meta' => ['default_description' => 'Toko rumah terpercaya.', 'default_og_image' => ['url' => 'https://cdn.toko.test/og.png', 'width' => 1200, 'height' => 630]], 'verification' => ['google' => 'g-123', 'bing' => 'B456', 'pinterest' => 'p789']]);

    $home = headOf(headPage($this, '/'));
    $about = headOf(headPage($this, '/about', fn () => Xerads::head()->title('Tentang kami')));

    assertHeadSnapshot('home', $home);
    assertHeadSnapshot('plain-page', $about);

    expect(metaContent($home, 'google-site-verification'))->toBe(['g-123'])
        ->and(metaContent($home, 'msvalidate.01'))->toBe(['B456'])
        ->and(metaContent($home, 'p:domain_verify'))->toBe(['p789'])
        ->and(metaContent($about, 'google-site-verification'))->toBe([])
        ->and(array_values(array_unique(tagCounts($home))))->toBe([1]);
});

it('keeps every page out of search engines outside production', function () {
    app()->detectEnvironment(fn () => 'staging');

    $head = headOf(headPage($this, '/about'));

    expect(metaContent($head, 'robots'))->toBe(['noindex, follow']);
});

it('escapes everything it prints', function () {
    $evil = '"><script>alert(1)</script>';

    $head = headOf(headPage($this, '/about', fn () => Xerads::head()
        ->title($evil)
        ->description($evil)
        ->image('https://cdn.toko.test/a.png?x="><script>', alt: $evil)));

    expect($head)->not->toContain('<script>alert(1)</script>')
        ->and($head)->not->toContain('"><script>')
        ->and($head)->toContain('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('prints only the groups asked for, or all but some', function () {
    $only = headOf(headPage($this, '/about', null, '<x-xerads::head only="title, canonical" />'));
    $except = headOf(headPage($this, '/about', null, '<x-xerads::head except="title,jsonld,og" />'));

    expect($only)->toBe("<title>Laravel</title>\n<link rel=\"canonical\" href=\"http://localhost/about\">")
        ->and($except)->not->toContain('<title>')
        ->and($except)->not->toContain('og:')
        ->and($except)->not->toContain('ld+json')
        ->and($except)->toContain('name="robots"');
});

it('gives the same head as an array, for headless and Inertia front ends', function () {
    holdSettings(['site' => ['name' => 'Toko']]);

    headPage($this, '/about', fn () => Xerads::head()->title('Tentang kami')->description('Siapa kami.'));
    $array = Xerads::head()->toArray();

    expect($array['title'])->toBe('Tentang kami - Toko')
        ->and($array['meta'])->toContain(['name' => 'description', 'content' => 'Siapa kami.'])
        ->and($array['meta'])->toContain(['property' => 'og:title', 'content' => 'Tentang kami'])
        ->and($array['link'])->toBe([['rel' => 'canonical', 'href' => 'http://localhost/about']])
        ->and($array['jsonld'][0]['@context'])->toBe('https://schema.org');
});

it('prints the same with the directive and with a model passed to it', function () {
    $directive = Blade::render('@xeradsHead(["title" => "Dari direktif"])');

    expect($directive)->toContain('<title>Dari direktif - Laravel</title>');
});

it('prints the trail the structured data uses, and nothing when breadcrumbs are off', function () {
    $prepare = fn () => Xerads::breadcrumbs()->push('Produk', '/produk')->push('Sepatu');

    $page = (string) headPage($this, '/produk/sepatu', $prepare, '@xeradsHead</head><body><x-xerads::breadcrumbs /><x-xerads::toc :items="[[\'id\' => \'ukuran\', \'text\' => \'Ukuran\', \'level\' => 2]]" />')->getContent();

    expect($page)->toContain('<nav class="xerads-breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="http://localhost/">Home</a></li><li><a href="http://localhost/produk">Produk</a></li><li aria-current="page">Sepatu</li></ol></nav>')
        ->and($page)->toContain('<a href="#ukuran">Ukuran</a>')
        ->and(collect(jsonLd(headOf(headPage($this, '/produk/sepatu', $prepare)))['@graph'])->firstWhere('@type', 'BreadcrumbList')['itemListElement'][1])
        ->toBe(['@type' => 'ListItem', 'position' => 2, 'name' => 'Produk', 'item' => 'http://localhost/produk']);

    holdSettings(['breadcrumbs' => ['enabled' => false]]);
    $off = (string) headPage($this, '/produk/sepatu', $prepare, '@xeradsHead</head><body><x-xerads::breadcrumbs />')->getContent();

    expect($off)->not->toContain('xerads-breadcrumbs')
        ->and($off)->not->toContain('BreadcrumbList');
});

it('takes the model on the breadcrumbs too, so a trail printed before the head agrees with it', function () {
    config(['xerads.credentials.key' => testSiteKey()]);
    deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

    // The body renders before the layout's head: the trail must know the post itself.
    $page = (string) headPage(
        $this,
        '/artikel/kpr',
        fn () => Xerads::breadcrumbs()->push('Artikel', '/artikel')->push('KPR'),
        '<x-xerads::head :for="\\Workbench\\App\\Models\\Post::sole()" /></head><body><x-xerads::breadcrumbs :for="\\Workbench\\App\\Models\\Post::sole()" />',
    )->getContent();

    expect($page)->toContain('<li><a href="http://localhost/">Beranda</a></li>')
        ->and(collect(jsonLd(headOf(headPage($this, '/artikel/kpr', fn () => Xerads::breadcrumbs()->push('Artikel', '/artikel')->push('KPR'), '<x-xerads::head :for="\\Workbench\\App\\Models\\Post::sole()" />')))['@graph'])->firstWhere('@type', 'BreadcrumbList')['itemListElement'][0]['name'])->toBe('Beranda');
});
