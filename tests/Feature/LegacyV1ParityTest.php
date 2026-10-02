<?php

/**
 * The legacy endpoint, driven with the exact bytes XerAds sends.
 *
 * The request body, timestamp, secret and signature come from the v1
 * fixture: exactly what XerAds sends to a custom endpoint, kept
 * byte-identical with XerAds' own contract tests.
 */

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Workbench\App\Models\Note;
use Workbench\App\Models\Post;
use Workbench\App\Models\SoftDeletingPost;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;
use XerAds\CmsBridge\Receivers\EloquentArticleReceiver;
use XerAds\Laravel\Content\Contracts\ValidatesConfiguration;
use XerAds\Laravel\Tests\TestCase;

const LEGACY_URI = '/api/xerads/articles';

/**
 * POST a raw body the way XerAds does: signed bytes, sent unchanged.
 *
 * `$uri` and `$contentType` let a test play an attacker who replays captured
 * bytes with a query string or a different content type, neither of which
 * the signature covers.
 */
function sendLegacy(
    TestCase $test,
    string $body,
    ?int $timestamp = null,
    ?string $signature = null,
    ?string $secret = null,
    string $uri = LEGACY_URI,
    string $contentType = 'application/json',
): TestResponse {
    $fixture = TestCase::v1Fixture();
    $timestamp ??= $fixture['timestamp'];
    $secret ??= $fixture['secret'];
    $signature ??= hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return $test->call('POST', $uri, [], [], [], [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$secret,
        'HTTP_X_XERADS_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_XERADS_SIGNATURE' => $signature,
    ], $body);
}

/** @param  array<string, mixed>  $overrides */
function legacyBody(array $overrides = []): string
{
    $payload = array_merge(json_decode(TestCase::v1Fixture()['body'], true), $overrides);

    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** The body XerAds' "Test connection" button sends. */
function connectionTestBody(): string
{
    return json_encode([
        'test' => true,
        'title' => 'XerAds connection test',
        'seo_title' => 'XerAds connection test',
        'content' => 'This is a connection test from XerAds. Your endpoint should answer 2xx and store nothing.',
        'slug' => 'xerads-connection-test',
        'meta_description' => null,
        'keywords' => [],
        'image_url' => null,
        'status' => 'draft',
        'cms_post_id' => null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(TestCase::v1Fixture()['timestamp']));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stores the frozen v1 request and answers 201 with id and url', function () {
    $fixture = TestCase::v1Fixture();

    $response = sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature']);

    $post = Post::sole();

    // Exactly these two keys: the whole response contract XerAds reads.
    $response->assertCreated()->assertExactJson([
        'id' => $post->id,
        'url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3',
    ]);

    expect($post->title)->toBe('Panduan KPR 2026 — Syarat & Bunga')
        ->and($post->slug)->toBe('panduan-kpr-2026-a1b2c3')
        ->and($post->content)->toBe($fixture['article']['content'])
        ->and($post->meta_description)->toBe($fixture['article']['meta_description'])
        ->and($post->image_url)->toBe($fixture['article']['image_url'])
        ->and($post->keywords)->toBe('kpr, bunga kpr')
        ->and($post->status)->toBe('published')
        ->and((int) $post->user_id)->toBe(1);
});

it('verifies the raw body bytes, not a re-encoding of the parsed payload', function () {
    $body = TestCase::v1Fixture()['body'];
    $reencoded = json_encode(json_decode($body, true));

    // The premise: default json_encode escapes slashes and non-ASCII, so the
    // two strings differ and only a raw-body verifier can accept XerAds' bytes.
    expect($reencoded)->not->toBe($body);

    sendLegacy($this, $body, signature: hash_hmac('sha256', TestCase::v1Fixture()['timestamp'].'.'.$reencoded, TestCase::v1Fixture()['secret']))
        ->assertUnauthorized()
        ->assertJson(['ok' => false, 'error' => 'SIGNATURE_INVALID']);

    sendLegacy($this, $body)->assertCreated();
});

it('answers a replayed request with the stored response and stores nothing new', function () {
    $fixture = TestCase::v1Fixture();

    $first = sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature']);
    $second = sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature']);

    $first->assertCreated();
    $second->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and(Post::count())->toBe(1)
        ->and(DB::table('xerads_deliveries')->where('status', 'ok')->count())->toBe(1);
});

it('keeps receiving without replay protection when the ledger table is missing', function () {
    // A prefix with no tables behind it stands in for a site that has not
    // migrated yet. Dropping the real table would commit the test's
    // transaction on MySQL, where DDL is never transactional.
    config(['xerads.database.table_prefix' => 'not_migrated_']);
    $warnings = $this->captureWarnings();

    $fixture = TestCase::v1Fixture();

    $first = sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'])->assertCreated();
    $second = sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'])->assertCreated();

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('not_migrated_deliveries');

    // No ledger, so the copy is processed again. It lands on the same post,
    // matched by slug, and nothing is recorded anywhere.
    expect($second->json('id'))->toBe($first->json('id'))
        ->and(Post::count())->toBe(1)
        ->and(DB::table('xerads_deliveries')->count())->toBe(0);
});

it('signs and stores non-ASCII bodies byte for byte', function () {
    $body = legacyBody([
        'title' => 'Kafe “Ünïcode” — 東京 & résumé',
        'content' => '<p>Emoji 😀, NBSP '."\u{00A0}".', slash https://contoh.co.id/a/b?x=1&y=2</p>',
        'slug' => 'kafe-unicode',
        'cms_post_id' => null,
    ]);

    sendLegacy($this, $body)->assertCreated();

    expect(Post::sole()->title)->toBe('Kafe “Ünïcode” — 東京 & résumé');
});

it('refuses a tampered body and a stale timestamp', function () {
    $fixture = TestCase::v1Fixture();
    $tampered = str_replace('Panduan', 'Pandu4n', $fixture['body']);

    sendLegacy($this, $tampered, $fixture['timestamp'], $fixture['signature'])
        ->assertUnauthorized()
        ->assertJson(['ok' => false, 'error' => 'SIGNATURE_INVALID']);

    Carbon::setTestNow(Carbon::createFromTimestamp($fixture['timestamp'] + 301));

    sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'])
        ->assertUnauthorized()
        ->assertJson(['ok' => false, 'error' => 'TIMESTAMP_SKEW']);

    expect(Post::count())->toBe(0);
});

it('passes the connection test only when the receiver is configured', function () {
    sendLegacy($this, connectionTestBody())
        ->assertOk()
        ->assertExactJson(['ok' => true, 'test' => true]);

    // The test stores nothing, not even a ledger row.
    expect(Post::count())->toBe(0)
        ->and(DB::table('xerads_deliveries')->count())->toBe(0);
});

it('fails the connection test for a model that does not exist', function () {
    config(['xerads.content.mapped.model' => 'App\\Models\\DoesNotExist']);

    sendLegacy($this, connectionTestBody())
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'RECEIVER_MISCONFIGURED'])
        ->assertJsonPath('problems.0', fn (string $problem) => str_contains($problem, 'App\\Models\\DoesNotExist'));
});

