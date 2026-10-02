<?php

/**
 * Both signature schemes against the shared contract fixtures.
 *
 * The expected values are literals in the fixtures, computed once and pinned
 * on the XerAds side as well, so a change to either formula fails here.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Signature\V1Verifier;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Support\Signature\V2Verifier;
use XerAds\Laravel\Support\Signature\VerificationError;
use XerAds\Laravel\Tests\TestCase;

function v2Verifier(): V2Verifier
{
    app()->forgetScopedInstances();

    return app(V2Verifier::class);
}

/** @return array<string, mixed> */
function pushVector(int $index = 0): array
{
    return TestCase::v2Vectors()['push'][$index];
}

beforeEach(function () {
    config(['xerads.credentials.key' => testSiteKey()]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('v2 signer', function () {
    it('reproduces every push vector', function (int $index) {
        $vector = pushVector($index);

        expect(app(V2Signer::class)->push(TestCase::v2Vectors()['secret'], $vector['ts'], $vector['delivery_id'], $vector['body']))
            ->toBe($vector['expected']);
    })->with([0, 1]);

    it('reproduces every pull vector', function (int $index) {
        $vector = TestCase::v2Vectors()['pull'][$index];

        expect(app(V2Signer::class)->pull(TestCase::v2Vectors()['secret'], $vector['ts'], $vector['nonce'], $vector['method'], $vector['uri'], $vector['body']))
            ->toBe($vector['expected']);
    })->with([0, 1]);

    it('uppercases the method so get and GET sign alike', function () {
        $vector = TestCase::v2Vectors()['pull'][0];

        expect(app(V2Signer::class)->pull(TestCase::v2Vectors()['secret'], $vector['ts'], $vector['nonce'], 'get', $vector['uri'], $vector['body']))
            ->toBe($vector['expected']);
    });
});

describe('v2 push verification', function () {
    it('accepts every push vector', function (int $index) {
        $vector = pushVector($index);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body'], TEST_KEY_ID, TEST_SITE_ID);

        expect($result->ok)->toBeTrue()
            ->and($result->error)->toBeNull()
            ->and($result->credentials?->keyId)->toBe(TEST_KEY_ID);
    })->with([0, 1]);

    it('reads the headers and raw body of a request', function () {
        $vector = pushVector(1);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $request = Request::create('/xerads/v1/webhook', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XERADS_SIGNATURE' => $vector['expected'],
            'HTTP_X_XERADS_TIMESTAMP' => (string) $vector['ts'],
            'HTTP_X_XERADS_DELIVERY' => $vector['delivery_id'],
            'HTTP_X_XERADS_KEY_ID' => TEST_KEY_ID,
            'HTTP_X_XERADS_SITE' => TEST_SITE_ID,
        ], content: $vector['body']);

        expect(v2Verifier()->verifyPushRequest($request)->ok)->toBeTrue();
    });

    it('refuses a body with one byte changed', function () {
        $vector = pushVector(1);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $tampered = $vector['body'];
        $tampered[10] = $tampered[10] === 'a' ? 'b' : 'a';

        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $tampered, TEST_KEY_ID);

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(VerificationError::SignatureInvalid)
            ->and($result->status())->toBe(401);
    });

    it('refuses a signature replayed under another delivery id', function () {
        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], '01JB2ZZZZZZZZZZZZZZZZZZZZZ', $vector['body'], TEST_KEY_ID);

        expect($result->error)->toBe(VerificationError::SignatureInvalid);
    });

    it('refuses a timestamp outside the tolerance, in either direction', function (int $offset, bool $ok) {
        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts'] + $offset));

        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body'], TEST_KEY_ID);

        expect($result->ok)->toBe($ok)
            ->and($result->error)->toBe($ok ? null : VerificationError::TimestampSkew);
    })->with([
        'at the edge, late' => [300, true],
        'at the edge, early' => [-300, true],
        'one second late' => [301, false],
        'one second early' => [-301, false],
    ]);

    it('refuses a key id this site does not hold', function () {
        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body'], 'sk_00000000000000000000000000');

        expect($result->error)->toBe(VerificationError::KeyUnknown)
            ->and($result->status())->toBe(401);
    });

    it('refuses a delivery addressed to another site', function () {
        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body'], TEST_KEY_ID, 'site_00000000000000000000000000');

        expect($result->error)->toBe(VerificationError::SiteMismatch);
    });

    it('accepts the previous key during a rotation', function () {
        config([
            'xerads.credentials.key' => testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET),
            'xerads.credentials.previous' => testSiteKey(),
        ]);

        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $byKeyId = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body'], TEST_KEY_ID);
        $withoutKeyId = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body']);

        expect($byKeyId->ok)->toBeTrue()
            ->and($byKeyId->credentials?->keyId)->toBe(TEST_KEY_ID)
            ->and($withoutKeyId->ok)->toBeTrue();
    });

    it('refuses a signature made with the right key id but another secret', function () {
        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        $forged = app(V2Signer::class)->push(TEST_OTHER_SECRET, $vector['ts'], $vector['delivery_id'], $vector['body']);

        expect(v2Verifier()->verifyPush($forged, $vector['ts'], $vector['delivery_id'], $vector['body'], TEST_KEY_ID)->error)
            ->toBe(VerificationError::SignatureInvalid);
    });

    it('refuses malformed signature headers', function (string $signature) {
        $vector = pushVector(0);
        Carbon::setTestNow(Carbon::createFromTimestamp($vector['ts']));

        expect(v2Verifier()->verifyPush($signature, $vector['ts'], $vector['delivery_id'], $vector['body'], TEST_KEY_ID)->error)
            ->toBe(VerificationError::SignatureInvalid);
    })->with([
        'bare hex (v1 style)' => [substr(pushVector(0)['expected'], 3)],
        'uppercase hex' => ['v2='.strtoupper(substr(pushVector(0)['expected'], 3))],
        'wrong scheme' => ['v1='.substr(pushVector(0)['expected'], 3)],
        'truncated' => [substr(pushVector(0)['expected'], 0, -1)],
        'empty' => [''],
    ]);

    it('answers 503 on a site that is not paired', function () {
        config(['xerads.credentials.key' => null]);

        $vector = pushVector(0);
        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body']);

        expect($result->error)->toBe(VerificationError::NotConfigured)
            ->and($result->status())->toBe(503)
            ->and($result->toResponseBody())->toMatchArray(['ok' => false, 'error' => 'NOT_CONFIGURED']);
    });

    it('answers 503 for a malformed configured key, without repeating it', function () {
        config(['xerads.credentials.key' => 'xsk_garbage.'.TEST_SECRET]);

        $vector = pushVector(0);
        $result = v2Verifier()->verifyPush($vector['expected'], $vector['ts'], $vector['delivery_id'], $vector['body']);

        expect($result->error)->toBe(VerificationError::NotConfigured)
            ->and($result->message)->not->toContain(TEST_SECRET);
    });
});

