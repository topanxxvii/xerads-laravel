<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use XerAds\Laravel\Sync\Http\Middleware\RefreshWhenStale;

/** @return array{0: int, 1: string} */
function status(array $options = []): array
{
    app()->forgetScopedInstances();
    $code = Artisan::call('xerads:status', $options);

    return [$code, Artisan::output()];
}

it('says when the site is not paired', function () {
    [$code, $output] = status();

    expect($code)->toBe(0)->and($output)->toContain('Not paired with XerAds');

    [, $json] = status(['--json' => true]);

    expect(json_decode($json, true))->toMatchArray(['paired' => false, 'site_id' => null, 'key_source' => null]);
});

it('shows the connection, the versions held and the sync health', function () {
    storeTestKey();
    fakeXerads(['heartbeat' => Http::response(heartbeatReply(['latest_plugin_version' => '9.9.9']))]);
    Artisan::call('xerads:sync', ['--full' => true]);
    deliver($this->withoutMiddleware(RefreshWhenStale::class), envelopeFor('ping'), secret: TEST_SECRET);

    [$code, $json] = status(['--json' => true]);
    $status = json_decode($json, true);

    expect($code)->toBe(0)
        ->and($status)->toMatchArray([
            'paired' => true,
            'site_id' => TEST_SITE_ID,
            'key_id' => TEST_KEY_ID,
            'key_source' => 'database',
            'revoked' => false,
            'latest_version' => '9.9.9',
            'outdated' => true,
            'contract' => 2,
            'mode' => 'mapped',
        ])
        ->and($status['settings'])->toMatchArray(['version' => 3, 'announced_version' => 3])
        ->and($status['redirects'])->toMatchArray(['version' => 1, 'count' => 1])
        ->and($status['sync']['failures'])->toBe(0)
        ->and($status['last_heartbeat']['status'])->toBe('connected')
        ->and($status['last_delivery'])->toMatchArray(['event' => 'ping', 'status' => 'ok']);

    [, $text] = status();

    expect($text)->toContain('Site:       '.TEST_SITE_ID)
        ->and($text)->toContain('Key:        '.TEST_KEY_ID.' (from the database)')
        ->and($text)->toContain('Version 9.9.9 is available')
        ->and($text)->toContain('Settings:   version 3')
        ->and($text)->toContain('Delivery:   ping (ok)');
});

it('shows a failing sync and how long it waits', function () {
    storeTestKey();
    fakeXerads(['settings' => fn () => Http::response('', 503)]);
    Artisan::call('xerads:sync', ['--settings' => true]);

    [, $text] = status();
    [, $json] = status(['--json' => true]);

    expect($text)->toContain('Sync failed 1 time(s) in a row')
        ->and(json_decode($json, true)['sync']['failures'])->toBe(1);
});

it('shows a failing heartbeat apart from the sync', function () {
    storeTestKey();
    fakeXerads(['heartbeat' => fn () => Http::response('', 503)]);
    Artisan::call('xerads:sync', ['--heartbeat' => true]);

    [, $text] = status();
    $status = json_decode(status(['--json' => true])[1], true);

    expect($text)->toContain('The last heartbeat failed')
        ->and($text)->not->toContain('Sync failed')
        ->and($status['sync']['failures'])->toBe(0)
        ->and($status['sync']['heartbeat_error'])->toContain('503');
});
