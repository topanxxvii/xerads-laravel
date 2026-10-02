<?php

/**
 * /sitemap.xml and /sitemaps/{source}-{page}.xml: valid against the
 * protocol's schema (tests/Fixtures/sitemap, offline), complete or 503, with
 * only the pages search engines should index.
 */

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Workbench\App\Models\Post;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Seo\ContentVersion;
use XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource;
use XerAds\Laravel\Seo\Sitemap\SitemapBuilder;
use XerAds\Laravel\Seo\Sitemap\SitemapUrl;
use XerAds\Laravel\Seo\Sitemap\SitemapWriter;
use XerAds\Laravel\Tests\TestCase;

/** Libxml's complaints, or [] when the document is valid. */
function schemaErrors(string $xml, string $xsd): array
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $document = new DOMDocument;
    $document->loadXML($xml);
    $valid = $document->schemaValidate(__DIR__.'/../Fixtures/sitemap/'.$xsd);
    $errors = array_map(fn (LibXMLError $error) => trim($error->message), libxml_get_errors());

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $valid ? [] : ($errors ?: ['invalid']);
}

/** Every <loc> of a sitemap or an index, in order. */
function locs(string $xml): array
{
    $document = new SimpleXMLElement($xml);
    $document->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    return array_map('strval', $document->xpath('//s:loc') ?: []);
}

