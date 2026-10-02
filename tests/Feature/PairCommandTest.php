<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Sync\RemoteState;

const PAIRING_CODE = 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA';

/** @return array{0: int, 1: string} */
function pair(array $options = []): array
{
    app()->forgetScopedInstances();
    $code = Artisan::call('xerads:pair', $options + ['code' => PAIRING_CODE]);
    app()->forgetScopedInstances();

    return [$code, Artisan::output()];
}

function heldKey(): ?Credentials
{
    app()->forgetScopedInstances();

    return app(CredentialsResolver::class)->current();
}

it('pairs, stores the key encrypted, reports in with it and pulls the settings', function () {
    fakeXerads();

    [$code, $output] = pair();

    expect($code)->toBe(0)
        ->and($output)->toContain('Paired as xsk_'.TEST_SITE_ID.'.'.TEST_KEY_ID.'.********')
        ->and($output)->toContain('pulled the site\'s settings and redirects')
        ->and(heldKey()?->keyId)->toBe(TEST_KEY_ID);

    $stored = (string) DB::table('xerads_state')->where('key', 'credentials')->value('value');
    expect($stored)->not->toContain(TEST_SECRET);

    // What the pairing told XerAds about this site.
    $pairing = pairingRequests()[0];

    expect(pairingRequests())->toHaveCount(1)
        ->and($pairing->url())->toBe(API.'/pair')
        ->and($pairing->method())->toBe('POST')
        ->and($pairing['code'])->toBe(PAIRING_CODE)
        ->and($pairing['site_url'])->toBe('http://localhost')
        ->and($pairing['webhook_url'])->toBe('http://localhost/xerads/v1/webhook')
        ->and($pairing['contract'])->toBe([2])
        ->and($pairing['mode'])->toBe('mapped')
        ->and($pairing['events'])->toContain('article.upsert')
        ->and($pairing->hasHeader('X-XerAds-Signature'))->toBeFalse();

    // The first heartbeat is signed with the new key: that is what makes
    // XerAds sign deliveries with it.
    Http::assertSent(fn (Request $request) => $request->url() === API.'/heartbeat'
        && $request->header('X-XerAds-Key-Id')[0] === TEST_KEY_ID
        && $request['key_id'] === TEST_KEY_ID);

    $state = app(RemoteState::class);

    expect($state->get('site'))->toMatchArray(['site_id' => TEST_SITE_ID, 'key_id' => TEST_KEY_ID, 'delivery_mode' => 'push'])
        ->and($state->scalar('indexnow_key'))->toBe(str_repeat('ab', 16))
        ->and($state->heldVersion('settings'))->toBe(3)
        ->and($state->heldVersion('redirects'))->toBe(1);
});

it('reports a code XerAds refuses, and stores nothing', function (int $status, string $error, string $message) {
    fakeXerads(['pair' => Http::response(['error' => $error, 'message' => $message], $status)]);

    [$code, $output] = pair();

    expect($code)->toBe(1)
        ->and($output)->toContain($error)
        ->and($output)->toContain($message)
        ->and(heldKey())->toBeNull()
        ->and(apiRequests())->toBe(1);
})->with([
    'used or expired' => [404, 'PAIRING_CODE_INVALID', 'This pairing code is unknown, used or expired. Create a new one in XerAds.'],
    'another host' => [409, 'SITE_URL_MISMATCH', 'This code is for shop.test. Pair from that site, or change the site\'s address in XerAds.'],
    'webhook unreachable' => [422, 'WEBHOOK_UNREACHABLE', 'XerAds cannot deliver to http://localhost/xerads/v1/webhook. Only https URLs are allowed.'],
]);

