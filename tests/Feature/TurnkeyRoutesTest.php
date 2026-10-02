<?php

/**
 * The turnkey blog end to end: deliveries through the real webhook, pages
 * through the real routes of an application booted in turnkey mode.
 */

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Workbench\App\Providers\SiteRoutesServiceProvider;
use XerAds\Laravel\Content\ArticleBodies;
use XerAds\Laravel\Content\Contracts\ContentReceiver;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Seo\Redirects\Redirect;
use XerAds\Laravel\Tests\TestCase;
use XerAds\Laravel\XeradsServiceProvider;

const TURNKEY_ID = '01JA9Z5K7M3X8Q2W4E6R1T0Y9U';

beforeEach(function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
});

/**
 * An `article.upsert` of the fixture article, with fields replaced.
 *
 * @param  array<string, mixed>  $article
 */
function upsertArticle(TestCase $test, array $article = [], int $sequence = 1): TestResponse
{
    return deliver($test, upsertEnvelope($article, ['sequence' => $sequence, 'delivery_id' => (string) Str::ulid()]));
}

function removeArticle(TestCase $test, string $event, int $sequence, string $xeradsId = TURNKEY_ID): TestResponse
{
    return deliver($test, envelopeFor($event, ['article' => ['xerads_id' => $xeradsId, 'remote_id' => null, 'slug' => null, 'public_url' => null], 'redirect_to' => null], $sequence));
}

/** Another article, so lists have more than one. */
function otherArticle(int $number, array $article = []): array
{
    return array_merge([
        'xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Z'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
        'title' => "Artikel {$number}",
        'headline' => null,
        'slug' => "artikel-{$number}",
        'revision' => "sha256:{$number}",
        'remote_id' => null,
        'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null],
    ], $article);
}

it('publishes an article at /blog/{slug} with one H1, the headline', function () {
    $reply = upsertArticle($this)->assertCreated();

    expect($reply->json('url'))->toBe('http://localhost/blog/panduan-kpr-2026')
        ->and($reply->json('state'))->toBe('published')
        ->and($reply->json('preview_url'))->toBeNull()
        ->and($reply->json('id'))->toBe((string) Article::sole()->id);

    $page = $this->get('/blog/panduan-kpr-2026')->assertOk();
    $html = (string) $page->getContent();

    expect(substr_count(strtolower($html), '<h1'))->toBe(1)
        ->and($html)->toContain('<h1>Panduan KPR 2026: Syarat dan Bunga</h1>')
        ->and($html)->toContain('<title>Panduan KPR 2026</title>')
        ->and($html)->toContain('<img src="https://cdn.xerads.test/articles/panduan-kpr-2026-1.png"')
        ->and($html)->toContain('Daftar Isi')
        ->and($html)->toContain('<a href="#apa-itu-kpr">Apa itu KPR</a>')
        // The widget placeholder became its container, and the page loads the runtime.
        ->and($html)->toContain('<div data-xerads-widget="w_k3v9q2m8x1c4b7na" data-lang="id" style="min-height:2320px"></div>')
        ->and($html)->toContain('/v1/loader.js');
});

it('keeps the article page to one H1 when the body has one of its own', function () {
    upsertArticle($this, ['content' => ['html' => '<h1>Judul lagi</h1><p>Isi.</p><h2>Bagian</h2>'], 'headline' => null]);

    $html = (string) $this->get('/blog/panduan-kpr-2026')->assertOk()->getContent();

    expect(substr_count(strtolower($html), '<h1'))->toBe(1)
        ->and($html)->toContain('<h1>Panduan KPR 2026</h1>')
        ->and($html)->toContain('<h2 id="judul-lagi">Judul lagi</h2>');
});

