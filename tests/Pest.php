<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Psr\Http\Message\RequestInterface;
use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Content\Pipeline\ContentDocument;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Sync\Client\PairingClientFactory;
use XerAds\Laravel\Sync\RemoteState;
use XerAds\Laravel\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit', 'Feature');

/*
 * Test site keys. The secret is the one in the v2 signature vectors, so a key
 * built from these verifies the fixture signatures.
 */
const TEST_SITE_ID = 'site_01jb2m8q4z7x3k9v5t1r6w0y2h';
const TEST_KEY_ID = 'sk_01jb2m8q4z7x3k9v5t1r6w0y2h';
const TEST_SECRET = 'q8xN3vV0b2cY5mT9wR1uE7aL4kZ6sD0fH2jG8pX3nB5';
const TEST_OTHER_KEY_ID = 'sk_01jb2n0a1b2c3d4e5f6g7h8j9k';
const TEST_OTHER_SECRET = 'Zr7_kLm2-Qp9Ws4Xc1Vb8Nh3Jt6Yu0Ig5Oa';

function testSiteKey(string $keyId = TEST_KEY_ID, string $secret = TEST_SECRET, string $siteId = TEST_SITE_ID): string
{
    return 'xsk_'.$siteId.'.'.$keyId.'.'.$secret;
}

/**
 * Run one pipeline step (or several, in order) over some HTML.
 *
 * @param  class-string<PipelineStep>|list<class-string<PipelineStep>>  $steps
 * @param  array<string, int>  $heights
 */
function runSteps(string|array $steps, string $html, ?string $language = 'id', array $heights = [], ?string $excerpt = null): ContentDocument
{
    $document = new ContentDocument($html, $language, $heights, $excerpt);

    foreach ((array) $steps as $step) {
        app($step)->process($document);
    }

    return $document;
}