it('fails the connection test for a mapped column that does not exist', function () {
    config(['xerads.content.mapped.fields.meta_description' => 'summary']);

    sendLegacy($this, connectionTestBody())
        ->assertUnprocessable()
        ->assertJson(['ok' => false, 'error' => 'RECEIVER_MISCONFIGURED'])
        ->assertJsonPath('problems.0', fn (string $problem) => str_contains($problem, 'posts.summary'));
});

it('warns about a NOT NULL column nothing fills, without failing the test', function () {
    config(['xerads.content.mapped.defaults' => []]);

    sendLegacy($this, connectionTestBody())
        ->assertOk()
        ->assertJson(['ok' => true, 'test' => true])
        ->assertJsonPath('warnings.0', fn (string $warning) => str_contains($warning, 'posts.user_id'));
});

it('fills a NOT NULL column from the configured defaults, and fails cleanly without them', function () {
    config(['xerads.content.mapped.defaults' => []]);

    sendLegacy($this, TestCase::v1Fixture()['body'])
        ->assertStatus(500)
        ->assertExactJson([
            'ok' => false,
            'error' => 'RECEIVER_FAILED',
            'message' => 'The article could not be stored. The site\'s log has the details.',
        ]);

    expect(Post::count())->toBe(0)
        ->and(DB::table('xerads_deliveries')->value('status'))->toBe('failed');

    // A failed delivery is retried, not answered from the ledger.
    config(['xerads.content.mapped.defaults' => ['user_id' => 9]]);

    sendLegacy($this, TestCase::v1Fixture()['body'])->assertCreated();

    expect((int) Post::sole()->user_id)->toBe(9);
});

it('maps pending and private to draft and reports no url for a draft', function (string $status) {
    $response = sendLegacy($this, legacyBody(['status' => $status]))->assertCreated();

    expect($response->json())->toBe(['id' => Post::sole()->id, 'url' => null])
        ->and(Post::sole()->status)->toBe('draft');
})->with(['draft', 'pending', 'private', 'something-else']);