it('answers 404 for a draft, which its preview link shows', function () {
    $reply = upsertArticle($this, ['status' => 'draft', 'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null]])->assertCreated();

    expect($reply->json('state'))->toBe('draft')
        ->and($reply->json('url'))->toBeNull();

    $this->get('/blog/panduan-kpr-2026')->assertNotFound();

    $preview = (string) $reply->json('preview_url');

    expect($preview)->toStartWith('http://localhost/blog/preview/'.TURNKEY_ID.'?signature=')
        ->and($preview)->not->toContain('expires=');

    $page = $this->get($preview)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect((string) $page->getContent())->toContain('<meta name="robots" content="noindex, nofollow">')
        ->and((string) $page->getContent())->toContain('Pratinjau');

    // A link that was not signed by this site opens nothing.
    $this->get('/blog/preview/'.TURNKEY_ID.'?signature=forged')->assertForbidden();
    $this->get('/blog/preview/'.TURNKEY_ID)->assertForbidden();
});

it('answers 404 once unpublished, and serves the article again when it is republished', function () {
    upsertArticle($this);
    removeArticle($this, 'article.unpublish', 2)->assertOk()->assertJson(['state' => 'unpublished']);

    expect(Article::sole()->status)->toBe('unpublished');
    $this->get('/blog/panduan-kpr-2026')->assertNotFound();

    upsertArticle($this, [], 3)->assertOk()->assertJson(['state' => 'published']);
    $this->get('/blog/panduan-kpr-2026')->assertOk();
});

it('answers 410 once deleted, and the preview link stops working', function () {
    $draft = upsertArticle($this, ['status' => 'draft']);
    $preview = (string) $draft->json('preview_url');
    upsertArticle($this, [], 2);

    removeArticle($this, 'article.delete', 3)->assertOk()->assertJson(['state' => 'deleted']);

    expect(Article::withTrashed()->sole()->trashed())->toBeTrue()
        ->and(Redirect::forPath('/blog/panduan-kpr-2026'))->toMatchArray(['origin' => 'auto', 'status' => 410]);

    $this->get('/blog/panduan-kpr-2026')->assertStatus(410);
    $this->get($preview)->assertNotFound();

    // Sent again, the article comes back, at its address.
    upsertArticle($this, [], 4)->assertOk();

    $this->get('/blog/panduan-kpr-2026')->assertOk();
    expect(Redirect::forPath('/blog/panduan-kpr-2026'))->toBeNull();
});

it('answers 404, not 410, for a deleted draft that was never public', function () {
    upsertArticle($this, ['status' => 'draft', 'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null]]);
    removeArticle($this, 'article.delete', 2)->assertOk();

    $this->get('/blog/panduan-kpr-2026')->assertNotFound();
});

it('follows XerAds\' slug until the article is first published', function () {
    $draft = ['status' => 'draft', 'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null]];

    upsertArticle($this, $draft);
    upsertArticle($this, ['slug' => 'kpr-2026-lengkap', 'revision' => 'sha256:b'] + $draft, 2);

    expect(Article::sole()->slug)->toBe('kpr-2026-lengkap');

    upsertArticle($this, ['slug' => 'kpr-2026-lengkap', 'revision' => 'sha256:b'], 3);
    upsertArticle($this, ['slug' => 'judul-baru', 'title' => 'Judul baru', 'revision' => 'sha256:c'], 4)
        ->assertOk()
        ->assertJson(['url' => 'http://localhost/blog/kpr-2026-lengkap']);

    // Published once, the address is kept: the old links keep working.
    expect(Article::sole()->slug)->toBe('kpr-2026-lengkap')
        ->and(Article::sole()->title)->toBe('Judul baru');

    $this->get('/blog/kpr-2026-lengkap')->assertOk();
    $this->get('/blog/judul-baru')->assertNotFound();
});

it('moves a published article with a 301 when the site lets slugs change', function () {
    config(['xerads.content.slug.freeze_after_publish' => false]);

    upsertArticle($this);
    upsertArticle($this, ['slug' => 'kpr-terbaru', 'revision' => 'sha256:b'], 2)
        ->assertOk()
        ->assertJson(['url' => 'http://localhost/blog/kpr-terbaru']);

    $this->get('/blog/kpr-terbaru')->assertOk();
    $this->get('/blog/panduan-kpr-2026?ref=old')->assertRedirect('http://localhost/blog/kpr-terbaru?ref=old')->assertStatus(301);

    // Moved again: the first address goes straight to the newest, no chain.
    upsertArticle($this, ['slug' => 'kpr-final', 'revision' => 'sha256:c'], 3);

    $this->get('/blog/panduan-kpr-2026')->assertRedirect('http://localhost/blog/kpr-final');
    $this->get('/blog/kpr-terbaru')->assertRedirect('http://localhost/blog/kpr-final');

    // And back to the first address: it is served again, not redirected.
    upsertArticle($this, ['slug' => 'panduan-kpr-2026', 'revision' => 'sha256:d'], 4);

    $this->get('/blog/panduan-kpr-2026')->assertOk();
    expect(Redirect::query()->where('source', '/blog/panduan-kpr-2026')->exists())->toBeFalse();
});

it('gives each article its own slug', function () {
    upsertArticle($this);
    upsertArticle($this, otherArticle(1, ['slug' => 'panduan-kpr-2026', 'title' => 'Panduan KPR 2026']));

    expect(Article::query()->orderBy('id')->pluck('slug')->all())->toBe(['panduan-kpr-2026', 'panduan-kpr-2026-2']);
});

it('lists published articles, newest first, a page at a time', function () {
    config(['xerads.content.turnkey.per_page' => 2]);

    foreach ([1, 2, 3] as $number) {
        upsertArticle($this, otherArticle($number, [
            'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => "2026-09-0{$number}T08:00:00Z"],
        ]));
    }

    upsertArticle($this, otherArticle(4, ['status' => 'draft']));

    $first = (string) $this->get('/blog')->assertOk()->getContent();

    expect(substr_count(strtolower($first), '<h1'))->toBe(1)
        ->and($first)->toContain('Artikel 3')
        ->and($first)->toContain('Artikel 2')
        ->and($first)->not->toContain('Artikel 1')
        ->and($first)->not->toContain('Artikel 4')
        ->and($first)->toContain('href="http://localhost/blog?page=2" rel="next"');

    $second = (string) $this->get('/blog?page=2')->assertOk()->getContent();

    expect($second)->toContain('Artikel 1')
        ->and($second)->toContain('<title>Blog, page 2</title>')
        // The first page has one address, without ?page=1.
        ->and($second)->toContain('href="http://localhost/blog" rel="prev"');

    $this->get('/blog?page=3')->assertNotFound();
});

it('shows category and tag pages from the taxonomy XerAds sends', function () {
    upsertArticle($this, ['taxonomy' => [
        'categories' => [['name' => 'Keuangan', 'slug' => 'keuangan'], ['name' => 'Rumah', 'slug' => 'rumah']],
        'tags' => [['name' => 'KPR', 'slug' => 'kpr'], 'Bunga Bank'],
    ]]);
    upsertArticle($this, otherArticle(1, ['taxonomy' => ['categories' => [['name' => 'Rumah', 'slug' => 'rumah']], 'tags' => []]]));

    $article = Article::query()->where('xerads_id', TURNKEY_ID)->sole();

    expect($article->categories->pluck('slug')->all())->toBe(['keuangan', 'rumah'])
        ->and($article->primaryCategory?->slug)->toBe('keuangan')
        ->and($article->tags->pluck('slug')->sort()->values()->all())->toBe(['bunga-bank', 'kpr']);

    $finance = (string) $this->get('/blog/category/keuangan')->assertOk()->getContent();

    expect(substr_count(strtolower($finance), '<h1'))->toBe(1)
        ->and($finance)->toContain('<h1>Keuangan</h1>')
        ->and($finance)->toContain('Panduan KPR 2026')
        ->and($finance)->not->toContain('Artikel 1');

    $home = (string) $this->get('/blog/category/rumah')->assertOk()->getContent();

    expect($home)->toContain('Panduan KPR 2026')->and($home)->toContain('Artikel 1');

    $tag = (string) $this->get('/blog/tag/bunga-bank')->assertOk()->getContent();

    expect(substr_count(strtolower($tag), '<h1'))->toBe(1)
        ->and($tag)->toContain('<h1>Bunga Bank</h1>')
        ->and($tag)->toContain('Panduan KPR 2026');

    $this->get('/blog/category/tidak-ada')->assertNotFound();
    $this->get('/blog/tag/tidak-ada')->assertNotFound();

    // An empty list empties the article's terms; the terms themselves stay.
    upsertArticle($this, ['revision' => 'sha256:b', 'taxonomy' => ['categories' => [], 'tags' => []]], 2);

    expect($article->fresh()?->categories)->toHaveCount(0)
        ->and($article->fresh()?->primary_category_id)->toBeNull()
        ->and(Category::query()->count())->toBe(2);
});

it('leaves the terms alone when XerAds sends no taxonomy at all', function () {
    upsertArticle($this, ['taxonomy' => ['categories' => [['name' => 'Rumah', 'slug' => 'rumah']], 'tags' => []]]);
    upsertArticle($this, ['revision' => 'sha256:b', 'taxonomy' => null], 2);

    expect(Article::sole()->categories->pluck('slug')->all())->toBe(['rumah']);
});

it('caches the rendered body by article, revision and package version', function () {
    upsertArticle($this);

    $article = Article::sole();
    $key = app(ArticleBodies::class)->key($article);

    expect($key)->toContain(TURNKEY_ID.':'.$article->revision.':');

    $this->get('/blog/panduan-kpr-2026')->assertOk();

    expect(Cache::get($key))->toContain('data-xerads-widget');

    // A new revision is rendered from the new body.
    upsertArticle($this, ['revision' => 'sha256:b', 'content' => ['html' => '<p>Isi baru.</p>']], 2);

    expect((string) $this->get('/blog/panduan-kpr-2026')->getContent())->toContain('Isi baru.');
});

it('answers 404 for a slug that never existed', function () {
    $this->get('/blog/tidak-pernah-ada')->assertNotFound();
});

it('registers no blog in mapped mode', function () {
    $this->rebootWith(['xerads.content.mode' => 'mapped']);

    $this->get('/blog')->assertNotFound();
    $paths = array_map(fn (string $path) => realpath($path), app('migrator')->paths());

    expect($paths)->not->toContain(realpath(__DIR__.'/../../database/migrations/turnkey'));
});

it('wears the site\'s own layout when configured', function () {
    $views = scratchDirectory('views');
    file_put_contents($views.'/site.blade.php', '<html><body><nav>Situs</nav>@yield(\'main\')</body></html>');
    app('view')->addLocation($views);
    config(['xerads.content.turnkey.layout' => 'site', 'xerads.content.turnkey.section' => 'main']);

    upsertArticle($this);

    $html = (string) $this->get('/blog/panduan-kpr-2026')->assertOk()->getContent();

    expect($html)->toContain('<nav>Situs</nav>')
        ->and($html)->toContain('<h1>Panduan KPR 2026: Syarat dan Bunga</h1>')
        ->and($html)->not->toContain('xerads-site');
});

it('publishes its views and its migrations for the site to keep', function () {
    $views = ServiceProvider::pathsToPublish(XeradsServiceProvider::class, 'xerads-views');
    $migrations = ServiceProvider::pathsToPublish(XeradsServiceProvider::class, 'xerads-turnkey-migrations');

    expect(array_map('realpath', array_keys($views)))->toBe([realpath(__DIR__.'/../../resources/views')])
        ->and(array_values($views))->toBe([resource_path('views/vendor/xerads')])
        ->and(array_map('realpath', array_keys($migrations)))->toBe([realpath(__DIR__.'/../../database/migrations/turnkey')]);
});

it('reports a ready receiver once the tables exist, and what is missing before', function () {
    expect(app(ContentReceiver::class)->validateConfiguration()->isValid())->toBeTrue();

    $this->rebootWith(['xerads.content.mode' => 'turnkey']);

    expect(app(ContentReceiver::class)->validateConfiguration()->problems[0] ?? '')->toContain('php artisan migrate');
});

it('stores XerAds\' UTC dates in the application\'s timezone', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey(), 'app.timezone' => 'America/New_York']);
    date_default_timezone_set('America/New_York');

    try {
        $hourAgo = Carbon::now('UTC')->subHour()->startOfSecond();
        $stamp = $hourAgo->format('Y-m-d\TH:i:s\Z');

        upsertArticle($this, ['dates' => ['created_at' => $stamp, 'content_updated_at' => $stamp, 'published_at' => $stamp]])->assertCreated();

        expect(DB::table('xerads_articles')->value('published_at'))->toBe($hourAgo->copy()->setTimezone('America/New_York')->format('Y-m-d H:i:s'))
            ->and(Article::sole()->published_at?->equalTo($hourAgo))->toBeTrue();

        // Published an hour ago, so public now, not five hours from now.
        $this->get('/blog/panduan-kpr-2026')->assertOk();
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('leaves the site\'s own routes under the prefix to the site', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()], [SiteRoutesServiceProvider::class]);

    $this->get('/blog/feed')->assertOk()->assertSee('the site\'s own feed', escape: false);

    // As `route:cache` would serve them: the same order.
    $compiled = app('router')->getRoutes()->compile();
    $cached = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))->setRouter(app('router'))->setContainer(app());

    expect($cached->match(Request::create('/blog/feed'))->getName())->toBe('site.feed')
        ->and($cached->match(Request::create('/blog/panduan-kpr-2026'))->getName())->toBe('xerads.blog.show');

    upsertArticle($this);
    $this->get('/blog/panduan-kpr-2026')->assertOk();

    // An article offered that address gets another, which it is shown at.
    upsertArticle($this, otherArticle(1, ['slug' => 'feed']));

    expect(Article::query()->where('xerads_id', otherArticle(1)['xerads_id'])->value('slug'))->toBe('feed-2');
    $this->get('/blog/feed-2')->assertOk();
});

