<?php

/**
 * IndexNow: the key file, one submission per burst of changes, only from
 * production and only for an https site, and the outcome in the heartbeat.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use XerAds\Laravel\Seo\ContentChanges;
use XerAds\Laravel\Seo\IndexNow\IndexNowQueue;
use XerAds\Laravel\Seo\IndexNow\IndexNowSubmitter;
use XerAds\Laravel\Seo\IndexNow\Jobs\SubmitIndexNow;
use XerAds\Laravel\Sync\RemoteState;

const INDEXNOW_KEY = 'abababababababababababababababab';

function inProductionForIndexNow(): void
{
    app()->detectEnvironment(fn () => 'production');
}

/** IndexNow's endpoint, answering `$status`. */
function fakeIndexNow(int $status = 202): void
{
    fakeXerads();
    Http::fake(['https://api.indexnow.org/*' => Http::response('', $status)]);
}

/** @return list<Request> */
function indexNowSubmissions(): array
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://api.indexnow.org/'))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

/** Run the queued submission the way a worker would. */
function runSubmission(): void
{
    app()->forgetScopedInstances();
    (new SubmitIndexNow)->handle(app(IndexNowQueue::class), app(IndexNowSubmitter::class));
}

function changed(string ...$urls): void
{
    app()->forgetScopedInstances();
    app(ContentChanges::class)->record(...$urls);
}

function indexNowState(): array
{
    app()->forgetScopedInstances();

    return app(RemoteState::class)->get('indexnow_last');
}

beforeEach(function () {
    holdSettings(['site' => ['url' => 'https://toko.test']]);
});

it('serves the key from the settings, and nothing for any other name', function () {
    $this->get('/'.INDEXNOW_KEY.'.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent(INDEXNOW_KEY);

    $this->get('/'.str_repeat('cd', 16).'.txt')->assertNotFound();
    $this->get('/'.strtoupper(INDEXNOW_KEY).'.txt')->assertNotFound();
});

it('falls back to the key pairing received', function () {
    holdSettings(['indexnow' => ['key' => null]]);
    app(RemoteState::class)->put('indexnow_key', str_repeat('cd', 16));

    $this->get('/'.str_repeat('cd', 16).'.txt')->assertOk()->assertContent(str_repeat('cd', 16));
    $this->get('/'.INDEXNOW_KEY.'.txt')->assertNotFound();
});

it('serves no key while IndexNow is off', function () {
    holdSettings(['indexnow' => ['enabled' => false]]);

    $this->get('/'.INDEXNOW_KEY.'.txt')->assertNotFound();
});

it('submits a burst of changes once, after the debounce', function () {
    inProductionForIndexNow();
    fakeIndexNow();
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('https://toko.test/blog/satu');
    changed('https://toko.test/blog/dua', 'https://toko.test/blog/satu');

    // One job for the burst, held back for the debounce window.
    Queue::assertPushed(SubmitIndexNow::class, 1);
    Queue::assertPushed(SubmitIndexNow::class, fn (SubmitIndexNow $job) => $job->delay === 60);

    runSubmission();

    $sent = indexNowSubmissions();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->method())->toBe('POST')
        ->and($sent[0]->data())->toBe([
            'host' => 'toko.test',
            'key' => INDEXNOW_KEY,
            'keyLocation' => 'https://toko.test/'.INDEXNOW_KEY.'.txt',
            'urlList' => ['https://toko.test/blog/satu', 'https://toko.test/blog/dua'],
        ])
        ->and(indexNowState())->toMatchArray(['last_status' => 202, 'last_error' => null]);

    // The queue is empty again and the next change opens a new window.
    runSubmission();
    expect(indexNowSubmissions())->toHaveCount(1);

    changed('https://toko.test/blog/tiga');
    Queue::assertPushed(SubmitIndexNow::class, 2);
});

it('submits only addresses on the site\'s own host, on its own address', function () {
    inProductionForIndexNow();
    fakeIndexNow(200);
    Queue::fake();
    config(['queue.default' => 'redis']);

    // A request's host (app.url here) is another name for the site.
    changed('http://localhost/blog/satu', 'https://elsewhere.example/blog/dua');
    runSubmission();

    expect(indexNowSubmissions()[0]->data()['urlList'])->toBe(['https://toko.test/blog/satu']);
});