it('updates instead of duplicating when XerAds retries without the id', function () {
    // A timeout after this site stored the post: XerAds never saw the id, so
    // the retry carries no cms_post_id, and it is signed afresh with a new
    // timestamp, so the ledger cannot recognise it either.
    $body = legacyBody(['cms_post_id' => null]);

    $first = sendLegacy($this, $body)->assertCreated();

    Carbon::setTestNow(Carbon::now()->addSeconds(45));

    $retry = sendLegacy($this, $body, timestamp: Carbon::now()->getTimestamp())->assertCreated();

    expect($retry->json())->toBe($first->json())
        ->and(Post::count())->toBe(1)
        ->and(DB::table('xerads_deliveries')->where('status', 'ok')->count())->toBe(2);
});

it('does not take over a post with the same slug when slug matching is off', function () {
    config(['xerads.legacy.match_existing_by_slug' => false]);

    $unrelated = Post::create([
        'user_id' => 7,
        'title' => 'An unrelated post',
        'slug' => 'panduan-kpr-2026-a1b2c3',
        'content' => '<p>Written by hand.</p>',
        'status' => 'published',
    ]);

    $response = sendLegacy($this, TestCase::v1Fixture()['body'])->assertCreated();

    expect($response->json('id'))->not->toBe($unrelated->id)
        ->and($response->json('url'))->toBe('http://localhost/posts/panduan-kpr-2026-a1b2c3-2')
        ->and($unrelated->fresh()->title)->toBe('An unrelated post')
        ->and(Post::count())->toBe(2);
});

it('adopts a post with the same slug, as the original receiver did', function () {
    $existing = Post::create([
        'user_id' => 7,
        'title' => 'Imported by hand',
        'slug' => 'panduan-kpr-2026-a1b2c3',
        'content' => '<p>Copied over.</p>',
        'status' => 'draft',
    ]);

    sendLegacy($this, TestCase::v1Fixture()['body'])
        ->assertCreated()
        ->assertJson(['id' => $existing->id, 'url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3']);

    expect(Post::count())->toBe(1)
        ->and($existing->fresh()->title)->toBe('Panduan KPR 2026 — Syarat & Bunga')
        // Defaults apply to new rows only.
        ->and((int) $existing->fresh()->user_id)->toBe(7);
});

it('updates the post it created when XerAds echoes the id back, keeping its image', function () {
    $first = sendLegacy($this, TestCase::v1Fixture()['body'])->assertCreated();
    $id = $first->json('id');

    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    sendLegacy($this, legacyBody([
        'title' => 'Panduan KPR 2026 (diperbarui)',
        'image_url' => null,
        'cms_post_id' => (string) $id,
    ]), timestamp: Carbon::now()->getTimestamp())
        ->assertCreated()
        ->assertJson(['id' => $id]);

    $post = Post::sole();

    expect($post->title)->toBe('Panduan KPR 2026 (diperbarui)')
        ->and($post->image_url)->toBe(TestCase::v1Fixture()['article']['image_url']);
});

it('builds the url from the slug that was saved', function () {
    Post::saving(function (Post $post) {
        $post->slug = $post->slug.'-site';
    });

    sendLegacy($this, TestCase::v1Fixture()['body'])
        ->assertCreated()
        ->assertJson(['url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3-site']);
});

it('builds the url from the model\'s own slug when no slug column is mapped', function () {
    config(['xerads.content.mapped.fields.slug' => null]);

    Post::creating(function (Post $post) {
        $post->slug = 'derived-'.Str::slug($post->title);
    });

    sendLegacy($this, TestCase::v1Fixture()['body'])
        ->assertCreated()
        ->assertJson(['url' => 'http://localhost/posts/derived-panduan-kpr-2026-syarat-bunga']);
});

it('falls back to the slug XerAds sent for a model without one', function () {
    config([
        'xerads.content.mapped.model' => Note::class,
        'xerads.content.mapped.fields' => ['title' => 'title', 'content' => 'body', 'status' => 'status'],
        'xerads.content.mapped.defaults' => [],
    ]);

    sendLegacy($this, TestCase::v1Fixture()['body'])
        ->assertCreated()
        ->assertJson(['url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3']);

    expect(Note::sole()->body)->toBe(TestCase::v1Fixture()['article']['content']);
});

