<?php

/*
 * The after-response refresh runs on every page of the site, so it has to
 * hold up on whatever the site runs: Redis, immutable dates, a cache that is
 * down, a burst of requests, an application without a `web` group, and the
 * site's own test suite.
 */

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use XerAds\Laravel\Sync\Http\Middleware\RefreshWhenStale;
use XerAds\Laravel\Sync\Jobs\RefreshRemoteState;
use XerAds\Laravel\Sync\RemoteState;
use XerAds\Laravel\Sync\SyncServiceProvider;

/** Hands stored numbers back as numeric strings, the way the Redis store does. */
final class NumericStringStore extends ArrayStore
{
    public function get($key): mixed
    {
        $value = parent::get($key);

        return is_int($value) ? (string) $value : $value;
    }
}

/** A cache whose server is down. */
final class UnreachableStore extends ArrayStore
{
    public function get($key): mixed
    {
        throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
    }

    public function put($key, $value, $seconds): bool
    {
        throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
    }
}

function useCacheStore(string $name, ArrayStore $store): void
{
    Cache::extend($name, fn () => Cache::repository($store));
    config(["cache.stores.{$name}" => ['driver' => $name], 'xerads.cache.store' => $name]);
}

/** Page views handled one after another, all before any of them terminates. */
function burstOfPageViews(int $count): void
{
    for ($view = 0; $view < $count; $view++) {
        app()->forgetScopedInstances();
        app(RefreshWhenStale::class)->handle(Request::create('/halaman'), fn () => new Response('ok'));
    }
}

beforeEach(function () {
    config(['xerads.sync.after_response_in_tests' => true]);
    Route::middleware('web')->get('/halaman', fn () => 'ok');
});

it('dispatches one refresh for a burst of requests', function () {
    Bus::fake();

    burstOfPageViews(5);

    Bus::assertDispatchedAfterResponseTimes(RefreshRemoteState::class, 1);
});

it('dispatches one refresh for a burst on a cache that returns numbers as strings', function () {
    useCacheStore('numeric-strings', new NumericStringStore);
    Bus::fake();

    burstOfPageViews(5);

    Bus::assertDispatchedAfterResponseTimes(RefreshRemoteState::class, 1);
});

it('works when the application made immutable dates its default', function () {
    storeTestKey();
    fakeXerads();
    Date::use(CarbonImmutable::class);

    try {
        $this->get('/halaman')->assertOk();
    } finally {
        Date::useDefault();
    }

    // The refresh ran: the date was not swallowed as an error either.
    expect(app(RemoteState::class)->heldVersion('settings'))->toBe(3);
});

it('serves the page when the cache is down, paired or not', function (bool $paired) {
    if ($paired) {
        storeTestKey();
    }

    useCacheStore('unreachable', new UnreachableStore);
    Bus::fake();

    $this->get('/halaman')->assertOk();
    $this->get('/halaman')->assertOk();

    Bus::assertNotDispatchedAfterResponse(RefreshRemoteState::class);
})->with(['paired' => [true], 'not paired' => [false]]);

it('stays off while the site\'s own tests run', function () {
    config(['xerads.sync.after_response_in_tests' => false]);
    storeTestKey();
    fakeXerads();

    $this->get('/halaman')->assertOk();

    expect(apiRequests())->toBe(0);
});

it('leaves an application without a web group alone', function () {
    $kernel = app(HttpKernel::class);
    $kernel->setMiddlewareGroups(['api' => []]);

    (new SyncServiceProvider(app()))->boot();

    expect($kernel->getMiddlewareGroups())->toBe(['api' => []]);
});
