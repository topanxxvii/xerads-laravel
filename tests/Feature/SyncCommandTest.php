<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Sync\HeartbeatReporter;
use XerAds\Laravel\Sync\RemoteState;

beforeEach(function () {
    storeTestKey();
});

/** @return array{0: int, 1: string} */
function sync(array $options = []): array
{
    app()->forgetScopedInstances();
    $code = Artisan::call('xerads:sync', $options);

    return [$code, Artisan::output()];
}

function remote(): RemoteState
{
    app()->forgetScopedInstances();

    return app(RemoteState::class);
}

it('pulls everything with --full and keeps it as data', function () {
    fakeXerads();

    [$code, $output] = sync(['--full' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Heartbeat sent; XerAds asked for: pull_settings, pull_redirects.')
        ->and($output)->toContain('Settings: updated.')
        ->and($output)->toContain('Redirects: updated.')
        ->and($output)->toContain('No new 404s to report.');

    expect(remote()->get('settings'))->toMatchArray(['version' => 3, 'etag' => '"s3"', 'data' => settingsDocument()])
        ->and(remote()->get('redirects')['data'])->toBe(redirectsDocument())
        ->and(remote()->get('heartbeat'))->toMatchArray(['status' => 'connected', 'latest_plugin_version' => '1.0.0']);
});

it('asks with the ETag it holds and keeps its copy on 304', function () {
    fakeXerads();
    sync(['--settings' => true]);

    fakeXerads(['settings' => Http::response('', 304, ['ETag' => '"s3"'])]);

    [$code, $output] = sync(['--settings' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Settings: already current.')
        ->and(remote()->get('settings')['data'])->toBe(settingsDocument());

    // The second pull carried the ETag of the copy held.
    expect(Http::recorded()->last()[0]->header('If-None-Match'))->toBe(['"s3"']);
});

it('keeps the last known good copy when XerAds is down, and backs off', function (Closure $outage) {
    fakeXerads();
    sync(['--full' => true]);

    fakeXerads(['heartbeat' => $outage, 'settings' => $outage, 'redirects' => $outage]);

    [$code, $output] = sync(['--settings' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('The last good copy is kept')
        ->and(remote()->get('settings')['data'])->toBe(settingsDocument());

    $sync = remote()->get('sync');

    expect($sync['failures'])->toBe(1)
        ->and(Carbon::parse($sync['next_refresh_at'])->diffInSeconds(now(), true))->toBeGreaterThanOrEqual(59)
        ->and($sync['last_error'])->not->toBeNull();
})->with([
    // Closures: the parameter is typed Closure, so Pest passes them as they are.
    'a 503 page' => [fn () => Http::response('<html>Down for maintenance</html>', 503)],
    'no connection' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect')],
    'a broken document' => [fn () => Http::response(['schema' => 'something.else', 'version' => 4])],
]);

it('waits out the backoff, further for each failure in a row', function () {
    $down = fn () => Http::response('', 503);
    fakeXerads(['heartbeat' => $down, 'settings' => $down, 'redirects' => $down]);

    [$code] = sync();
    expect($code)->toBe(1)->and(remote()->get('sync')['failures'])->toBe(1);

    // Within the first backoff (60 s): the routine refresh does not even try.
    $before = apiRequests();
    [$code, $output] = sync();

    expect($code)->toBe(0)
        ->and($output)->toContain('Nothing is due')
        ->and(apiRequests())->toBe($before);

    $this->travel(61)->seconds();
    sync();

    $sync = remote()->get('sync');

    expect($sync['failures'])->toBe(2)
        ->and(Carbon::parse($sync['next_refresh_at'])->diffInSeconds(now(), true))->toBeGreaterThanOrEqual(299);

    // Once XerAds is back, a success clears the failures.
    $this->travel(301)->seconds();
    fakeXerads();
    sync();

    expect(remote()->get('sync'))->toMatchArray(['failures' => 0, 'next_refresh_at' => null, 'last_error' => null]);
});

it('does nothing on a routine run while the copy is fresh', function () {
    fakeXerads();
    sync();

    $before = apiRequests();
    [, $output] = sync();

    expect($output)->toContain('Nothing is due')->and(apiRequests())->toBe($before);

    $this->travel(16)->minutes();
    fakeXerads(['settings' => Http::response('', 304, ['ETag' => '"s3"']), 'redirects' => Http::response('', 304, ['ETag' => '"r1"'])]);
    [, $output] = sync();

    expect($output)->toContain('Settings: already current.');
});

it('pulls at once when XerAds announces a change', function () {
    fakeXerads();
    sync(['--full' => true]);

    config(['xerads.credentials.key' => testSiteKey()]);
    fakeXerads(['settings' => Http::response(settingsDocument(4), 200, ['ETag' => '"s4"'])]);

    // The nudge is answered first; the pull runs after the response.
    deliver($this, envelopeFor('settings.updated', ['version' => 4]))->assertOk();

    expect(remote()->heldVersion('settings'))->toBe(4)
        ->and(remote()->get('settings')['etag'])->toBe('"s4"');
});

it('refreshes after a response on a site without cron', function () {
    config(['xerads.sync.after_response_in_tests' => true]);
    fakeXerads();
    Route::middleware('web')->get('/halaman', fn () => 'ok');

    $this->get('/halaman')->assertOk();
    $this->get('/halaman')->assertOk();

    expect(remote()->heldVersion('settings'))->toBe(3)
        // One refresh (heartbeat, settings, redirects) for the two page views.
        ->and(apiRequests())->toBe(3);

    $this->travel(16)->minutes();
    fakeXerads(['settings' => Http::response('', 304), 'redirects' => Http::response('', 304)]);

    $this->get('/halaman')->assertOk();

    expect(Http::recorded()->last()[0]->url())->toBe(API.'/redirects')
        ->and(apiRequests())->toBe(5);
});

it('stops when XerAds removed the site', function () {
    fakeXerads(['heartbeat' => Http::response(['error' => 'SITE_REVOKED', 'message' => 'This site\'s connection to XerAds was removed.'], 410)]);

    [$code, $output] = sync(['--heartbeat' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('SITE_REVOKED')
        ->and(remote()->isRevoked())->toBeTrue();

    [, $output] = sync();
    expect($output)->toContain('not paired with XerAds (or XerAds removed it)');
});

it('says when the clock is off rather than blaming the key', function () {
    fakeXerads(['heartbeat' => Http::response(['error' => 'SITE_TIMESTAMP_SKEW', 'message' => 'The request timestamp is too far from XerAds\' clock.', 'server_time' => 1790000000], 401)]);

    [$code, $output] = sync(['--heartbeat' => true]);

    expect($code)->toBe(1)->and($output)->toContain('SITE_TIMESTAMP_SKEW');
});

it('reports nothing when no 404 was counted', function () {
    [$code, $output] = sync(['--report-404' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('No new 404s to report.')
        ->and(apiRequests())->toBe(0);
});

it('has nothing to do on a site that is not paired', function () {
    DB::table('xerads_state')->where('key', 'credentials')->delete();

    [$code, $output] = sync(['--full' => true]);

    expect($code)->toBe(0)->and($output)->toContain('not paired')->and(apiRequests())->toBe(0);
});

/** @return array<string, Event> the scheduled events, by command */
function scheduled(): array
{
    return collect(app(Schedule::class)->events())
        ->keyBy(fn (Event $event) => (string) preg_replace('/^.*artisan["\']?\s+/', '', (string) $event->command))
        ->all();
}

it('schedules the refresh and the hourly heartbeat at minutes of this site\'s own', function () {
    storeTestKey();

    // The same minute for the same site, every time; another site gets another.
    $minute = (int) (hexdec(substr(hash('sha256', 'xerads-schedule|'.TEST_SITE_ID), 0, 7)) % 60);
    $events = scheduled();

    expect($events['xerads:sync']->expression)->toBe(sprintf('%d,%d,%d,%d * * * *', $minute % 15, $minute % 15 + 15, $minute % 15 + 30, $minute % 15 + 45))
        ->and($events['xerads:sync --heartbeat']->expression)->toBe(sprintf('%d * * * *', ($minute + 7) % 60))
        // A sync killed mid-run blocks the next ones for minutes, not a day.
        ->and($events['xerads:sync']->withoutOverlapping)->toBeTrue()
        ->and($events['xerads:sync']->expiresAt)->toBe(10)
        ->and($events['xerads:sync --heartbeat']->expiresAt)->toBe(10);
});

it('uses the schedules the site configured', function () {
    config(['xerads.sync.schedule' => '*/5 * * * *', 'xerads.sync.heartbeat_schedule' => '30 * * * *']);

    $events = scheduled();

    expect($events['xerads:sync']->expression)->toBe('*/5 * * * *')
        ->and($events['xerads:sync --heartbeat']->expression)->toBe('30 * * * *');
});

it('schedules nothing when the scheduler is turned off', function () {
    $this->rebootWith(['xerads.sync.scheduler' => false]);

    expect(scheduled())->not->toHaveKey('xerads:sync')
        ->and(scheduled())->not->toHaveKey('xerads:sync --heartbeat');
});

it('refreshes on every cron tick, not every second one', function () {
    fakeXerads();
    sync();

    // The next tick: the copy is a moment short of 15 minutes old, because
    // the last run started a moment after its own tick.
    $this->travel(14 * 60 + 30)->seconds();
    fakeXerads(['settings' => Http::response('', 304, ['ETag' => '"s3"']), 'redirects' => Http::response('', 304, ['ETag' => '"r1"'])]);

    [, $output] = sync();

    expect($output)->not->toContain('Nothing is due')
        ->and($output)->toContain('Settings: already current.');
});

it('counts a refresh only when both documents were pulled', function () {
    fakeXerads();

    sync(['--settings' => true]);

    expect(remote()->lastRefreshAt())->toBeNull();

    sync(['--redirects' => true]);

    expect(remote()->lastRefreshAt())->toBeNull();

    sync(['--settings' => true, '--redirects' => true]);

    expect(remote()->lastRefreshAt())->not->toBeNull();
});

it('neither hides nor resets a failing refresh with a heartbeat that works', function () {
    $down = fn () => Http::response('', 503);
    fakeXerads(['settings' => $down, 'redirects' => $down]);

    [$code] = sync(['--full' => true]);
    $failing = remote()->get('sync');

    expect($code)->toBe(1)->and($failing['failures'])->toBe(1);

    [$code] = sync(['--heartbeat' => true]);

    expect($code)->toBe(0)
        ->and(remote()->get('sync'))->toMatchArray([
            'failures' => 1,
            'next_refresh_at' => $failing['next_refresh_at'],
            'last_error' => $failing['last_error'],
        ])
        ->and(remote()->lastRefreshAt())->toBeNull();
});

it('keeps a failing heartbeat apart from the refresh\'s backoff', function () {
    fakeXerads(['heartbeat' => fn () => Http::response('', 503)]);

    [$code] = sync(['--heartbeat' => true]);
    $sync = remote()->get('sync');

    expect($code)->toBe(1)
        ->and($sync['heartbeat_error'])->toContain('503')
        ->and($sync['failures'] ?? 0)->toBe(0)
        ->and($sync['next_refresh_at'] ?? null)->toBeNull();

    fakeXerads();
    sync(['--heartbeat' => true]);

    expect(remote()->get('sync')['heartbeat_error'])->toBeNull();
});

it('does not take a 410 from something other than XerAds for a removal', function () {
    fakeXerads(['heartbeat' => fn () => Http::response('<html>Gone</html>', 410)]);

    [$code] = sync(['--heartbeat' => true]);

    expect($code)->toBe(1)->and(remote()->isRevoked())->toBeFalse();

    fakeXerads();
    [$code] = sync(['--full' => true]);

    expect($code)->toBe(0);
});

it('takes no other site\'s documents for this one\'s', function () {
    fakeXerads();
    sync(['--full' => true]);

    // XERADS_SITE_KEY now names another site.
    $otherSite = 'site_01jb2n0a1b2c3d4e5f6g7h8j9k';
    config(['xerads.credentials.key' => testSiteKey(siteId: $otherSite)]);

    expect(remote()->heldVersion('settings'))->toBeNull()
        ->and(remote()->document('redirects'))->toBe([]);

    sync(['--settings' => true]);

    // No ETag from the other site's copy, so no 304 that would keep it.
    $pull = Http::recorded()->last()[0];

    expect($pull->url())->toBe(API.'/settings')
        ->and($pull->hasHeader('If-None-Match'))->toBeFalse()
        ->and(remote()->get('settings')['site_id'])->toBe($otherSite)
        ->and(remote()->get('site')['site_id'])->toBe($otherSite)
        // The first site's announced versions and heartbeat went with it.
        ->and(remote()->get('heartbeat'))->toBe([]);
});

it('reports the redirects it holds, not the keys of the document', function () {
    fakeXerads();
    sync(['--full' => true]);

    app()->forgetScopedInstances();

    expect(app(HeartbeatReporter::class)->payload()['counts']['redirects'])->toBe(1);
});