it('prints a .env line instead of storing the key with --print-env', function () {
    fakeXerads();

    [$code, $output] = pair(['--print-env' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('XERADS_SITE_KEY='.testSiteKey())
        ->and(heldKey())->toBeNull();

    // Only the pairing: nothing is signed with a key this site does not hold.
    expect(apiRequests())->toBe(1);
});

it('shows which key is held with --show, never the secret', function () {
    [$code, $output] = pair(['--show' => true, 'code' => null]);

    expect($code)->toBe(0)->and($output)->toContain('not paired');

    storeTestKey();
    [$code, $output] = pair(['--show' => true, 'code' => null]);

    expect($code)->toBe(0)
        ->and($output)->toContain(TEST_SITE_ID)
        ->and($output)->toContain(TEST_KEY_ID)
        ->and($output)->toContain('from the database')
        ->and($output)->not->toContain(TEST_SECRET)
        ->and(apiRequests())->toBe(0);
});

it('refuses to replace a key without --rotate, and keeps the old one for a day with it', function () {
    storeTestKey();
    $newKey = 'xsk_'.TEST_SITE_ID.'.'.TEST_OTHER_KEY_ID.'.'.TEST_OTHER_SECRET;
    fakeXerads(['pair' => Http::response(pairReply(['key_id' => TEST_OTHER_KEY_ID, 'secret' => TEST_OTHER_SECRET, 'site_key' => $newKey]), 201)]);

    [$refused, $output] = pair();

    expect($refused)->toBe(1)
        ->and($output)->toContain('already paired')
        ->and($output)->toContain('--rotate')
        ->and(apiRequests())->toBe(0);

    [$code, $output] = pair(['--rotate' => true]);
    $resolver = app(CredentialsResolver::class);

    expect($code)->toBe(0)
        ->and($output)->toContain('stays valid for 24 hours')
        ->and($resolver->current()?->keyId)->toBe(TEST_OTHER_KEY_ID)
        ->and($resolver->previous()?->keyId)->toBe(TEST_KEY_ID);

    $this->travel(25)->hours();
    app()->forgetScopedInstances();

    expect(app(CredentialsResolver::class)->previous())->toBeNull();
});

it('warns when the configuration is cached, or a key in .env would win', function () {
    // Laravel 13 remembers the answer in the container; earlier versions
    // look for the cache file each time.
    $cached = scratchDirectory('bootstrap').'/config.php';
    file_put_contents($cached, '<?php return [];');
    putenv('APP_CONFIG_CACHE='.$cached);
    app()->instance('config_loaded_from_cache', true);

    try {
        storeTestKey();
        config(['xerads.credentials.key' => testSiteKey()]);
        fakeXerads();

        [$code, $output] = pair(['--rotate' => true]);

        expect($code)->toBe(0)
            ->and($output)->toContain('The configuration is cached')
            ->and($output)->toContain('XERADS_SITE_KEY is set in the environment');
    } finally {
        putenv('APP_CONFIG_CACHE');
    }
});

it('refuses something that is not a pairing code without asking XerAds', function () {
    [$code] = pair(['code' => 'not-a-code']);

    expect($code)->toBe(2)->and(apiRequests())->toBe(0);
});

it('signs its heartbeat exactly as XerAds verifies it', function () {
    fakeXerads();
    pair();

    Http::assertSent(function (Request $request) {
        if ($request->url() !== API.'/heartbeat') {
            return false;
        }

        $expected = app(V2Signer::class)->pull(
            TEST_SECRET,
            (int) $request->header('X-XerAds-Timestamp')[0],
            $request->header('X-XerAds-Nonce')[0],
            'POST',
            '/api/site/v1/heartbeat',
            $request->body(),
        );

        return $request->header('X-XerAds-Signature')[0] === $expected
            && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $request->header('X-XerAds-Nonce')[0]) === 1
            && $request->header('X-XerAds-Site')[0] === TEST_SITE_ID;
    });
});

it('says the key is confirmed later when another sync holds the lock', function () {
    fakeXerads();
    $lock = Cache::lock('xerads:sync', 120);
    $lock->get();

    try {
        [$code, $output] = pair();
    } finally {
        $lock->release();
    }

    expect($code)->toBe(0)
        ->and($output)->toContain('XerAds switches to the new key at the next heartbeat')
        ->and($output)->not->toContain('Reported to XerAds');

    // Only the pairing: no heartbeat confirmed the new key.
    expect(apiRequests())->toBe(1);
});

it('lifts a revocation when the new key goes to .env', function () {
    storeTestKey();
    deliver($this, envelopeFor('site.revoked'))->assertOk();

    expect(app(RemoteState::class)->isRevoked())->toBeTrue();

    $newKey = testSiteKey(TEST_OTHER_KEY_ID, TEST_OTHER_SECRET);
    fakeXerads(['pair' => Http::response(pairReply(['key_id' => TEST_OTHER_KEY_ID, 'secret' => TEST_OTHER_SECRET, 'site_key' => $newKey]), 201)]);

    [$code] = pair(['--print-env' => true]);

    expect($code)->toBe(0)
        ->and(app(RemoteState::class)->get('site_status'))->toBe([])
        ->and(app(RemoteState::class)->get('site')['key_id'])->toBe(TEST_OTHER_KEY_ID);

    // The owner puts the line in .env.
    config(['xerads.credentials.key' => $newKey]);
    app()->forgetScopedInstances();
    Artisan::call('xerads:sync', ['--heartbeat' => true]);

    expect(Artisan::output())->toContain('Heartbeat sent');
});