it('prints an escaped placeholder as text, on the page and in the preview', function () {
    $html = '<p>Tulis [[xerads_widget id="w_k3v9q2m8x1c4b7na"]] untuk menyisipkan widget.</p><p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>';

    $reply = upsertArticle($this, ['status' => 'draft', 'content' => ['html' => $html]]);
    $preview = (string) $this->get((string) $reply->json('preview_url'))->assertOk()->getContent();

    upsertArticle($this, ['content' => ['html' => $html]], 2);
    $page = (string) $this->get('/blog/panduan-kpr-2026')->assertOk()->getContent();

    foreach ([$page, $preview] as $rendered) {
        expect($rendered)->toContain('Tulis [xerads_widget id="w_k3v9q2m8x1c4b7na"] untuk menyisipkan widget.')
            ->and(substr_count($rendered, '<div data-xerads-widget='))->toBe(1);
    }
});

it('answers 301 from an earlier address again once a deleted article is restored', function () {
    config(['xerads.content.slug.freeze_after_publish' => false]);

    upsertArticle($this);
    upsertArticle($this, ['slug' => 'kpr-terbaru', 'revision' => 'sha256:b'], 2);
    removeArticle($this, 'article.delete', 3);

    // Deleted: the old address still leads to the new one, which is gone.
    $this->get('/blog/panduan-kpr-2026')->assertRedirect('http://localhost/blog/kpr-terbaru');
    $this->get('/blog/kpr-terbaru')->assertStatus(410);

    upsertArticle($this, ['slug' => 'kpr-terbaru', 'revision' => 'sha256:b'], 4);

    $this->get('/blog/panduan-kpr-2026')->assertRedirect('http://localhost/blog/kpr-terbaru')->assertStatus(301);
    $this->get('/blog/kpr-terbaru')->assertOk();
});