it('submits nothing outside production', function () {
    fakeIndexNow();
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('https://toko.test/blog/satu');

    Queue::assertNothingPushed();

    runSubmission();

    expect(indexNowSubmissions())->toBe([])
        ->and(indexNowState())->toBe([]);
});

it('submits for any environment the site lists', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['xerads.indexnow.environments' => ['production', 'staging']]);
    fakeIndexNow();
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('https://toko.test/blog/satu');
    Queue::assertPushed(SubmitIndexNow::class, 1);
});

it('submits nothing for a site that is not on https, and says why', function () {
    inProductionForIndexNow();
    holdSettings(['site' => ['url' => 'http://toko.test']]);
    fakeIndexNow();
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('http://toko.test/blog/satu');
    runSubmission();

    expect(indexNowSubmissions())->toBe([])
        ->and(indexNowState())->toMatchArray(['last_status' => null, 'last_error' => 'The site\'s address is not https; IndexNow is not used.']);
});

it('submits nothing while the settings turn IndexNow off', function () {
    inProductionForIndexNow();
    holdSettings(['indexnow' => ['enabled' => false]]);
    fakeIndexNow();
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('https://toko.test/blog/satu');
    runSubmission();

    Queue::assertNothingPushed();
    expect(indexNowSubmissions())->toBe([]);
});

it('refuses an endpoint on a private address', function () {
    inProductionForIndexNow();
    config(['xerads.indexnow.endpoint' => 'https://127.0.0.1/indexnow']);
    fakeIndexNow();
    Http::fake(['https://127.0.0.1/*' => Http::response('', 202)]);
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('https://toko.test/blog/satu');
    runSubmission();

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), '127.0.0.1'))->count())->toBe(0)
        ->and(indexNowState()['last_error'])->toStartWith('The IndexNow endpoint was refused');
});

it('submits after the response when there is no queue worker', function () {
    inProductionForIndexNow();
    fakeIndexNow();
    config(['xerads.credentials.key' => testSiteKey()]);

    deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertSuccessful();

    $sent = indexNowSubmissions();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->data()['urlList'])->toBe(['https://toko.test/posts/panduan-kpr-2026']);
});

it('reports the last submission in the heartbeat', function (int $status, ?string $error) {
    inProductionForIndexNow();
    fakeIndexNow($status);
    Queue::fake();
    config(['queue.default' => 'redis']);

    changed('https://toko.test/blog/satu');
    runSubmission();

    app()->forgetScopedInstances();
    $this->artisan('xerads:sync', ['--heartbeat' => true])->assertSuccessful();

    $heartbeat = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/heartbeat'))->first()[0];

    expect($heartbeat->data()['indexnow'])->toMatchArray(['last_status' => $status, 'last_error' => $error])
        ->and($heartbeat->data()['indexnow']['last_submitted_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
})->with([
    'accepted' => [202, null],
    'refused' => [429, 'IndexNow answered 429.'],
]);

it('forgets the last submission when the site is paired as another one', function () {
    inProductionForIndexNow();
    fakeIndexNow(202);
    Queue::fake();
    config(['queue.default' => 'redis']);
    // A first sync records which site this state is kept for.
    $this->artisan('xerads:sync', ['--heartbeat' => true])->assertSuccessful();

    changed('https://toko.test/blog/satu');
    runSubmission();
    expect(indexNowState())->not->toBe([]);

    // XERADS_SITE_KEY now names another site; its first sync starts afresh.
    config(['xerads.credentials.key' => testSiteKey(siteId: 'site_01jb2n0a1b2c3d4e5f6g7h8j9k')]);
    app()->forgetScopedInstances();
    $this->artisan('xerads:sync', ['--heartbeat' => true])->assertSuccessful();

    $heartbeat = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/heartbeat'))->last()[0];

    expect($heartbeat->data()['indexnow'])->toBeNull()
        ->and(indexNowState())->toBe([]);
});

it('reports no submission in the heartbeat before the first one', function () {
    fakeXerads();

    $this->artisan('xerads:sync', ['--heartbeat' => true])->assertSuccessful();

    $heartbeat = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/heartbeat'))->first()[0];

    expect($heartbeat->data())->toHaveKey('indexnow')
        ->and($heartbeat->data()['indexnow'])->toBeNull();
});