describe('v1 verification', function () {
    it('accepts the golden request XerAds sends', function () {
        $fixture = TestCase::v1Fixture();
        Carbon::setTestNow(Carbon::createFromTimestamp($fixture['timestamp']));

        $result = app(V1Verifier::class)->verify($fixture['secret'], (string) $fixture['timestamp'], $fixture['signature'], $fixture['body']);

        expect($result->ok)->toBeTrue();
    });

    it('refuses the golden request under a wrong secret, a changed body or an uppercase signature', function () {
        $fixture = TestCase::v1Fixture();
        Carbon::setTestNow(Carbon::createFromTimestamp($fixture['timestamp']));
        $verifier = app(V1Verifier::class);

        expect($verifier->verify('another-secret', (string) $fixture['timestamp'], $fixture['signature'], $fixture['body'])->error)
            ->toBe(VerificationError::SignatureInvalid)
            ->and($verifier->verify($fixture['secret'], (string) $fixture['timestamp'], $fixture['signature'], $fixture['body'].' ')->error)
            ->toBe(VerificationError::SignatureInvalid)
            ->and($verifier->verify($fixture['secret'], (string) $fixture['timestamp'], strtoupper($fixture['signature']), $fixture['body'])->error)
            ->toBe(VerificationError::SignatureInvalid);
    });

    it('refuses a stale timestamp, missing headers and an unset secret', function () {
        $fixture = TestCase::v1Fixture();
        Carbon::setTestNow(Carbon::createFromTimestamp($fixture['timestamp'] + 301));
        $verifier = app(V1Verifier::class);

        expect($verifier->verify($fixture['secret'], (string) $fixture['timestamp'], $fixture['signature'], $fixture['body'])->error)
            ->toBe(VerificationError::TimestampSkew)
            ->and($verifier->verify($fixture['secret'], '', $fixture['signature'], $fixture['body'])->error)
            ->toBe(VerificationError::SignatureInvalid)
            ->and($verifier->verify($fixture['secret'], 'yesterday', $fixture['signature'], $fixture['body'])->error)
            ->toBe(VerificationError::SignatureInvalid)
            ->and($verifier->verify('', (string) $fixture['timestamp'], $fixture['signature'], $fixture['body'])->error)
            ->toBe(VerificationError::NotConfigured);
    });

    it('never accepts a v2 signature as a v1 one', function () {
        $fixture = TestCase::v1Fixture();
        Carbon::setTestNow(Carbon::createFromTimestamp($fixture['timestamp']));

        $v2 = app(V2Signer::class)->push($fixture['secret'], $fixture['timestamp'], 'x', $fixture['body']);

        expect(app(V1Verifier::class)->verify($fixture['secret'], (string) $fixture['timestamp'], $v2, $fixture['body'])->ok)
            ->toBeFalse();
    });
});