it('restores a soft-deleted post XerAds sends again by id', function () {
    config(['xerads.content.mapped.model' => SoftDeletingPost::class]);

    $first = sendLegacy($this, TestCase::v1Fixture()['body'])->assertCreated();
    $id = $first->json('id');

    SoftDeletingPost::findOrFail($id)->delete();
    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    sendLegacy($this, legacyBody(['cms_post_id' => (string) $id]), timestamp: Carbon::now()->getTimestamp())
        ->assertCreated()
        ->assertExactJson(['id' => $id, 'url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3']);

    expect(SoftDeletingPost::withTrashed()->count())->toBe(1)
        ->and(SoftDeletingPost::findOrFail($id)->trashed())->toBeFalse();
});

it('restores a soft-deleted post it finds by slug', function () {
    config(['xerads.content.mapped.model' => SoftDeletingPost::class]);

    $trashed = SoftDeletingPost::create([
        'user_id' => 7,
        'title' => 'Old copy',
        'slug' => 'panduan-kpr-2026-a1b2c3',
        'content' => '<p>Old.</p>',
        'status' => 'draft',
    ]);
    $trashed->delete();

    sendLegacy($this, legacyBody(['cms_post_id' => null]))
        ->assertCreated()
        ->assertJson(['id' => $trashed->id]);

    expect(SoftDeletingPost::withTrashed()->count())->toBe(1)
        ->and($trashed->fresh()->trashed())->toBeFalse()
        ->and($trashed->fresh()->title)->toBe('Panduan KPR 2026 — Syarat & Bunga');
});

it('ignores the query string and the content type, which the signature does not cover', function () {
    $victim = Post::create([
        'user_id' => 7,
        'title' => 'A post nobody should touch',
        'slug' => 'victim',
        'content' => '<p>Original.</p>',
        'status' => 'published',
    ]);

    // Captured bytes and headers, replayed as text/plain with a query string
    // that names the victim and carries new content.
    $fixture = TestCase::v1Fixture();
    $query = http_build_query(['title' => 'Hacked', 'content' => '<script>alert(1)</script>', 'cms_post_id' => $victim->id]);

    $response = sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'], uri: LEGACY_URI.'?'.$query, contentType: 'text/plain')
        ->assertCreated();

    expect($response->json('id'))->not->toBe($victim->id)
        ->and($victim->fresh()->title)->toBe('A post nobody should touch')
        ->and($victim->fresh()->content)->toBe('<p>Original.</p>')
        ->and(Post::where('content', 'like', '%<script>%')->exists())->toBeFalse()
        ->and(Post::findOrFail($response->json('id'))->title)->toBe('Panduan KPR 2026 — Syarat & Bunga');
});

it('does not let ?test=1 turn a real delivery into a no-op', function () {
    $fixture = TestCase::v1Fixture();

    sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'], uri: LEGACY_URI.'?test=1')
        ->assertCreated()
        ->assertJsonMissingPath('test');

    expect(Post::count())->toBe(1);
});

it('refuses a signed body that is not a JSON object', function (string $body) {
    sendLegacy($this, $body)
        ->assertStatus(400)
        ->assertExactJson(['ok' => false, 'error' => 'INVALID_PAYLOAD', 'message' => 'The request body must be a JSON object.']);

    expect(Post::count())->toBe(0);
})->with([
    'a list' => ['[{"title":"x","content":"y"}]'],
    'a string' => ['"title=x&content=y"'],
    'form encoding' => ['title=x&content=y'],
    'truncated JSON' => ['{"title":"x","content":'],
    'empty' => [''],
]);

it('keeps an HTTP error a receiver raises on purpose, and records it', function (Closure $failure, int $status) {
    app()->bind(ArticleReceiver::class, fn () => new class($failure) implements ArticleReceiver
    {
        public function __construct(private Closure $failure) {}

        public function receive(IncomingArticle $article): array
        {
            ($this->failure)();

            return ['id' => null, 'url' => null];
        }
    });

    sendLegacy($this, TestCase::v1Fixture()['body'])->assertStatus($status);

    expect(DB::table('xerads_deliveries')->first())
        ->status->toBe('failed')
        ->response_code->toEqual($status);
})->with([
    // Passed as closures (the parameter is typed Closure, so Pest hands them
    // over unresolved) and run inside the receiver.
    'abort' => [fn () => abort(403, 'Not on this site.'), 403],
    'validation' => [fn () => throw ValidationException::withMessages(['title' => 'Too long.']), 422],
    'prepared response' => [fn () => throw new HttpResponseException(response()->json(['nope' => true], 409)), 409],
]);

