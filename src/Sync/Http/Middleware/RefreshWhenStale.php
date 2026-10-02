<?php

namespace XerAds\Laravel\Sync\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use XerAds\Laravel\Sync\Jobs\RefreshRemoteState;
use XerAds\Laravel\Sync\Synchronizer;

/**
 * Lets a site without cron keep its settings current.
 *
 * On a web request, when the cached next-check time has passed, a refresh is
 * dispatched to run after the response. The check is one cache read; the
 * next-check time is pushed forward at once, so a burst of requests
 * dispatches one refresh, not one each. The refresh decides for itself
 * (under a lock, against the stored state) whether anything is due.
 *
 * It runs on every page of the site, so nothing here may fail a page: a cache
 * outage counts as "not due". Turn it off with `xerads.sync.after_response`.
 * It stays off while the site's own test suite runs, which must not call
 * XerAds with the site's real key or overwrite what the dashboard shows.
 */
final class RefreshWhenStale
{
    /** Logged once per process, not once per page, while the cache is down. */
    private static bool $failureLogged = false;

    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->enabled()) {
            $this->refreshIfDue();
        }

        return $response;
    }

    private function enabled(): bool
    {
        if (! $this->config->get('xerads.sync.after_response', true)) {
            return false;
        }

        return ! $this->app->runningUnitTests() || (bool) $this->config->get('xerads.sync.after_response_in_tests', false);
    }

    private function refreshIfDue(): void
    {
        try {
            if (! $this->synchronizer->checkDue()) {
                return;
            }

            // Claimed now; the refresh sets the real next time when it runs.
            // Carbon itself, not now(): an application may have made
            // immutable dates its default.
            $this->synchronizer->scheduleNextCheck(Carbon::now()->addMinute());

            RefreshRemoteState::dispatchAfterResponse();
        } catch (Throwable $exception) {
            if (! self::$failureLogged) {
                self::$failureLogged = true;

                Log::debug('XerAds skipped its after-response refresh: '.$exception->getMessage());
            }
        }
    }
}
