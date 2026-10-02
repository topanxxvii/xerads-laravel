<?php

/**
 * The paired webhook, driven with the contract's own article-upsert.json,
 * re-signed with the test site's key exactly as XerAds signs it.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\Post;
use XerAds\Laravel\Content\Events\ArticleDeleted;
use XerAds\Laravel\Content\Events\ArticlePublished;
use XerAds\Laravel\Content\Events\ArticleReceived;
use XerAds\Laravel\Content\Events\ArticleUnpublished;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Version;

const XERADS_ID = '01JA9Z5K7M3X8Q2W4E6R1T0Y9U';

beforeEach(function () {
    config(['xerads.credentials.key' => testSiteKey()]);
});

/** @return array<string, mixed> */
function removalEnvelope(string $event, int $sequence, ?string $remoteId = null, string $xeradsId = XERADS_ID): array
{
    return envelopeFor($event, ['article' => ['xerads_id' => $xeradsId, 'remote_id' => $remoteId, 'slug' => 'panduan-kpr-2026', 'public_url' => null], 'redirect_to' => null], $sequence);
}

it('creates the article, then updates it in place', function () {
    $created = deliver($this, upsertEnvelope())->assertCreated();
    $post = Post::sole();

    expect($created->json())->toBe([
        'ok' => true,
        'id' => (string) $post->id,
        'url' => 'http://localhost/posts/panduan-kpr-2026',
        'preview_url' => null,
        'state' => 'published',
        'sequence' => 1842,
        'revision' => upsertFixture()['data']['article']['revision'],
        'created' => true,
        'duplicate' => false,
        'plugin' => ['version' => Version::VERSION, 'contract' => 2],
    ]);

    expect($post->title)->toBe('Panduan KPR 2026')
        ->and($post->status)->toBe('published')
        ->and($post->meta_description)->toBe('Syarat, bunga dan simulasi KPR 2026.')
        ->and($post->image_url)->toBe('https://cdn.xerads.test/articles/panduan-kpr-2026-1.png')
        ->and($post->keywords)->toBe('kpr, bunga kpr')
        ->and($post->content)->toContain('<div data-xerads-widget="w_k3v9q2m8x1c4b7na" data-lang="id" style="min-height:2320px"></div>')
        ->and($post->content)->not->toContain('xerads_widget');

    $updated = deliver($this, upsertEnvelope(
        ['title' => 'Panduan KPR 2026 (diperbarui)', 'revision' => 'sha256:changed'],
        ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W0Y3J', 'sequence' => 1843],
    ))->assertOk();

    expect($updated->json('id'))->toBe((string) $post->id)
        ->and($updated->json('created'))->toBeFalse()
        ->and(Post::count())->toBe(1)
        ->and($post->fresh()->title)->toBe('Panduan KPR 2026 (diperbarui)');

    $entry = ContentMapEntry::sole();

    expect($entry->only(['xerads_id', 'model_type', 'model_id', 'remote_id', 'sequence', 'revision', 'state', 'last_url']))->toBe([
        'xerads_id' => XERADS_ID,
        'model_type' => (new Post)->getMorphClass(),
        'model_id' => (string) $post->id,
        'remote_id' => (string) $post->id,
        'sequence' => 1843,
        'revision' => 'sha256:changed',
        'state' => 'published',
        'last_url' => 'http://localhost/posts/panduan-kpr-2026',
    ]);
});

it('answers a repeated delivery with the stored reply, marked as a duplicate', function () {
    $first = deliver($this, upsertEnvelope())->assertCreated();
    $second = deliver($this, upsertEnvelope())->assertCreated();

    // Equal, not identical: MySQL normalises the key order of a stored JSON
    // document, and XerAds reads the reply by key anyway.
    expect($second->json())->toEqual(array_merge($first->json(), ['duplicate' => true]))
        ->and(Post::count())->toBe(1);
});

it('drops an event older than the one it holds', function () {
    deliver($this, upsertEnvelope())->assertCreated();

    $stale = deliver($this, upsertEnvelope(
        ['title' => 'Versi lama', 'revision' => 'sha256:older'],
        ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W0Y1A', 'sequence' => 1841],
    ))->assertOk();

    expect($stale->json())->toMatchArray(['ok' => true, 'stale' => true, 'state' => 'published', 'sequence' => 1842])
        ->and(Post::sole()->title)->toBe('Panduan KPR 2026');
});

