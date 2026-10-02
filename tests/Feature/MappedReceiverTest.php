<?php

/**
 * Paired deliveries written into the site's own model: the cases where a
 * careless receiver takes over, nulls or misreports a post.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Post;
use Workbench\App\Models\SoftDeletingPost;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Seo\Models\SeoMeta;

beforeEach(function () {
    config(['xerads.credentials.key' => testSiteKey()]);
});

/** A follow-up delivery of the fixture article. */
function followUp(array $article, int $sequence = 1843): array
{
    return upsertEnvelope(
        array_merge(['revision' => 'sha256:'.$sequence], $article),
        ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT), 'sequence' => $sequence],
    );
}

it('fills a NOT NULL column from the defaults, and names it when nothing does', function () {
    config(['xerads.content.mapped.defaults' => []]);

    deliver($this, upsertEnvelope())
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'RECEIVER_MISCONFIGURED', 'column' => 'user_id'])
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'posts.user_id'));

    expect(Post::count())->toBe(0)
        ->and(DB::table('xerads_content_map')->count())->toBe(0);

    config(['xerads.content.mapped.defaults' => ['user_id' => 4]]);

    // The same delivery, retried after the fix, goes through.
    deliver($this, upsertEnvelope())->assertCreated();

    expect((int) Post::sole()->user_id)->toBe(4);
});

it('does not take over a post that shares the slug', function () {
    $unrelated = Post::create(['user_id' => 7, 'title' => 'Hand written', 'slug' => 'panduan-kpr-2026', 'content' => '<p>Mine.</p>', 'status' => 'published']);

    $reply = deliver($this, upsertEnvelope(['remote_id' => null]))->assertCreated();

    expect($reply->json('id'))->not->toBe((string) $unrelated->id)
        ->and($reply->json('url'))->toBe('http://localhost/posts/panduan-kpr-2026-2')
        ->and($unrelated->fresh()->title)->toBe('Hand written');
});

it('adopts a post by slug only when told to', function () {
    config(['xerads.content.mapped.match_existing_by_slug' => true]);
    $imported = Post::create(['user_id' => 7, 'title' => 'Imported', 'slug' => 'panduan-kpr-2026', 'content' => '<p>Copied.</p>', 'status' => 'draft']);

    deliver($this, upsertEnvelope(['remote_id' => null]))->assertOk()->assertJson(['id' => (string) $imported->id, 'created' => false]);

    expect(Post::count())->toBe(1);
});

it('adopts the post the original endpoint created, by the id XerAds echoes', function () {
    $legacy = Post::create(['user_id' => 7, 'title' => 'From v1', 'slug' => 'from-v1', 'content' => '<p>Old.</p>', 'status' => 'published']);

    deliver($this, upsertEnvelope(['remote_id' => (string) $legacy->id]))
        ->assertOk()
        ->assertJson(['id' => (string) $legacy->id, 'created' => false]);

    expect(Post::count())->toBe(1)
        ->and($legacy->fresh()->title)->toBe('Panduan KPR 2026');
});

it('reports the url of the slug that was saved', function () {
    Post::saving(fn (Post $post) => $post->slug = $post->slug.'-site');

    deliver($this, upsertEnvelope())
        ->assertCreated()
        ->assertJson(['url' => 'http://localhost/posts/panduan-kpr-2026-site']);
});

it('keeps the slug of a published post when the title changes', function () {
    deliver($this, upsertEnvelope())->assertCreated();

    deliver($this, followUp(['title' => 'Judul baru', 'slug' => 'judul-baru']))
        ->assertOk()
        ->assertJson(['url' => 'http://localhost/posts/panduan-kpr-2026']);

    expect(Post::sole()->slug)->toBe('panduan-kpr-2026');
});

it('never nulls an image it already has', function () {
    deliver($this, upsertEnvelope())->assertCreated();

    deliver($this, followUp(['image' => null]))->assertOk();

    expect(Post::sole()->image_url)->toBe('https://cdn.xerads.test/articles/panduan-kpr-2026-1.png');
});

it('reports no url for a draft', function () {
    deliver($this, upsertEnvelope(['status' => 'draft']))
        ->assertCreated()
        ->assertJson(['state' => 'draft', 'url' => null]);

    expect(Post::sole()->status)->toBe('draft');
});