/** The sitemap index, then every sitemap it lists, each checked against the schema, asked on `$origin`. */
function crawlSitemaps(TestCase $test, string $origin = ''): array
{
    $index = $test->get($origin.'/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = (string) $index->getContent();

    expect(schemaErrors($xml, 'siteindex.xsd'))->toBe([]);

    $urls = [];

    foreach (locs($xml) as $sitemap) {
        $page = (string) $test->get($origin.parse_url($sitemap, PHP_URL_PATH))->assertOk()->getContent();

        expect(schemaErrors($page, 'sitemap.xsd'))->toBe([]);
        $urls[basename((string) parse_url($sitemap, PHP_URL_PATH))] = locs($page);
    }

    return $urls;
}

function publish(TestCase $test, array $article = [], int $sequence = 1): TestResponse
{
    return deliver($test, upsertEnvelope($article, ['sequence' => $sequence, 'delivery_id' => (string) Str::ulid()]));
}

function anotherArticle(int $number, array $article = []): array
{
    return array_merge([
        'xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Z'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
        'title' => "Artikel {$number}",
        'headline' => null,
        'slug' => "artikel-{$number}",
        'revision' => "sha256:{$number}",
        'remote_id' => null,
        'taxonomy' => ['categories' => [['name' => 'Rumah', 'slug' => 'rumah']], 'tags' => [['name' => 'KPR', 'slug' => 'kpr']]],
    ], $article);
}

/** A source of the site's own that fails while it is read. */
class FailingSitemapSource implements SitemapSource
{
    public function name(): string
    {
        return 'broken';
    }

    public function urls(): iterable
    {
        yield new SitemapUrl('http://localhost/a');

        throw new RuntimeException('The products table is locked.');
    }
}

describe('a turnkey blog', function () {
    beforeEach(function () {
        $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
    });

    it('lists the articles and categories, valid against the schema, with images and lastmod', function () {
        publish($this, ['taxonomy' => ['categories' => [['name' => 'Keuangan', 'slug' => 'keuangan']], 'tags' => []]]);
        publish($this, anotherArticle(1));
        publish($this, anotherArticle(2, ['status' => 'draft', 'taxonomy' => ['categories' => [['name' => 'Rahasia', 'slug' => 'rahasia']], 'tags' => []]]));

        $urls = crawlSitemaps($this);

        expect(array_keys($urls))->toBe(['articles-1.xml', 'categories-1.xml'])
            ->and($urls['articles-1.xml'])->toBe(['http://localhost/blog/panduan-kpr-2026', 'http://localhost/blog/artikel-1'])
            // A category of drafts only is no page at all.
            ->and($urls['categories-1.xml'])->toBe(['http://localhost/blog/category/keuangan', 'http://localhost/blog/category/rumah']);

        $articles = (string) $this->get('/sitemaps/articles-1.xml')->getContent();

        expect($articles)->toContain('<image:image><image:loc>https://cdn.xerads.test/articles/panduan-kpr-2026-1.png</image:loc></image:image>')
            ->and($articles)->toContain('<lastmod>2026-10-01T08:00:00+00:00</lastmod>');
    });

    it('lists tags when the settings include them, and leaves images out when they do not', function () {
        publish($this, anotherArticle(1));
        holdSettings(['sitemap' => ['include' => ['tags' => true, 'images' => false]], 'robots' => ['noindex' => ['tags' => false]]]);

        $urls = crawlSitemaps($this);

        expect($urls['tags-1.xml'])->toBe(['http://localhost/blog/tag/kpr'])
            ->and((string) $this->get('/sitemaps/articles-1.xml')->getContent())->not->toContain('image:');
    });

    it('leaves out what search engines should not index', function () {
        publish($this, anotherArticle(1));
        publish($this, anotherArticle(2, ['seo' => array_merge(upsertFixture()['data']['article']['seo'], ['robots' => ['index' => false, 'follow' => true]])]));
        publish($this, anotherArticle(3, ['seo' => array_merge(upsertFixture()['data']['article']['seo'], ['canonical_url' => 'https://partner.test/asli'])]));
        publish($this, anotherArticle(4, ['slug' => 'internal-catatan']));
        publish($this, anotherArticle(5, ['slug' => 'promo-lama']));
        publish($this, anotherArticle(6, ['seo' => array_merge(upsertFixture()['data']['article']['seo'], ['canonical_url' => 'http://localhost/blog/artikel-6'])]));

        holdSettings([
            'robots' => ['paths' => [['pattern' => '/blog/internal-*', 'index' => false, 'follow' => true]]],
            'sitemap' => ['exclude_paths' => ['/blog/promo-lama']],
        ]);

        expect(crawlSitemaps($this)['articles-1.xml'])->toBe([
            'http://localhost/blog/artikel-1',
            // Its canonical is itself.
            'http://localhost/blog/artikel-6',
        ]);
    });

    it('lists nothing while the settings keep the site out of search engines', function () {
        publish($this);
        holdSettings(['robots' => ['index_site' => false]]);

        // The protocol has no empty index, so there is none.
        expect(schemaErrors(app(SitemapWriter::class)->index([]), 'siteindex.xsd'))->not->toBe([]);

        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/sitemaps/articles-1.xml')->assertNotFound();

        app()->detectEnvironment(fn () => 'production');
        expect((string) $this->get('/robots.txt')->getContent())->not->toContain('Sitemap:');
    });

    it('leaves out the groups and pages the settings noindex, as the pages themselves say', function () {
        publish($this, anotherArticle(1));
        publish($this, anotherArticle(2));
        publish($this, anotherArticle(3, ['seo' => array_merge(upsertFixture()['data']['article']['seo'], ['robots' => ['index' => false, 'follow' => true]])]));
        publish($this, anotherArticle(4, ['slug' => 'arsip-lama']));

        holdSettings([
            'sitemap' => ['include' => ['tags' => true]],
            'robots' => [
                'noindex' => ['categories' => true, 'tags' => true],
                'paths' => [['pattern' => '/blog/arsip-*', 'index' => false, 'follow' => true]],
            ],
            'pages' => [
                ['path' => '/blog/artikel-2', 'title' => null, 'description' => null, 'canonical' => null, 'og_image' => null, 'robots' => ['index' => false, 'follow' => true]],
                // pages[] may lift a path rule, but never the article's own noindex.
                ['path' => '/blog/arsip-lama', 'title' => null, 'description' => null, 'canonical' => null, 'og_image' => null, 'robots' => ['index' => true, 'follow' => true]],
                ['path' => '/blog/artikel-3', 'title' => null, 'description' => null, 'canonical' => null, 'og_image' => null, 'robots' => ['index' => true, 'follow' => true]],
            ],
        ]);

        $urls = crawlSitemaps($this);

        expect(array_keys($urls))->toBe(['articles-1.xml'])
            ->and($urls['articles-1.xml'])->toBe(['http://localhost/blog/artikel-1', 'http://localhost/blog/arsip-lama']);

        $this->get('/sitemaps/categories-1.xml')->assertNotFound();
        $this->get('/sitemaps/tags-1.xml')->assertNotFound();

        // And the head of a page left out says noindex.
        app()->detectEnvironment(fn () => 'production');
        $robots = fn (string $path) => implode(' ', metaContent((string) $this->get($path)->getContent(), 'robots'));

        expect($robots('/blog/artikel-2'))->toContain('noindex')
            ->and($robots('/blog/artikel-3'))->toContain('noindex')
            ->and($robots('/blog/category/rumah'))->toContain('noindex')
            ->and($robots('/blog/arsip-lama'))->not->toContain('noindex');
    });

    it('builds while the cache is down', function () {
        publish($this);
        Cache::extend('broken', fn () => new CacheRepository(new BrokenStore));
        config(['cache.stores.broken' => ['driver' => 'broken'], 'xerads.cache.store' => 'broken']);

        expect(crawlSitemaps($this)['articles-1.xml'])->toBe(['http://localhost/blog/panduan-kpr-2026']);
    });

    it('cuts a source into pages of max_urls', function () {
        foreach ([1, 2, 3] as $number) {
            publish($this, anotherArticle($number, ['dates' => ['created_at' => null, 'content_updated_at' => "2026-09-0{$number}T08:00:00Z", 'published_at' => "2026-09-0{$number}T08:00:00Z"]]));
        }

        holdSettings(['sitemap' => ['max_urls' => 2, 'include' => ['categories' => false]]]);

        $urls = crawlSitemaps($this);

        expect(array_keys($urls))->toBe(['articles-1.xml', 'articles-2.xml'])
            ->and(count($urls['articles-1.xml']))->toBe(2)
            ->and(count($urls['articles-2.xml']))->toBe(1);

        // The index dates each page by its newest address.
        expect((string) $this->get('/sitemap.xml')->getContent())->toContain('<lastmod>2026-09-03T08:00:00+00:00</lastmod>');

        $this->get('/sitemaps/articles-3.xml')->assertNotFound();
        $this->get('/sitemaps/unknown-1.xml')->assertNotFound();
    });

    it('answers from the cache until the content changes', function () {
        publish($this);
        crawlSitemaps($this);

        // A row the package did not write itself: not seen until a change.
        $copy = Article::sole()->replicate();
        $copy->forceFill(['xerads_id' => null, 'slug' => 'disisipkan'])->save();

        expect(crawlSitemaps($this)['articles-1.xml'])->toBe(['http://localhost/blog/panduan-kpr-2026']);

        publish($this, anotherArticle(1));

        expect(crawlSitemaps($this)['articles-1.xml'])->toContain('http://localhost/blog/disisipkan', 'http://localhost/blog/artikel-1');
    });

    it('answers 503 rather than a partial sitemap', function () {
        publish($this);
        config(['xerads.sitemap.sources' => [FailingSitemapSource::class]]);

        $this->get('/sitemap.xml')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertDontSee('<urlset', false);

        $this->get('/sitemaps/broken-1.xml')->assertStatus(503);
    });

    it('answers 404 when the settings turn sitemaps off', function () {
        holdSettings(['sitemap' => ['enabled' => false]]);

        $this->get('/sitemap.xml')->assertNotFound();
    });

    it('puts images on the site\'s own address, and leaves out what is no address', function () {
        publish($this);
        holdSettings(['site' => ['url' => 'https://toko.test']]);
        DB::table('xerads_articles')->update(['featured_image_url' => '/storage/xerads/sampul.png']);
        publish($this, anotherArticle(1));
        DB::table('xerads_articles')->where('slug', 'artikel-1')->update(['featured_image_url' => 'http://localhost/storage/xerads/dua.png']);
        publish($this, anotherArticle(2));
        DB::table('xerads_articles')->where('slug', 'artikel-2')->update(['featured_image_url' => 'data:image/png;base64,AAAA']);
        app(ContentVersion::class)->bump();

        $page = (string) $this->get('/sitemaps/articles-1.xml')->getContent();

        expect(schemaErrors($page, 'sitemap.xsd'))->toBe([])
            ->and($page)->toContain('<image:loc>https://toko.test/storage/xerads/sampul.png</image:loc>')
            ->and($page)->toContain('<image:loc>https://toko.test/storage/xerads/dua.png</image:loc>')
            ->and($page)->not->toContain('data:image');
    });

    it('builds every address on the site\'s own base, whatever the Host header', function () {
        publish($this);
        holdSettings(['site' => ['url' => 'https://toko.test']]);

        $index = (string) $this->get('http://evil.example/sitemap.xml')->getContent();
        $page = (string) $this->get('http://evil.example/sitemaps/articles-1.xml')->getContent();

        expect(locs($index))->toBe(['https://toko.test/sitemaps/articles-1.xml'])
            ->and(locs($page))->toBe(['https://toko.test/blog/panduan-kpr-2026']);
    });
});

it('lists XerAds\' articles in a mapped model at the address the site reported', function () {
    config(['xerads.credentials.key' => testSiteKey()]);
    publish($this);
    publish($this, anotherArticle(1, ['status' => 'draft']));

    $urls = crawlSitemaps($this);

    expect($urls)->toBe(['articles-1.xml' => ['http://localhost/posts/panduan-kpr-2026']]);
});

it('lists models whose addresses come from url() and route() on the site\'s own host, whatever Host came first', function (string $class) {
    DB::table('posts')->insert([
        ['user_id' => 1, 'title' => 'Satu', 'slug' => 'satu', 'content' => '', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
    ]);
    holdSettings(['site' => ['url' => 'https://toko.test']]);
    config(['xerads.sitemap.models' => ['pages' => $class]]);

    // A forged Host on a cold cache, then an alias, then the site itself.
    $forged = crawlSitemaps($this, 'http://evil.example');
    $alias = crawlSitemaps($this, 'http://www.toko.test');
    $own = crawlSitemaps($this, 'https://toko.test');

    $expected = $class === SitemapUrlPost::class ? 'https://toko.test/halaman/satu' : 'https://toko.test/posts/satu';

    expect($forged['pages-1.xml'])->toBe([$expected])
        ->and($alias['pages-1.xml'])->toBe([$expected])
        ->and($own['pages-1.xml'])->toBe([$expected]);
})->with([
    'url() in xeradsSitemapUrl()' => [SitemapUrlPost::class],
    'the mapped model\'s public route' => [Post::class],
]);

it('never caches what it built while answering another host', function () {
    config(['xerads.sitemap.sources' => [RequestHostSource::class]]);

    // Built on the forged host, the address is someone else's: left out,
    // and not remembered.
    $this->get('http://evil.example/sitemap.xml')->assertNotFound();

    // A relative address in a test follows the previous request's host.
    expect(crawlSitemaps($this, 'http://localhost')['halaman-1.xml'])->toBe(['http://localhost/halaman/satu']);
});

it('lists a mapped site\'s articles whatever Host the sitemap is asked on', function () {
    config(['xerads.credentials.key' => testSiteKey()]);
    publish($this);
    holdSettings(['site' => ['url' => 'https://toko.test']]);

    expect(crawlSitemaps($this, 'http://evil.example')['articles-1.xml'])->toBe(['https://toko.test/posts/panduan-kpr-2026'])
        ->and(crawlSitemaps($this, 'https://toko.test')['articles-1.xml'])->toBe(['https://toko.test/posts/panduan-kpr-2026']);
});

it('leaves out, and warns about, addresses XML cannot carry', function () {
    DB::table('posts')->insert([
        ['user_id' => 1, 'title' => 'Satu', 'slug' => 'satu', 'content' => '', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => 1, 'title' => 'Rusak', 'slug' => "rusak\x01", 'content' => '', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => 1, 'title' => 'Bukan UTF-8', 'slug' => "caf\xff", 'content' => '', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()],
    ]);
    config(['xerads.sitemap.models' => ['pages' => SitemapPost::class]]);
    $warnings = $this->captureWarnings();

    expect(crawlSitemaps($this)['pages-1.xml'])->toBe(['http://localhost/halaman/satu'])
        ->and($warnings->getArrayCopy())->toBe(['XerAds left 2 address(es) out of the pages sitemap: they are not valid UTF-8 or contain control characters.']);
});

it('refuses a sitemap name that cannot be part of an address, saying why', function (array $models, string $message) {
    config(['xerads.sitemap.models' => $models]);

    expect(fn () => app(SitemapBuilder::class)->sources())->toThrow(InvalidArgumentException::class, $message);

    $this->get('/sitemap.xml')->assertStatus(503);
})->with([
    'capitals' => [['Products' => SitemapPost::class], 'xerads.sitemap.models: "Products" cannot name a sitemap. Use lowercase letters a-z only'],
    'a hyphen' => [['blog-posts' => SitemapPost::class], '"blog-posts" cannot name a sitemap'],
    'not a model' => [['pages' => stdClass::class], 'xerads.sitemap.models.pages must be the class name of an Eloquent model.'],
    'taken' => [['articles' => SitemapPost::class], 'Two sitemaps are named "articles"'],
]);

it('lists the site\'s own models through their sitemap scope and address', function () {
    DB::table('posts')->insert([
        ['user_id' => 1, 'title' => 'Satu', 'slug' => 'satu', 'content' => '', 'status' => 'published', 'created_at' => now(), 'updated_at' => '2026-09-01 08:00:00'],
        ['user_id' => 1, 'title' => 'Draf', 'slug' => 'draf', 'content' => '', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
    ]);
    config(['xerads.sitemap.models' => ['pages' => SitemapPost::class]]);

    $urls = crawlSitemaps($this);

    expect($urls['pages-1.xml'])->toBe(['http://localhost/halaman/satu'])
        ->and((string) $this->get('/sitemaps/pages-1.xml')->getContent())->toContain('<lastmod>2026-09-01T08:00:00+00:00</lastmod>');
});

/** A source of the site's own that reads the request's host itself. */
class RequestHostSource implements SitemapSource
{
    public function name(): string
    {
        return 'halaman';
    }

    public function urls(): iterable
    {
        yield new SitemapUrl('http://'.request()->getHost().'/halaman/satu');
    }
}

/** A model whose address is built with url(), as most sites write it. */
class SitemapUrlPost extends Post
{
    protected $table = 'posts';

    public function xeradsSitemapUrl(): string
    {
        return url('/halaman/'.$this->slug);
    }
}

/** A cache that is down. */
class BrokenStore implements Store
{
    public function get($key)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function many(array $keys)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function put($key, $value, $seconds)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function putMany(array $values, $seconds)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function increment($key, $value = 1)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function decrement($key, $value = 1)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function forever($key, $value)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function forget($key)
    {
        throw new RuntimeException('The cache is down.');
    }

    public function flush()
    {
        throw new RuntimeException('The cache is down.');
    }

    public function getPrefix()
    {
        return '';
    }

    public function touch($key, $seconds)
    {
        throw new RuntimeException('The cache is down.');
    }
}

/** A model of the site's own, with a sitemap scope and address. */
class SitemapPost extends Post
{
    public function scopeXeradsSitemap(Builder $query): void
    {
        $query->where('status', 'published');
    }

    public function xeradsSitemapUrl(): string
    {
        return '/halaman/'.$this->slug;
    }
}

it('has schemas that refuse what the protocol refuses', function () {
    $ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';

    expect(schemaErrors('<?xml version="1.0"?><urlset '.$ns.'><url><lastmod>2026-10-01</lastmod></url></urlset>', 'sitemap.xsd'))->not->toBe([])
        ->and(schemaErrors('<?xml version="1.0"?><urlset '.$ns.'><url><loc>/relative</loc></url></urlset>', 'sitemap.xsd'))->not->toBe([])
        ->and(schemaErrors('<?xml version="1.0"?><urlset '.$ns.'><url><loc>https://a.test/</loc><image:image><image:title>x</image:title></image:image></url></urlset>', 'sitemap.xsd'))->not->toBe([])
        ->and(schemaErrors('<?xml version="1.0"?><urlset '.$ns.'><url><loc>https://a.test/</loc><lastmod>yesterday</lastmod></url></urlset>', 'sitemap.xsd'))->not->toBe([])
        ->and(schemaErrors('<?xml version="1.0"?><urlset '.$ns.'><url><loc>https://a.test/</loc><lastmod>2026-10-01T08:00:00+07:00</lastmod><image:image><image:loc>https://a.test/i.png</image:loc></image:image></url></urlset>', 'sitemap.xsd'))->toBe([]);
});