it('applies only the status when the revision is unchanged', function () {
    deliver($this, upsertEnvelope())->assertCreated();

    Post::query()->update(['content' => '<p>Edited on the site.</p>']);

    $reply = deliver($this, upsertEnvelope(['status' => 'draft'], ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W0Y4K', 'sequence' => 1843]))->assertOk();

    expect($reply->json())->toMatchArray(['state' => 'draft', 'url' => null])
        ->and(Post::sole()->status)->toBe('draft')
        ->and(Post::sole()->content)->toBe('<p>Edited on the site.</p>');
});

it('tells a concurrent copy to retry, and reprocesses an expired or failed claim', function (string $status, ?int $leaseSeconds, int $expected) {
    DB::table('xerads_deliveries')->insert([
        'delivery_id' => upsertFixture()['delivery_id'],
        'event' => 'article.upsert',
        'status' => $status,
        'claim_token' => 'held-by-another-worker',
        'processing_until' => $leaseSeconds !== null ? Carbon::now()->addSeconds($leaseSeconds) : null,
        'received_at' => Carbon::now(),
    ]);

    $response = deliver($this, upsertEnvelope())->assertStatus($expected);

    if ($expected === 409) {
        expect($response->json('error'))->toBe('DELIVERY_IN_PROGRESS')
            ->and(Post::count())->toBe(0);
    } else {
        expect(Post::count())->toBe(1);
    }
})->with([
    'in progress' => ['processing', 60, 409],
    'expired claim' => ['processing', -1, 201],
    'failed before' => ['failed', null, 201],
]);

it('refuses another contract', function () {
    deliver($this, upsertEnvelope(), ['X-XerAds-Contract' => '3'])
        ->assertStatus(400)
        ->assertExactJson(['ok' => false, 'error' => 'CONTRACT_UNSUPPORTED', 'message' => 'This site speaks contract 2.', 'supported' => [2]]);

    deliver($this, upsertEnvelope(envelope: ['contract' => 3]))
        ->assertStatus(400)
        ->assertJson(['error' => 'CONTRACT_UNSUPPORTED', 'supported' => [2]]);
});

it('refuses a delivery for another site, an unknown key, a bad signature and a stale timestamp', function (array $headers, string $secret, string $keyId, string $error) {
    deliver($this, upsertEnvelope(), $headers, $secret, $keyId)
        ->assertUnauthorized()
        ->assertJson(['ok' => false, 'error' => $error]);

    expect(Post::count())->toBe(0);
})->with([
    'another site' => [['X-XerAds-Site' => 'site_00000000000000000000000000'], TEST_SECRET, TEST_KEY_ID, 'SITE_MISMATCH'],
    'no site' => [['X-XerAds-Site' => ''], TEST_SECRET, TEST_KEY_ID, 'SITE_MISMATCH'],
    'unknown key' => [[], TEST_SECRET, 'sk_00000000000000000000000000', 'KEY_UNKNOWN'],
    'wrong secret' => [[], TEST_OTHER_SECRET, TEST_KEY_ID, 'SIGNATURE_INVALID'],
    'stale timestamp' => [['X-XerAds-Timestamp' => '1790000000'], TEST_SECRET, TEST_KEY_ID, 'TIMESTAMP_SKEW'],
]);

it('accepts the previous key during a rotation', function () {
    config([
        'xerads.credentials.key' => testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET),
        'xerads.credentials.previous' => testSiteKey(),
    ]);

    deliver($this, upsertEnvelope())->assertCreated();
});

it('refuses headers that disagree with the signed envelope', function () {
    deliver($this, upsertEnvelope(), ['X-XerAds-Event' => 'article.delete'])
        ->assertStatus(400)
        ->assertJson(['ok' => false, 'error' => 'INVALID_PAYLOAD']);
});

it('answers 503 on a site that is not paired', function () {
    config(['xerads.credentials.key' => null]);

    deliver($this, upsertEnvelope())
        ->assertStatus(503)
        ->assertJson(['ok' => false, 'error' => 'NOT_CONFIGURED']);
});

it('refuses a body over the size limit before reading it further', function () {
    $body = json_encode(upsertEnvelope(['content' => ['html' => str_repeat('a', 2 * 1024 * 1024)]]));

    deliver($this, (string) $body)
        ->assertStatus(413)
        ->assertJson(['ok' => false, 'error' => 'PAYLOAD_TOO_LARGE']);
});

it('reports itself on ping', function () {
    $reply = deliver($this, envelopeFor('ping'))->assertOk();

    expect(array_keys($reply->json()))->toBe(['ok', 'plugin', 'platform', 'version', 'contract', 'mode', 'events', 'features', 'php', 'laravel', 'time', 'checks'])
        ->and($reply->json())->toMatchArray([
            'ok' => true,
            'plugin' => 'xerads',
            'platform' => 'laravel',
            'version' => Version::VERSION,
            'contract' => 2,
            'mode' => 'mapped',
            'events' => ['ping', 'article.upsert', 'article.unpublish', 'article.delete', 'settings.updated', 'redirects.updated', 'site.revoked'],
            'features' => ['articles', 'widgets'],
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ])
        ->and(array_keys($reply->json('checks')))->toBe(['receiver', 'storage_link', 'queue', 'app_url_https', 'robots_static_file', 'sitemap_static_file'])
        ->and($reply->json('checks.receiver'))->toBe([]);
});

it('reports receiver problems on ping', function () {
    config(['xerads.content.mapped.model' => 'App\\Models\\Missing']);

    expect(deliver($this, envelopeFor('ping'))->json('checks.receiver.0'))->toContain('App\\Models\\Missing');
});

it('unpublishes, and deletes per on_delete', function (string $onDelete, string $event, string $state) {
    config(['xerads.content.mapped.on_delete' => $onDelete]);
    deliver($this, upsertEnvelope())->assertCreated();
    $id = Post::sole()->id;

    $reply = deliver($this, removalEnvelope($event, 1843))->assertOk();

    expect($reply->json())->toMatchArray(['ok' => true, 'id' => (string) $id, 'url' => null, 'state' => $state, 'sequence' => 1843])
        ->and(ContentMapEntry::sole()->state)->toBe($state);

    $state === 'deleted'
        ? expect(Post::count())->toBe(0)
        : expect(Post::sole()->status)->toBe('draft');
})->with([
    'unpublish' => ['unpublish', 'article.unpublish', 'unpublished'],
    'delete, kept as a draft' => ['unpublish', 'article.delete', 'unpublished'],
    'delete, deleted' => ['delete', 'article.delete', 'deleted'],
]);

it('answers an unknown article\'s removal with 200 unknown', function (string $event) {
    deliver($this, removalEnvelope($event, 5))
        ->assertOk()
        ->assertJson(['ok' => true, 'state' => 'unknown']);
})->with(['article.unpublish', 'article.delete']);

it('tells listeners after the article is stored', function () {
    Event::fake([ArticleReceived::class, ArticlePublished::class, ArticleUnpublished::class, ArticleDeleted::class]);

    deliver($this, upsertEnvelope())->assertCreated();

    Event::assertDispatched(ArticleReceived::class, fn (ArticleReceived $event) => $event->article->xeradsId === XERADS_ID && $event->receipt->created);
    Event::assertDispatched(ArticlePublished::class);

    deliver($this, removalEnvelope('article.unpublish', 1843))->assertOk();

    Event::assertDispatched(ArticleUnpublished::class, fn (ArticleUnpublished $event) => $event->xeradsId === XERADS_ID);
    Event::assertNotDispatched(ArticleDeleted::class);
    // Published once: an update of a published article is not a new publish.
    Event::assertDispatchedTimes(ArticlePublished::class, 1);
});

it('records new settings and redirects versions', function () {
    deliver($this, envelopeFor('settings.updated', ['version' => 12]))->assertOk()->assertJson(['ok' => true, 'version' => 12]);
    deliver($this, envelopeFor('redirects.updated', ['version' => 3]))->assertOk();

    expect(DB::table('xerads_state')->where('key', 'settings_version')->value('value'))->toBe('12')
        ->and(DB::table('xerads_state')->where('key', 'redirects_version')->value('value'))->toBe('3');
});

it('forgets its key when XerAds revokes the site', function () {
    config(['xerads.credentials.key' => null]);
    app(CredentialsResolver::class)->store(Credentials::parse(testSiteKey()));

    deliver($this, envelopeFor('site.revoked'))->assertOk()->assertExactJson(['ok' => true, 'state' => 'revoked']);

    app()->forgetScopedInstances();

    expect(app(CredentialsResolver::class)->current())->toBeNull();

    deliver($this, envelopeFor('ping'))->assertStatus(503);
});

it('refuses events it does not take', function () {
    deliver($this, envelopeFor('article.archive'))
        ->assertStatus(400)
        ->assertJson(['ok' => false, 'error' => 'EVENT_UNSUPPORTED']);

    config(['xerads.content.mode' => 'off']);

    deliver($this, upsertEnvelope())
        ->assertStatus(400)
        ->assertJson(['ok' => false, 'error' => 'EVENT_UNSUPPORTED']);
});

it('refuses an upsert without an article id', function () {
    deliver($this, upsertEnvelope(['xerads_id' => null]))
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'INVALID_PAYLOAD']);
});