it('stores the SEO data beside the post, keeping fields the site locked', function () {
    deliver($this, upsertEnvelope())->assertCreated();
    $post = Post::sole();
    $meta = $post->xeradsSeo();

    expect($meta)->toBeInstanceOf(SeoMeta::class)
        ->and($meta->title)->toBe('Panduan KPR 2026: Syarat, Bunga, Simulasi')
        ->and($meta->description)->toBe('Syarat, bunga dan simulasi KPR 2026.')
        ->and($meta->focus_keyword)->toBe('kpr')
        ->and($meta->keywords)->toBe(['kpr', 'bunga kpr'])
        ->and($meta->robots)->toBe(['index' => true, 'follow' => true])
        ->and($meta->schema)->toBe(['type' => 'BlogPosting'])
        ->and($meta->source)->toBe('xerads')
        ->and($meta->extra('headline'))->toBe('Panduan KPR 2026: Syarat dan Bunga')
        ->and($meta->extra('toc'))->toBe(upsertFixture()['data']['article']['toc'])
        ->and($meta->widgetHeights())->toBe([TEST_WIDGET_ID => 2320])
        ->and($post->xeradsSeoValue('title'))->toBe('Panduan KPR 2026: Syarat, Bunga, Simulasi');

    $meta->forceFill(['title' => 'Judul pilihan situs', 'locked_fields' => ['title']])->save();

    deliver($this, followUp(['seo' => ['title' => 'Judul dari XerAds', 'description' => 'Baru.']]))->assertOk();

    expect($meta->fresh()->title)->toBe('Judul pilihan situs')
        ->and($meta->fresh()->description)->toBe('Baru.')
        ->and(SeoMeta::count())->toBe(1);
});

it('stores placeholders instead of containers when the site expands them itself', function () {
    config(['xerads.content.mapped.content_format' => 'shortcode']);

    deliver($this, upsertEnvelope())->assertCreated();

    expect(Post::sole()->content)->toContain('<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>')
        ->and(Post::sole()->content)->not->toContain('data-xerads-widget');
});

it('restores a soft-deleted post XerAds sends again', function () {
    config(['xerads.content.mapped.model' => SoftDeletingPost::class]);

    deliver($this, upsertEnvelope())->assertCreated();
    SoftDeletingPost::sole()->delete();

    deliver($this, followUp([]))->assertOk()->assertJson(['state' => 'published']);

    expect(SoftDeletingPost::count())->toBe(1);
});

it('hands paired articles to a receiver the site wrote for the original endpoint', function () {
    $received = new ArrayObject;

    app()->bind(ArticleReceiver::class, fn () => new class($received) implements ArticleReceiver
    {
        public function __construct(private ArrayObject $received) {}

        public function receive(IncomingArticle $article): array
        {
            $this->received[] = $article;

            return ['id' => 'custom-7', 'url' => 'https://site.example/custom'];
        }
    });

    // The fixture echoes an id this site issued before (remote_id 42), so as
    // far as the package can tell this is an update: 200, not 201.
    deliver($this, upsertEnvelope())
        ->assertOk()
        ->assertJson(['id' => 'custom-7', 'url' => 'https://site.example/custom', 'state' => 'published', 'created' => false]);

    /** @var IncomingArticle $article */
    $article = $received[0];

    expect($article->title)->toBe('Panduan KPR 2026')
        ->and($article->content)->toContain('data-xerads-widget')
        ->and($article->remoteId)->toBe('42')
        ->and(Post::count())->toBe(0);

    // The original interface has no way to take an article down.
    deliver($this, envelopeFor('article.unpublish', ['article' => ['xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Y9U']], 1843))
        ->assertStatus(422)
        ->assertJson(['error' => 'RECEIVER_MISCONFIGURED']);
});

