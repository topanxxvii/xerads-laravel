<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use XerAds\Laravel\Sync\DeliveryLedger;

function ledger(): DeliveryLedger
{
    app()->forgetScopedInstances();

    return app(DeliveryLedger::class);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 2, 8, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('claims a new delivery once and answers copies from the stored response', function () {
    $claim = ledger()->claim('01JB2M8Q4Z7X3K9V5T1R6W0Y2H', 'ping');

    expect($claim?->isClaimed())->toBeTrue()
        ->and($claim?->token)->toHaveLength(32)
        ->and(ledger()->claim('01JB2M8Q4Z7X3K9V5T1R6W0Y2H', 'ping')?->isInProgress())->toBeTrue()
        ->and(ledger()->complete($claim, 201, ['id' => 7, 'url' => null]))->toBeTrue();

    $replay = ledger()->claim('01JB2M8Q4Z7X3K9V5T1R6W0Y2H', 'ping');

    expect($replay?->isReplay())->toBeTrue()
        ->and($replay?->responseCode)->toBe(201)
        ->and($replay?->response)->toBe(['id' => 7, 'url' => null]);
});

it('holds a claim at least as long as a copy of the request can verify', function (int $legacyTolerance, int $webhookTolerance, int $expected) {
    config([
        'xerads.legacy.timestamp_tolerance' => $legacyTolerance,
        'xerads.webhook.timestamp_tolerance' => $webhookTolerance,
    ]);

    ledger()->claim('d-lease', 'ping');

    $until = Carbon::parse(DB::table('xerads_deliveries')->where('delivery_id', 'd-lease')->value('processing_until'));

    expect((int) Carbon::now()->diffInSeconds($until))->toBe($expected);
})->with([
    'defaults' => [300, 300, 300],
    'a wider legacy window' => [900, 300, 900],
    'a wider webhook window' => [300, 600, 600],
    'narrow windows keep the minimum lease' => [60, 60, DeliveryLedger::LEASE_SECONDS],
]);

it('lets only the current claim record the outcome', function () {
    $first = ledger()->claim('d-fence', 'article.upsert');

    // The first worker stalls past its lease; a retry takes the delivery over.
    Carbon::setTestNow(Carbon::now()->addSeconds(301));
    $second = ledger()->claim('d-fence', 'article.upsert');

    expect($second?->isClaimed())->toBeTrue()
        ->and($second?->token)->not->toBe($first?->token)
        // The stalled worker finishes late: its result is not recorded.
        ->and(ledger()->complete($first, 201, ['id' => 'stale']))->toBeFalse()
        ->and(ledger()->complete($second, 201, ['id' => 'current']))->toBeTrue()
        // Nor can it turn the finished delivery into a failure afterwards.
        ->and(ledger()->fail($first, 500, null, 'late failure'))->toBeFalse();

    expect(ledger()->claim('d-fence', 'article.upsert')?->response)->toBe(['id' => 'current']);
});

it('processes a failed delivery again under a new claim', function () {
    $first = ledger()->claim('d-failed', 'article.upsert');
    ledger()->fail($first, 500, ['ok' => false], 'database down');

    $retry = ledger()->claim('d-failed', 'article.upsert');

    expect($retry?->isClaimed())->toBeTrue()
        ->and($retry?->token)->not->toBe($first?->token)
        ->and(DB::table('xerads_deliveries')->where('delivery_id', 'd-failed')->value('error'))->toBeNull();
});

it('records nothing for a claim that was never granted', function () {
    $claim = ledger()->claim('d-busy', 'ping');
    $busy = ledger()->claim('d-busy', 'ping');

    expect($busy?->isInProgress())->toBeTrue()
        ->and(ledger()->complete($busy, 201, []))->toBeFalse()
        ->and(ledger()->complete($claim, 201, []))->toBeTrue();
});
