<?php

namespace XerAds\Laravel\Sync\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;
use XerAds\Laravel\Sync\Synchronizer;

/**
 * Brings this site's settings and redirects up to date, after the response.
 *
 * Dispatched after the response has been sent, so no visitor waits for
 * XerAds, and on hosts with no queue worker it still runs. It never throws:
 * a failure is recorded (and backed off from) by the Synchronizer, and a
 * page view must not end in an error because XerAds was unreachable.
 */
final class RefreshRemoteState
{
    use Dispatchable;

    /** @param  list<string>  $only  `settings` and/or `redirects`, after XerAds announced a change */
    public function __construct(
        public readonly array $only = [],
        public readonly bool $force = false,
    ) {}

    public function handle(Synchronizer $synchronizer): void
    {
        try {
            $synchronizer->refreshIfDue($this->only, $this->force);
        } catch (Throwable) {
            // Recorded in the sync state with a backoff; the heartbeat and
            // `xerads:status` report it.
        }
    }
}