it('checks the configuration of the shipped receiver only, unless a subclass opts in', function () {
    config(['xerads.content.mapped.model' => 'App\\Models\\DoesNotExist']);

    // A subclass may write somewhere else entirely; the inherited check of
    // the configured model would be a false alarm.
    app()->bind(ArticleReceiver::class, fn () => new class extends EloquentArticleReceiver {});

    sendLegacy($this, connectionTestBody())->assertOk()->assertExactJson(['ok' => true, 'test' => true]);

    app()->bind(ArticleReceiver::class, fn () => new class extends EloquentArticleReceiver implements ValidatesConfiguration {});

    sendLegacy($this, connectionTestBody())->assertUnprocessable()->assertJson(['error' => 'RECEIVER_MISCONFIGURED']);
});

it('answers 503 when the secret is emptied after the route was registered', function () {
    config(['xerads.legacy.secret' => '']);

    sendLegacy($this, TestCase::v1Fixture()['body'])
        ->assertStatus(503)
        ->assertJson(['ok' => false, 'error' => 'NOT_CONFIGURED']);

    expect(Post::count())->toBe(0);
});

it('registers no route at all without a legacy secret', function () {
    $this->rebootWith(['xerads.legacy.secret' => '']);

    expect(Route::has('xerads.articles.receive'))->toBeFalse();

    sendLegacy($this, TestCase::v1Fixture()['body'])->assertNotFound();
});

it('registers the route under the configured path and name', function () {
    expect(Route::has('xerads.articles.receive'))->toBeTrue()
        ->and(route('xerads.articles.receive', absolute: false))->toBe(LEGACY_URI);
});

describe('installs configured the original way', function () {
    it('works from XERADS_CMS_* environment variables alone', function () {
        $this->rebootAsInstall([
            'XERADS_CMS_SECRET' => TestCase::v1Fixture()['secret'],
            'XERADS_CMS_MODEL' => Post::class,
            'XERADS_CMS_PUBLIC_ROUTE' => 'posts.show',
        ], [
            // As if config/xerads.php were published with another mode; the
            // legacy variables still select mapped mode.
            'xerads.content.mode' => 'turnkey',
            'xerads.content.mapped.defaults' => ['user_id' => 3],
        ]);

        expect(Route::has('xerads.articles.receive'))->toBeTrue()
            ->and(config('xerads.content.mode'))->toBe('mapped')
            // Mirrored for code that still reads the original keys.
            ->and(config('xerads-cms.secret'))->toBe(TestCase::v1Fixture()['secret'])
            ->and(config('xerads-cms.model'))->toBe(Post::class)
            ->and(config('xerads-cms.public_route'))->toBe('posts.show');

        $fixture = TestCase::v1Fixture();

        sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'])
            ->assertCreated()
            ->assertJson(['url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3']);

        expect(Post::sole())
            ->title->toBe('Panduan KPR 2026 — Syarat & Bunga')
            ->status->toBe('published')
            ->user_id->toEqual(3);
    });

    it('works from a published config/xerads-cms.php', function () {
        $this->rebootAsInstall([], [
            'xerads-cms' => [
                'secret' => TestCase::v1Fixture()['secret'],
                'route' => '/hooks/xerads',
                'middleware' => ['api'],
                'timestamp_tolerance' => 300,
                'model' => Post::class,
                // The complete map: fields it leaves out are not written.
                'fields' => ['title' => 'title', 'content' => 'content', 'slug' => 'slug', 'status' => 'status'],
                'status_map' => ['draft' => 'draft', 'publish' => 'live'],
                'public_route' => 'posts.show',
                'public_route_parameter' => 'slug',
            ],
            'xerads.content.mode' => 'off',
            'xerads.content.mapped.defaults' => ['user_id' => 5],
        ]);

        expect(route('xerads.articles.receive', absolute: false))->toBe('/hooks/xerads')
            ->and(config('xerads.content.mode'))->toBe('mapped')
            ->and(config('xerads.content.mapped.fields.meta_description'))->toBeNull();

        $fixture = TestCase::v1Fixture();

        sendLegacy($this, $fixture['body'], $fixture['timestamp'], $fixture['signature'], uri: '/hooks/xerads')
            ->assertCreated()
            ->assertJson(['url' => 'http://localhost/posts/panduan-kpr-2026-a1b2c3']);

        expect(Post::sole())
            ->status->toBe('live')
            ->meta_description->toBeNull()
            ->image_url->toBeNull()
            ->keywords->toBeNull()
            ->user_id->toEqual(5);
    });
});