/** @return array<string, mixed> */
function widgetEmbeds(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/contract/v2/widget-embeds.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function upsertFixture(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/contract/v2/article-upsert.json'), true, flags: JSON_THROW_ON_ERROR);
}

const TEST_WIDGET_ID = 'w_k3v9q2m8x1c4b7na';

/**
 * The upsert fixture as an envelope, with fields replaced.
 *
 * @param  array<string, mixed>  $article  merged into data.article
 * @param  array<string, mixed>  $envelope  merged into the envelope
 * @return array<string, mixed>
 */
function upsertEnvelope(array $article = [], array $envelope = []): array
{
    $fixture = upsertFixture();
    $fixture['data']['article'] = array_merge($fixture['data']['article'], $article);

    return array_merge($fixture, $envelope);
}

/**
 * An envelope for any event, addressed to the test site.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function envelopeFor(string $event, array $data = [], int $sequence = 1, ?string $deliveryId = null): array
{
    return [
        'contract' => 2,
        'event' => $event,
        'delivery_id' => $deliveryId ?? (string) Str::ulid(),
        'occurred_at' => '2026-10-02T08:00:00Z',
        'site_id' => TEST_SITE_ID,
        'sequence' => $sequence,
        'data' => $data === [] ? new stdClass : $data,
    ];
}

/**
 * POST a delivery to the webhook the way XerAds does: the body signed once,
 * sent byte for byte, with every push header.
 *
 * @param  array<string, mixed>|string  $envelope  an envelope, or exact body bytes
 * @param  array<string, string>  $headers  replaces the computed headers
 */
function deliver(TestCase $test, array|string $envelope, array $headers = [], string $secret = TEST_SECRET, string $keyId = TEST_KEY_ID): TestResponse
{
    $body = is_string($envelope) ? $envelope : json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($body, true) ?: [];

    $headers = array_merge([
        'X-XerAds-Contract' => '2',
        'X-XerAds-Event' => (string) ($decoded['event'] ?? ''),
        'X-XerAds-Site' => (string) ($decoded['site_id'] ?? ''),
        'X-XerAds-Key-Id' => $keyId,
        'X-XerAds-Delivery' => (string) ($decoded['delivery_id'] ?? ''),
        'X-XerAds-Attempt' => '1',
        'X-XerAds-Timestamp' => (string) Carbon::now()->getTimestamp(),
    ], $headers);

    $headers['X-XerAds-Signature'] ??= app(V2Signer::class)
        ->push($secret, $headers['X-XerAds-Timestamp'], $headers['X-XerAds-Delivery'], $body);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', '/xerads/v1/webhook', [], [], [], $server, $body);
}

/** The widget container exactly as the dashboard's script embed code writes it (widgets contract §3.1). */
function scriptContainer(): string
{
    return explode("\n", widgetEmbeds()['embeds']['script'])[0];
}

/** A scratch directory for a test that needs files of its own (a public/ or config/). */
function scratchDirectory(string $name): string
{
    $path = sys_get_temp_dir().'/xerads-laravel-tests/'.$name.'-'.bin2hex(random_bytes(4));
    @mkdir($path, 0777, true);

    return $path;
}

const API = 'https://api.xerads.id/api/site/v1';

/** XerAds' reply to a successful pairing, as SitePairingService builds it. */
function pairReply(array $overrides = []): array
{
    return array_merge([
        'site_id' => TEST_SITE_ID,
        'key_id' => TEST_KEY_ID,
        'secret' => TEST_SECRET,
        'site_key' => testSiteKey(),
        'api_url' => API,
        'delivery_mode' => 'push',
        'settings_version' => 3,
        'redirects_version' => 1,
        'indexnow_key' => str_repeat('ab', 16),
        'server_time' => '2026-10-02T08:00:00Z',
    ], $overrides);
}

/** A settings document in the shape XerAds serves (version 3). */
function settingsDocument(int $version = 3): array
{
    return [
        'schema' => 'xerads.site_settings',
        'v' => 1,
        'version' => $version,
        'site' => ['name' => 'Toko', 'tagline' => '', 'url' => 'https://shop.test', 'default_language' => 'id', 'logo' => null],
        'titles' => ['separator' => '-', 'article' => '{title} {sep} {site}'],
        'widgets' => ['loader_url' => 'https://widgets.xerads.id/v1/loader.js'],
    ];
}

function redirectsDocument(int $version = 1): array
{
    return ['version' => $version, 'data' => [
        ['id' => 7, 'match' => 'exact', 'source' => '/lama', 'target' => '/baru', 'status' => 301, 'preserve_query' => true, 'case_insensitive' => false],
    ]];
}

function heartbeatReply(array $overrides = []): array
{
    return array_merge([
        'server_time' => '2026-10-02T08:00:00Z',
        'status' => 'connected',
        'delivery_mode' => 'push',
        'settings_version' => 3,
        'redirects_version' => 1,
        'latest_plugin_version' => '1.0.0',
        'min_plugin_version' => '1.0.0',
        'entitlements' => ['articles' => true, 'widgets' => true],
        'actions' => ['pull_settings', 'pull_redirects'],
    ], $overrides);
}

/**
 * Fake XerAds' site API. Each endpoint answers as the backend does unless
 * replaced: pass a response, or a closure returning one, per path. Calling it
 * again sets every answer anew (a second Http::fake() would not: the first
 * stub registered keeps answering).
 *
 * @param  array<string, mixed>  $responses  keyed by path: pair, heartbeat, settings, redirects, not-found
 */
function fakeXerads(array $responses = []): void
{
    $defaults = [
        'pair' => fn () => Http::response(pairReply(), 201),
        'heartbeat' => fn () => Http::response(heartbeatReply()),
        'settings' => fn () => Http::response(settingsDocument(), 200, ['ETag' => '"s3"']),
        'redirects' => fn () => Http::response(redirectsDocument(), 200, ['ETag' => '"r1"']),
    ];

    $registered = app()->bound('xerads.test.api');
    $answers = $registered ? app('xerads.test.api') : new ArrayObject;

    // Every call starts from the defaults: earlier replacements do not linger.
    $answers->exchangeArray(array_merge($defaults, $responses));

    if ($registered) {
        return;
    }

    app()->instance('xerads.test.api', $answers);

    $answer = function (Request $request) use ($answers) {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/site/v1/'));
        $answer = $answers[$path] ?? Http::response(['error' => 'NOT_FOUND', 'message' => 'No such endpoint.'], 404);

        return $answer instanceof Closure ? $answer($request) : $answer;
    };

    Http::fake([API.'/*' => $answer]);

    // Pairing goes through a Guzzle client of its own (PairingClientFactory),
    // which Http::fake() does not reach: the same answers, through a handler.
    $pairings = new ArrayObject;
    app()->instance('xerads.test.pairings', $pairings);

    app()->instance(PairingClientFactory::class, new PairingClientFactory(function (RequestInterface $psrRequest) use ($answer, $pairings) {
        $request = new Request($psrRequest);
        $pairings[] = $request;

        return $answer($request);
    }));
}

/**
 * The pairing requests the fake API has seen so far in this test.
 *
 * @return list<Request>
 */
function pairingRequests(): array
{
    return app()->bound('xerads.test.pairings') ? array_values(app('xerads.test.pairings')->getArrayCopy()) : [];
}

/** How many requests the fake API has seen so far in this test, pairing included. */
function apiRequests(): int
{
    return Http::recorded()->count() + count(pairingRequests());
}

/** Store the test site's key the way pairing does. */
function storeTestKey(): void
{
    app(CredentialsResolver::class)->store(Credentials::parse(testSiteKey()));
    app()->forgetScopedInstances();
}

/** The settings document XerAds serves by default (contract fixture). */
function siteSettingsFixture(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/contract/v2/site-settings.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Hold a settings document from XerAds, as the Synchronizer stores one, for
 * the test site (whose key this stores too).
 *
 * @param  array<string, mixed>  $settings  merged over XerAds' default
 *                                          document: objects key by key,
 *                                          lists replaced whole
 */
function holdSettings(array $settings = []): void
{
    storeTestKey();

    app(RemoteState::class)->put('settings', [
        'site_id' => TEST_SITE_ID,
        'version' => 1,
        'etag' => '"s1"',
        'fetched_at' => '2026-10-02T08:00:00+00:00',
        'data' => SettingsRepository::merge(siteSettingsFixture(), $settings),
    ]);

    app(SettingsRepository::class)->forget();
    app()->forgetScopedInstances();
}

/**
 * A page of the site's own at `$uri` whose layout prints `@xeradsHead`;
 * `$prepare` runs in the request first, as a controller would.
 */
function headPage(TestCase $test, string $uri, ?Closure $prepare = null, string $head = '@xeradsHead', array $headers = []): TestResponse
{
    // Routes are matched on the decoded path.
    $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

    if (! app()->bound('test.head-pages')) {
        app()->instance('test.head-pages', new ArrayObject);
    }

    $pages = app('test.head-pages');
    $registered = isset($pages[$path]);
    $pages[$path] = ['prepare' => $prepare, 'head' => $head];

    if (! $registered) {
        Route::middleware('web')->get($path, function () use ($path) {
            $page = app('test.head-pages')[$path];

            if ($page['prepare'] !== null) {
                ($page['prepare'])();
            }

            return Blade::render('<!DOCTYPE html><html><head>'.$page['head'].'</head><body><main>Page</main></body></html>');
        });
    }

    return $test->get($uri, $headers);
}

/** The `<head>` of a response, for counting and comparing tags. */
function headOf(TestResponse $response): string
{
    preg_match('#<head>(.*?)</head>#s', (string) $response->getContent(), $match);

    return trim($match[1] ?? '');
}

/** The `content` of each meta tag with this name or property. */
function metaContent(string $html, string $key): array
{
    preg_match_all('#<meta (?:name|property)="'.preg_quote($key, '#').'" content="([^"]*)">#', $html, $matches);

    return $matches[1];
}

/** The page's JSON-LD, decoded. */
function jsonLd(string $html): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);

    return json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR) ?? [];
}
