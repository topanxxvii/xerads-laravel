<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use XerAds\Laravel\Content\Contracts\PipelineStep;
use XerAds\Laravel\Content\Pipeline\ContentDocument;
use XerAds\Laravel\Support\Signature\V2Signer;
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
