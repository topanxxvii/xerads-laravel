<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['xerads.credentials.key' => testSiteKey()]);
});

/** @return array{0: int, 1: array{ok: bool, checks: list<array{check: string, status: string, message: string}>}} */
function doctor(): array
{
    $code = Artisan::call('xerads:doctor', ['--json' => true]);

    return [$code, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
}

/** @param  array{checks: list<array{check: string, status: string, message: string}>}  $report */
function statusOf(array $report, string $check): ?string
{
    foreach ($report['checks'] as $entry) {
        if ($entry['check'] === $check) {
            return $entry['status'];
        }
    }

    return null;
}

it('passes a working setup, warnings and all', function () {
    [$code, $report] = doctor();

    expect($code)->toBe(0)
        ->and($report['ok'])->toBeTrue()
        ->and(statusOf($report, 'credentials'))->toBe('ok')
        ->and(statusOf($report, 'webhook_route'))->toBe('ok')
        ->and(statusOf($report, 'route_conflicts'))->toBe('ok')
        ->and(statusOf($report, 'migrations'))->toBe('ok')
        ->and(statusOf($report, 'receiver'))->toBe('ok')
        // http://localhost, no storage link and the sync driver are worth a
        // look, not a failure.
        ->and(statusOf($report, 'app_url'))->toBe('warn')
        ->and(statusOf($report, 'queue'))->toBe('warn');
});

it('only warns on a site that is not paired yet', function () {
    config(['xerads.credentials.key' => null]);

    [$code, $report] = doctor();

    expect($code)->toBe(0)->and(statusOf($report, 'credentials'))->toBe('warn');
});

it('fails on what stops deliveries', function (Closure $break, string $check) {
    $break();

    [$code, $report] = doctor();

    expect($code)->toBe(1)
        ->and($report['ok'])->toBeFalse()
        ->and(statusOf($report, $check))->toBe('fail');
})->with([
    'malformed key' => [fn () => config(['xerads.credentials.key' => 'xsk_broken']), 'credentials'],
    'revoked site' => [fn () => DB::table('xerads_state')->insert(['key' => 'site_status', 'value' => json_encode(['status' => 'revoked'])]), 'credentials'],
    'webhook in the web group' => [fn () => Route::getRoutes()->getByName('xerads.webhook')?->middleware('web'), 'webhook_route'],
    'tables missing' => [fn () => config(['xerads.database.table_prefix' => 'not_migrated_']), 'migrations'],
    'receiver broken' => [fn () => config(['xerads.content.mapped.model' => 'App\\Models\\Missing']), 'receiver'],
]);

it('warns about routes sharing the prefix and static files that shadow the package', function () {
    Route::get('xerads/v1/mine', fn () => 'mine');

    $public = scratchDirectory('public');
    file_put_contents($public.'/robots.txt', "User-agent: *\n");
    app()->usePublicPath($public);

    [$code, $report] = doctor();

    expect($code)->toBe(0)
        ->and(statusOf($report, 'route_conflicts'))->toBe('warn')
        ->and(statusOf($report, 'static_robots_txt'))->toBe('warn')
        ->and(statusOf($report, 'static_sitemap_xml'))->toBe('ok');
});

it('prints a table for people', function () {
    $this->artisan('xerads:doctor')
        ->expectsOutputToContain('webhook_route')
        ->expectsOutputToContain('Nothing is broken.')
        ->assertSuccessful();
});

it('warns when stored widgets would never get their loader', function () {
    config(['xerads.widgets.inject_loader' => false]);

    [$code, $report] = doctor();

    expect($code)->toBe(0)->and(statusOf($report, 'widget_loader'))->toBe('warn');

    config(['xerads.content.mapped.content_format' => 'shortcode']);

    expect(statusOf(doctor()[1], 'widget_loader'))->toBe('ok');
});