it('never gives a new article an address that still redirects', function () {
    config(['xerads.content.slug.freeze_after_publish' => false]);

    upsertArticle($this);
    upsertArticle($this, ['slug' => 'kpr-terbaru', 'revision' => 'sha256:b'], 2);

    // A draft offered the old address, then renamed to it.
    upsertArticle($this, otherArticle(1, ['status' => 'draft', 'slug' => 'panduan-kpr-2026']));
    upsertArticle($this, otherArticle(1, ['slug' => 'panduan-kpr-2026', 'revision' => 'sha256:x']), 2);

    expect(Article::query()->where('xerads_id', otherArticle(1)['xerads_id'])->value('slug'))->toBe('panduan-kpr-2026-2');

    // Old links still reach the article they were for.
    $this->get('/blog/panduan-kpr-2026')->assertRedirect('http://localhost/blog/kpr-terbaru');
});

it('keeps a category or tag of drafts only off the site', function () {
    $taxonomy = ['categories' => [['name' => 'Rahasia', 'slug' => 'rahasia']], 'tags' => [['name' => 'Bocoran', 'slug' => 'bocoran']]];

    upsertArticle($this, ['status' => 'draft', 'taxonomy' => $taxonomy]);

    $this->get('/blog/category/rahasia')->assertNotFound();
    $this->get('/blog/tag/bocoran')->assertNotFound();

    upsertArticle($this, ['taxonomy' => $taxonomy], 2);

    $this->get('/blog/category/rahasia')->assertOk();
    $this->get('/blog/tag/bocoran')->assertOk();
});

it('lets a new article take the address of a deleted draft that was never public', function () {
    $draft = ['status' => 'draft', 'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null]];

    upsertArticle($this, $draft);
    removeArticle($this, 'article.delete', 2);
    upsertArticle($this, otherArticle(1, ['slug' => 'panduan-kpr-2026']));

    expect(Article::query()->where('xerads_id', otherArticle(1)['xerads_id'])->value('slug'))->toBe('panduan-kpr-2026');
    $this->get('/blog/panduan-kpr-2026')->assertOk();

    // Sent again, the deleted draft comes back under a free address.
    upsertArticle($this, $draft, 3);

    expect(Article::query()->where('xerads_id', TURNKEY_ID)->value('slug'))->toBe('panduan-kpr-2026-2');
});
