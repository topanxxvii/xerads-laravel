<?php

/**
 * `xerads:prune`: 404 paths no longer seen, delivery records XerAds no
 * longer retries, and the package's expired entries in a database cache;
 * daily on the site's scheduler unless turned off.
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use XerAds\Laravel\Seo\NotFound\NotFoundEntry;

function notFoundSeenDaysAgo(string $path, int $days): void
{
    NotFoundEntry::query()->create(['path' => $path, 'path_hash' => sha1($path), 'hits' => 1, 'first_seen_at' => now()->subDays($days), 'last_seen_at' => now()->subDays($days)]);
}

function deliveryDaysAgo(string $id, int $days, string $status = 'done'): void
{
    DB::table('xerads_deliveries')->insert(['delivery_id' => $id, 'event' => 'article.upsert', 'status' => $status, 'received_at' => now()->subDays($days)]);
}

/** @return array<string, Event> */
function scheduledEvents(): array
{
    return collect(app(Schedule::class)->events())
        ->keyBy(fn (Event $event) => (string) preg_replace('/^.*artisan["\']?\s+/', '', (string) $event->command))
        ->all();
}

it('deletes 404 paths and delivery records past their retention, and nothing newer', function () {
    notFoundSeenDaysAgo('/lama-sekali', 31);
    notFoundSeenDaysAgo('/baru-saja', 29);
    deliveryDaysAgo('lama', 8);
    deliveryDaysAgo('lama-macet', 8, 'processing');
    deliveryDaysAgo('baru', 6);

    $this->artisan('xerads:prune')
        ->expectsOutput('Pruned 1 404 path(s), 1 delivery record(s) and 0 expired cache entries.')
        ->assertSuccessful();

    expect(NotFoundEntry::query()->pluck('path')->all())->toBe(['/baru-saja'])
        ->and(DB::table('xerads_deliveries')->orderBy('delivery_id')->pluck('delivery_id')->all())->toBe(['baru', 'lama-macet']);
});

it('follows the retention the site configured', function () {
    config(['xerads.monitor_404.retention_days' => 3, 'xerads.webhook.delivery_retention_days' => 2]);
    notFoundSeenDaysAgo('/empat-hari', 4);
    deliveryDaysAgo('tiga-hari', 3);

    $this->artisan('xerads:prune')->assertSuccessful();

    expect(NotFoundEntry::query()->count())->toBe(0)
        ->and(DB::table('xerads_deliveries')->count())->toBe(0);
});

it('deletes the package\'s expired entries from a database cache, and only those', function () {
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    config(['cache.default' => 'database', 'cache.stores.database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null], 'cache.prefix' => 'situs_']);

    $past = Carbon::now()->subMinute()->getTimestamp();
    $future = Carbon::now()->addHour()->getTimestamp();

    DB::table('cache')->insert([
        ['key' => 'situs_xerads:sitemap:lama', 'value' => 's:1:"x";', 'expiration' => $past],
        ['key' => 'situs_xerads:sitemap:baru', 'value' => 's:1:"x";', 'expiration' => $future],
        ['key' => 'situs_milik-situs', 'value' => 's:1:"x";', 'expiration' => $past],
    ]);

    $this->artisan('xerads:prune')->expectsOutputToContain('1 expired cache entry.')->assertSuccessful();

    expect(DB::table('cache')->orderBy('key')->pluck('key')->all())->toBe(['situs_milik-situs', 'situs_xerads:sitemap:baru']);
});

it('runs daily, on its own switch', function () {
    expect(scheduledEvents()['xerads:prune']->expression)->toMatch('/^\d{1,2} 3 \* \* \*$/');

    $this->rebootWith(['xerads.sync.scheduler' => false]);
    expect(scheduledEvents())->toHaveKey('xerads:prune')
        ->and(scheduledEvents())->not->toHaveKey('xerads:sync');

    $this->rebootWith(['xerads.prune.scheduled' => false]);
    expect(scheduledEvents())->not->toHaveKey('xerads:prune')
        ->and(scheduledEvents())->toHaveKey('xerads:sync');
});