it('answers 422 in turnkey mode until it exists', function () {
    config(['xerads.content.mode' => 'turnkey']);

    deliver($this, upsertEnvelope())
        ->assertStatus(422)
        ->assertJson(['ok' => false, 'error' => 'RECEIVER_MISCONFIGURED'])
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'later release'));

    // Not remembered as done: the same delivery is processed again once fixed.
    expect(DB::table('xerads_deliveries')->value('status'))->toBe('failed');
});

it('runs outside the web middleware group', function () {
    $response = deliver($this, envelopeFor('ping'))->assertOk();

    // No session started, no CSRF check: nothing set a cookie.
    expect($response->headers->getCookies())->toBe([]);
});

it('still succeeds when a listener fails after the article is stored', function () {
    Event::listen(ArticlePublished::class, fn () => throw new RuntimeException('Mail server down.'));

    deliver($this, upsertEnvelope())
        ->assertCreated()
        ->assertJson(['ok' => true, 'state' => 'published', 'url' => 'http://localhost/posts/panduan-kpr-2026']);

    expect(DB::table('xerads_deliveries')->value('status'))->toBe('ok');
});

it('answers a retry of the delivery it last applied as a duplicate, not as stale', function () {
    deliver($this, upsertEnvelope())->assertCreated();
    $post = Post::sole();

    // The reply never reached XerAds and the ledger has no success for it
    // (it failed after the commit), so XerAds sends the same delivery again.
    DB::table('xerads_deliveries')->update(['status' => 'failed']);

    $retry = deliver($this, upsertEnvelope())->assertOk();

    expect($retry->json())->toMatchArray([
        'ok' => true,
        'id' => (string) $post->id,
        'url' => 'http://localhost/posts/panduan-kpr-2026',
        'state' => 'published',
        'sequence' => 1842,
        'duplicate' => true,
    ])->and($retry->json())->not->toHaveKey('stale');
});

