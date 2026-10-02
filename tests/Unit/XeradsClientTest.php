<?php

/**
 * The client against the signature vectors XerAds' own suite asserts, and
 * against the ways the site API can refuse.
 */

use Illuminate\Support\Facades\Http;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\ErrorScrubber;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Sync\Client\Exceptions\ApiUnavailable;
use XerAds\Laravel\Sync\Client\Exceptions\AuthenticationFailed;
use XerAds\Laravel\Sync\Client\Exceptions\ClockSkew;
use XerAds\Laravel\Sync\Client\Exceptions\NotPaired;
use XerAds\Laravel\Sync\Client\Exceptions\RateLimited;
use XerAds\Laravel\Sync\Client\Exceptions\SiteRevoked;
use XerAds\Laravel\Sync\Client\Exceptions\XeradsApiException;
use XerAds\Laravel\Sync\Client\XeradsClient;
use XerAds\Laravel\Tests\TestCase;

function client(): XeradsClient
{
    app()->forgetScopedInstances();

    return app(XeradsClient::class);
}

it('signs pulls exactly as the shared vectors say', function (int $index) {
    $vectors = TestCase::v2Vectors();
    $vector = $vectors['pull'][$index];

    // The test site's key carries the vectors' secret.
    expect($vectors['secret'])->toBe(TEST_SECRET);

    $headers = client()->signatureHeaders(Credentials::parse(testSiteKey()), $vector['method'], $vector['uri'], $vector['body'], $vector['ts'], $vector['nonce']);

    expect($headers['X-XerAds-Signature'])->toBe($vector['expected'])
        ->and($headers)->toMatchArray([
            'X-XerAds-Site' => TEST_SITE_ID,
            'X-XerAds-Key-Id' => TEST_KEY_ID,
            'X-XerAds-Timestamp' => (string) $vector['ts'],
            'X-XerAds-Nonce' => $vector['nonce'],
        ]);
})->with([0, 1]);

it('signs the path it sends to, including a prefix in the API address', function () {
    config(['xerads.api.url' => 'https://xerads.example/backend', 'xerads.credentials.key' => testSiteKey()]);
    Http::fake(['https://xerads.example/backend/api/site/v1/heartbeat' => Http::response(heartbeatReply())]);

    client()->heartbeat(['plugin_version' => '1.0.0']);

    $request = Http::recorded()->first()[0];
    $expected = app(V2Signer::class)->pull(TEST_SECRET, (int) $request->header('X-XerAds-Timestamp')[0], $request->header('X-XerAds-Nonce')[0], 'POST', '/backend/api/site/v1/heartbeat', $request->body());

    expect($request->header('X-XerAds-Signature')[0])->toBe($expected);
});

it('maps the site API\'s refusals to typed exceptions', function (int $status, array $body, string $exception) {
    config(['xerads.credentials.key' => testSiteKey()]);
    Http::fake([API.'/heartbeat' => Http::response($body, $status)]);

    // The exact class: every one of them is a XeradsApiException.
    $thrown = null;

    try {
        client()->heartbeat([]);
    } catch (XeradsApiException $caught) {
        $thrown = $caught::class;
    }

    expect($thrown)->toBe($exception);
})->with([
    'bad signature' => [401, ['error' => 'SITE_SIGNATURE_INVALID', 'message' => 'The signature does not match.'], AuthenticationFailed::class],
    'unknown key' => [401, ['error' => 'SITE_KEY_INVALID', 'message' => 'Unknown site or key.'], AuthenticationFailed::class],
    'replay' => [401, ['error' => 'SITE_REPLAY', 'message' => 'This signed request was already used.'], AuthenticationFailed::class],
    'clock' => [401, ['error' => 'SITE_TIMESTAMP_SKEW', 'message' => 'Too far.', 'server_time' => 1790000000], ClockSkew::class],
    'revoked' => [410, ['error' => 'SITE_REVOKED', 'message' => 'Removed.'], SiteRevoked::class],
    // A proxy or a wrong api.url: not XerAds removing the site.
    'a bare 410' => [410, [], ApiUnavailable::class],
    'a 410 in other words' => [410, ['error' => 'GONE', 'message' => 'Gone.'], XeradsApiException::class],
    'throttled' => [429, ['error' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests.'], RateLimited::class],
    'server error' => [500, [], ApiUnavailable::class],
    'a login page' => [403, [], ApiUnavailable::class],
]);

it('never follows a redirect with a signed request', function () {
    config(['xerads.credentials.key' => testSiteKey()]);
    Http::fake([API.'/heartbeat' => Http::response('', 302, ['Location' => 'https://elsewhere.example/'])]);

    expect(fn () => client()->heartbeat([]))->toThrow(ApiUnavailable::class);
});

it('refuses an API address on a private network unless allowed outside production', function () {
    config(['xerads.credentials.key' => testSiteKey(), 'xerads.api.url' => 'https://10.0.0.5']);
    Http::fake(['*' => Http::response(heartbeatReply())]);

    expect(fn () => client()->heartbeat([]))->toThrow(ApiUnavailable::class, 'was refused');
    expect(Http::recorded())->toHaveCount(0);

    config(['xerads.api.allow_private_hosts' => true, 'xerads.api.url' => 'http://localhost:8000']);
    client()->heartbeat([]);
    expect(Http::recorded())->toHaveCount(1);

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => client()->heartbeat([]))->toThrow(ApiUnavailable::class);
});

it('pairs with the same address check and no redirects, outside Laravel\'s HTTP client', function () {
    fakeXerads(['pair' => Http::response('', 302, ['Location' => 'https://elsewhere.example/pair'])]);

    expect(fn () => client()->pair(['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']))->toThrow(ApiUnavailable::class);
    expect(pairingRequests())->toHaveCount(1)
        ->and(Http::recorded())->toHaveCount(0);

    config(['xerads.api.url' => 'https://10.0.0.5']);

    expect(fn () => client()->pair(['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']))->toThrow(ApiUnavailable::class, 'was refused');
    expect(pairingRequests())->toHaveCount(1);
});

it('signs nothing without a key', function () {
    expect(fn () => client()->settings())->toThrow(NotPaired::class);
});

it('scrubs keys, secrets, headers and server paths from an error before it travels', function () {
    storeTestKey();

    $scrubbed = app(ErrorScrubber::class)->scrub(
        'Failed with '.testSiteKey().' and '.TEST_SECRET.' using Bearer abc.def secret=hunter2 in /var/www/shop/vendor/x/y.php and '.base_path('app/Models/Post.php').' at https://api.xerads.id/api/site/v1/settings'
    );

    expect($scrubbed)->not->toContain(TEST_SECRET)
        ->and($scrubbed)->not->toContain('hunter2')
        ->and($scrubbed)->not->toContain('abc.def')
        ->and($scrubbed)->not->toContain('/var/www')
        ->and($scrubbed)->toContain('xsk_[hidden]')
        ->and($scrubbed)->toContain('app/Models/Post.php')
        ->and($scrubbed)->toContain('https://api.xerads.id/api/site/v1/settings')
        ->and(mb_strlen((string) app(ErrorScrubber::class)->scrub(str_repeat('x ', 600))))->toBeLessThanOrEqual(500);
});
