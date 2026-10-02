<?php

/**
 * The site key's secret is in the database (encrypted) and in signed
 * requests' HMACs, and nowhere a person or a log can read it: no command
 * prints it, and no heartbeat sends it. `xerads:pair --print-env` is the one
 * exception, by design, and prints it exactly once.
 */

use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use XerAds\Laravel\Sync\RemoteState;

it('appears in no command\'s output', function () {
    fakeXerads();
    $outputs = [];

    $run = function (string $command, array $options = []) use (&$outputs) {
        app()->forgetScopedInstances();
        Artisan::call($command, $options);
        $outputs[$command.' '.json_encode($options)] = Artisan::output();
    };

    $run('xerads:pair', ['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']);
    $run('xerads:pair', ['--show' => true]);
    $run('xerads:pair', ['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']);
    $run('xerads:pair', ['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA', '--rotate' => true]);
    $run('xerads:sync', ['--full' => true]);
    $run('xerads:sync');
    $run('xerads:status');
    $run('xerads:status', ['--json' => true]);
    $run('xerads:doctor');
    $run('xerads:doctor', ['--json' => true]);
    $run('xerads:simulate', ['--event' => 'ping']);

    fakeXerads(['heartbeat' => fn () => Http::response(['error' => 'SITE_SIGNATURE_INVALID', 'message' => 'Bad signature for '.testSiteKey()], 401)]);
    $run('xerads:sync', ['--heartbeat' => true]);
    $run('xerads:status');

    foreach ($outputs as $command => $output) {
        expect($output)->not->toContain(TEST_SECRET, "`{$command}` printed the secret.");
    }
});

it('is printed once, by design, with --print-env', function () {
    fakeXerads();

    Artisan::call('xerads:pair', ['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA', '--print-env' => true]);

    expect(substr_count(Artisan::output(), TEST_SECRET))->toBe(1);
});

it('never travels in a heartbeat, even inside an error message', function () {
    storeTestKey();
    app(RemoteState::class)->put('sync', ['failures' => 1, 'last_error' => 'cURL failed for '.testSiteKey().' at '.base_path('vendor/x.php')]);
    fakeXerads();

    Artisan::call('xerads:sync', ['--heartbeat' => true]);

    $heartbeat = Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), '/heartbeat'))[0];

    expect($heartbeat->body())->not->toContain(TEST_SECRET)
        ->and($heartbeat['last_error'])->toContain('xsk_[hidden]')
        ->and($heartbeat['key_id'])->toBe(TEST_KEY_ID);
});

it('reaches no HTTP client listener when pairing', function () {
    fakeXerads();
    $seen = new ArrayObject;

    // What a request inspector records: every request and response the
    // HTTP client announces.
    Event::listen(RequestSending::class, fn (RequestSending $event) => $seen[] = $event->request->body());
    Event::listen(ResponseReceived::class, fn (ResponseReceived $event) => $seen[] = $event->response->body());

    Artisan::call('xerads:pair', ['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']);

    expect(Artisan::output())->toContain('Paired as')
        ->and(pairingRequests())->toHaveCount(1)
        // The heartbeat and pulls after it did go through the HTTP client.
        ->and(count($seen))->toBeGreaterThan(0)
        ->and(implode("\n", (array) $seen))->not->toContain(TEST_SECRET);

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pair'));
});