it('orders events per XerAds site, so a site added again starts afresh', function () {
    deliver($this, upsertEnvelope())->assertCreated();

    $newSite = 'site_01jc000000000000000000000z';
    config(['xerads.credentials.key' => testSiteKey(siteId: $newSite)]);
    // A new request, as the next delivery would be: the key is read afresh.
    app()->forgetScopedInstances();

    deliver($this, upsertEnvelope(['title' => 'Dari situs baru', 'revision' => 'sha256:new-site'], [
        'site_id' => $newSite,
        'delivery_id' => '01JC0000000000000000000001',
        'sequence' => 1,
    ]))->assertOk()->assertJson(['state' => 'published', 'sequence' => 1]);

    expect(Post::sole()->title)->toBe('Dari situs baru')
        ->and(ContentMapEntry::sole()->site_id)->toBe($newSite);
});

it('records a removal of an article it does not have, so an older upsert cannot bring it back', function () {
    deliver($this, removalEnvelope('article.delete', 12))->assertOk()->assertJson(['state' => 'unknown']);

    expect(ContentMapEntry::sole()->only(['state', 'sequence']))->toBe(['state' => 'deleted', 'sequence' => 12]);

    deliver($this, upsertEnvelope(envelope: ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W0Y11', 'sequence' => 11]))
        ->assertOk()
        ->assertJson(['stale' => true]);

    expect(Post::count())->toBe(0);

    deliver($this, upsertEnvelope(['remote_id' => null], ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W0Y13', 'sequence' => 13]))
        ->assertCreated();

    expect(Post::count())->toBe(1);
});