it('keeps the slug once published, through an unpublish and a republish', function () {
    $neverPublishedBefore = ['dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null]];

    deliver($this, upsertEnvelope($neverPublishedBefore))->assertCreated();

    deliver($this, envelopeFor('article.unpublish', ['article' => ['xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Y9U']], 1843))
        ->assertOk()->assertJson(['state' => 'unpublished']);

    deliver($this, followUp($neverPublishedBefore + ['title' => 'Simulasi KPR 2027', 'slug' => 'simulasi-kpr-2027'], 1844))
        ->assertOk()
        ->assertJson(['state' => 'published', 'url' => 'http://localhost/posts/panduan-kpr-2026']);

    expect(Post::sole()->slug)->toBe('panduan-kpr-2026');
});

it('keeps the slug of a published post it adopted without any history', function () {
    $legacy = Post::create(['user_id' => 7, 'title' => 'Dari v1', 'slug' => 'dari-v1', 'content' => '<p>Lama.</p>', 'status' => 'published']);

    deliver($this, upsertEnvelope([
        'remote_id' => (string) $legacy->id,
        'dates' => ['created_at' => null, 'content_updated_at' => null, 'published_at' => null],
    ]))->assertOk()->assertJson(['url' => 'http://localhost/posts/dari-v1']);

    expect($legacy->fresh()->slug)->toBe('dari-v1');
});

it('answers a lost race for a slug with a retryable 409', function () {
    Post::create(['user_id' => 7, 'title' => 'Sudah ada', 'slug' => 'taken', 'content' => '<p>x</p>', 'status' => 'published']);

    // Another delivery takes the slug between the check and the insert.
    Post::creating(fn (Post $post) => $post->slug = 'taken');

    deliver($this, upsertEnvelope(['remote_id' => null]))
        ->assertStatus(409)
        ->assertJson(['ok' => false, 'error' => 'WRITE_CONFLICT']);

    expect(DB::table('xerads_deliveries')->value('status'))->toBe('failed');
});

it('answers a database outage with a retryable 500, not as a configuration problem', function () {
    Post::creating(fn () => throw new QueryException('testing', 'insert into posts', [], new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')));

    deliver($this, upsertEnvelope(['remote_id' => null]))
        ->assertStatus(500)
        ->assertJson(['ok' => false, 'error' => 'RECEIVER_FAILED']);
});

it('leaves a post the site trashed in the trash when XerAds takes it down', function (string $event, string $state) {
    config(['xerads.content.mapped.model' => SoftDeletingPost::class]);

    deliver($this, upsertEnvelope())->assertCreated();
    SoftDeletingPost::sole()->delete();

    deliver($this, envelopeFor($event, ['article' => ['xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Y9U']], 1843))
        ->assertOk()
        ->assertJson(['state' => $state]);

    expect(SoftDeletingPost::count())->toBe(0)
        ->and(SoftDeletingPost::withTrashed()->sole()->status)->toBe('published');
})->with([
    'unpublish' => ['article.unpublish', 'unpublished'],
    'delete, kept as a draft' => ['article.delete', 'deleted'],
]);

it('does not take over a row of a different model after the site switches models', function () {
    $unrelated = Post::create(['user_id' => 7, 'title' => 'Bukan artikel ini', 'slug' => 'lain', 'content' => '<p>x</p>', 'status' => 'published']);

    ContentMapEntry::create([
        'xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Y9U',
        'target' => 'mapped',
        'site_id' => TEST_SITE_ID,
        'model_type' => 'App\\Models\\Article',
        'model_id' => (string) $unrelated->id,
        'remote_id' => (string) $unrelated->id,
        'sequence' => 1,
        'state' => 'published',
    ]);

    deliver($this, upsertEnvelope(['remote_id' => (string) $unrelated->id]))->assertCreated();

    expect($unrelated->fresh()->title)->toBe('Bukan artikel ini')
        ->and(Post::count())->toBe(2);
});

it('refuses an id longer than XerAds can store, before keeping the post', function () {
    app()->bind(ArticleReceiver::class, fn () => new class implements ArticleReceiver
    {
        public function receive(IncomingArticle $article): array
        {
            return ['id' => str_repeat('x', 192), 'url' => null];
        }
    });

    deliver($this, upsertEnvelope())
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'RECEIVER_MISCONFIGURED'])
        ->assertJsonPath('message', fn (string $message) => str_contains($message, '191'));

    expect(ContentMapEntry::count())->toBe(0);
});

it('reads SEO values kept in extras on a model in strict mode', function () {
    deliver($this, upsertEnvelope())->assertCreated();

    Model::preventAccessingMissingAttributes();

    try {
        $post = Post::sole();

        expect($post->xeradsSeoValue('headline'))->toBe('Panduan KPR 2026: Syarat dan Bunga')
            ->and($post->xeradsSeoValue('title'))->toBe('Panduan KPR 2026: Syarat, Bunga, Simulasi')
            ->and($post->xeradsSeoValue('missing', 'fallback'))->toBe('fallback');
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});
