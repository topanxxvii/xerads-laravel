<?php

namespace XerAds\Laravel\Sync;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Seo\Redirects\RedirectSync;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\ErrorScrubber;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Sync\Client\Exceptions\ApiUnavailable;
use XerAds\Laravel\Sync\Client\Exceptions\SiteRevoked;
use XerAds\Laravel\Sync\Client\Exceptions\XeradsApiException;
use XerAds\Laravel\Sync\Client\XeradsClient;

/**
 * Keeps this site's copy of its XerAds settings and redirects current.
 *
 * ── When it runs ────────────────────────────────────────────────────────────
 * - On the scheduler, if the site has one (`xerads:sync`).
 * - After a response, when the copy is older than `sync.stale_after_minutes`:
 *   a site whose host never set up cron still catches up.
 * - Right away after XerAds announces a change (a push nudge).
 * One run at a time across all servers (a cache lock), and after a failure
 * not again until `next_refresh_at`, further out with every failure in a row.
 *
 * ── What it does ────────────────────────────────────────────────────────────
 * Pulls with the ETag of the copy held, so an unchanged document costs XerAds
 * a 304 and this site nothing. A failed pull keeps the last known good copy:
 * a site never loses its settings because XerAds was briefly unreachable.
 * About once an hour it also sends a heartbeat; a failed heartbeat is kept
 * apart from the refresh's own health (`heartbeat_error`).
 */
final class Synchronizer
{
    public const LOCK_SECONDS = 120;

    public const HEARTBEAT_MINUTES = 60;

    /** See graceSeconds(). */
    public const GRACE_SECONDS = 60;

    /** Seconds to wait after the 1st, 2nd, … failure in a row. */
    public const DEFAULT_BACKOFF = [60, 300, 900, 3600, 21600];

    public function __construct(
        private readonly XeradsClient $client,
        private readonly HeartbeatReporter $heartbeat,
        private readonly RemoteState $state,
        private readonly CredentialsResolver $credentials,
        private readonly ErrorScrubber $scrubber,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
        private readonly SettingsRepository $settings,
        private readonly RedirectSync $redirectSync,
    ) {}

    /** Paired, migrated, not revoked: is there anything to sync with? */
    public function canSync(): bool
    {
        try {
            return $this->state->available() && ! $this->state->isRevoked() && $this->credentials->current() !== null;
        } catch (InvalidSiteKey) {
            return false;
        }
    }

    /**
     * Is a routine refresh due? When the copy is stale or behind what XerAds
     * announced, and not while backing off from a failure.
     */
    public function due(): bool
    {
        $next = $this->state->nextRefreshAt();

        if ($next !== null && $next->isFuture()) {
            return false;
        }

        $last = $this->state->lastRefreshAt();

        return $last === null
            || $last->lte(Carbon::now()->subMinutes($this->staleMinutes())->addSeconds($this->graceSeconds()))
            || $this->state->behind('settings')
            || $this->state->behind('redirects');
    }

    /**
     * The routine refresh: a heartbeat when the last is an hour old, then
     * both documents. Nothing when not due, unless forced.
     *
     * @param  list<string>  $only  `settings` and/or `redirects` to pull just those (a nudge)
     */
    public function refreshIfDue(array $only = [], bool $force = false): ?SyncReport
    {
        $canSync = $this->canSync();

        if (! $canSync || (! $force && ! $this->due())) {
            $this->scheduleNextCheck($canSync ? $this->nextDueAt() : null);

            return null;
        }

        $heartbeat = $only === [] && ($this->state->lastHeartbeatAt()?->lt(Carbon::now()->subMinutes(self::HEARTBEAT_MINUTES)) ?? true);

        return $this->run(
            heartbeat: $heartbeat,
            settings: $only === [] || in_array('settings', $only, true),
            redirects: $only === [] || in_array('redirects', $only, true),
        );
    }

    /**
     * One sync, now, under the lock. Null when another run holds the lock.
     *
     * Only a pull of both documents counts as a refresh: it alone moves
     * `last_refresh_at` and clears the failures and the backoff. A heartbeat
     * or a single document (a nudge) says nothing about the rest of the copy,
     * so it must not hide a refresh that keeps failing.
     *
     * @throws XeradsApiException after recording the failure
     */
    public function run(bool $heartbeat = true, bool $settings = true, bool $redirects = true): ?SyncReport
    {
        return $this->locked(function () use ($heartbeat, $settings, $redirects): SyncReport {
            $startedAt = Carbon::now();
            $pulls = $settings || $redirects;
            $report = new SyncReport;

            $credentials = $this->credentials->current();

            if ($credentials !== null && $this->state->adopt($credentials)) {
                // Another site's settings were cached for the pages.
                $this->settings->forget();
            }

            if ($heartbeat) {
                try {
                    $report->heartbeat = $this->heartbeat->actions($this->heartbeat->report());
                } catch (XeradsApiException $exception) {
                    // When documents were to be pulled in this run too, the
                    // refresh failed with the heartbeat and backs off.
                    $this->recordFailure($exception, heartbeat: true, refresh: $pulls);

                    throw $exception;
                }

                $this->recordHeartbeat();
            }

            try {
                if ($settings) {
                    $report->settings = $this->pull('settings', $credentials?->siteId);
                }

                if ($redirects) {
                    $report->redirects = $this->pull('redirects', $credentials?->siteId);

                    // Into the table the redirects middleware reads, whenever
                    // the held document is not the one applied last.
                    $this->redirectSync->syncFromState();
                }
            } catch (XeradsApiException $exception) {
                $this->recordFailure($exception, heartbeat: false, refresh: true);

                throw $exception;
            }

            if ($settings && $redirects) {
                $this->recordRefresh($startedAt);
            }

            return $report;
        });
    }

    /**
     * Pull one document. `updated` when a new one arrived, `unchanged` when
     * the copy held is current.
     */
    private function pull(string $document, ?string $siteId): string
    {
        $held = $this->state->document($document);
        $etag = isset($held['data']) && is_string($held['etag'] ?? null) ? $held['etag'] : null;

        $result = $document === 'settings' ? $this->client->settings($etag) : $this->client->redirects($etag);

        if (! $result->modified) {
            if ($held !== []) {
                $this->state->merge($document, ['fetched_at' => Carbon::now()->toIso8601String()]);
            }

            return 'unchanged';
        }

        $data = (array) $result->document;
        $version = $data['version'] ?? null;

        if (! is_int($version) || ! $this->wellFormed($document, $data)) {
            // Keep the last good copy rather than replace it with one this
            // package cannot use.
            throw new ApiUnavailable("XerAds sent {$document} in a shape this package does not recognise.");
        }

        $this->state->put($document, [
            'site_id' => $siteId,
            'version' => $version,
            'etag' => $result->etag,
            'fetched_at' => Carbon::now()->toIso8601String(),
            'data' => $data,
        ]);

        if (($this->state->announcedVersion($document) ?? -1) < $version) {
            $this->state->put($document.'_version', $version);
        }

        if ($document === 'settings') {
            // Pages render with the new settings from the next request on.
            $this->settings->forget();
        }

        return 'updated';
    }

    /** @param  array<string, mixed>  $data */
    private function wellFormed(string $document, array $data): bool
    {
        return $document === 'settings'
            ? ($data['schema'] ?? null) === 'xerads.site_settings'
            : is_array($data['data'] ?? null) && array_is_list($data['data']);
    }

    /**
     * Both documents pulled: the copy is current as of the run's start, so
     * the next cron tick, a moment more than one period later, finds it stale.
     */
    private function recordRefresh(Carbon $startedAt): void
    {
        $this->state->merge('sync', [
            'last_refresh_at' => $startedAt->toIso8601String(),
            'failures' => 0,
            'next_refresh_at' => null,
            'last_error' => null,
            'last_error_at' => null,
        ]);

        $this->scheduleNextCheck($startedAt->copy()->addMinutes($this->staleMinutes())->subSeconds($this->graceSeconds()));
    }

    private function recordHeartbeat(): void
    {
        $sync = $this->state->get('sync');

        if (($sync['heartbeat_error'] ?? null) !== null) {
            $this->state->put('sync', array_merge($sync, ['heartbeat_error' => null, 'heartbeat_error_at' => null]));
        }
    }

    /**
     * @param  bool  $heartbeat  the heartbeat failed
     * @param  bool  $refresh  documents were to be pulled: back off
     */
    private function recordFailure(XeradsApiException $exception, bool $heartbeat, bool $refresh): void
    {
        if ($exception instanceof SiteRevoked) {
            $this->state->markRevoked();
        }

        $sync = $this->state->get('sync');
        $error = $this->scrubber->scrub(($exception->errorCode !== null ? $exception->errorCode.': ' : '').$exception->getMessage());
        $now = Carbon::now()->toIso8601String();

        if ($heartbeat) {
            $sync['heartbeat_error'] = $error;
            $sync['heartbeat_error_at'] = $now;
        }

        $next = null;

        if ($refresh) {
            $failures = (int) ($sync['failures'] ?? 0) + 1;
            $backoff = $this->backoff();
            $next = Carbon::now()->addSeconds($backoff[min($failures, count($backoff)) - 1]);

            $sync = array_merge($sync, [
                'failures' => $failures,
                'next_refresh_at' => $next->toIso8601String(),
                'last_error' => $error,
                'last_error_at' => $now,
            ]);
        }

        $this->state->put('sync', $sync);

        if ($next !== null) {
            $this->scheduleNextCheck($next);
        }
    }

    /** When the routine refresh is next due, for web requests to wait until. */
    private function nextDueAt(): Carbon
    {
        $backoff = $this->state->nextRefreshAt();

        if ($backoff !== null && $backoff->isFuture()) {
            return $backoff;
        }

        $stale = $this->state->lastRefreshAt()?->addMinutes($this->staleMinutes())->subSeconds($this->graceSeconds());

        return $stale !== null && $stale->isFuture() ? $stale : Carbon::now()->addMinutes($this->staleMinutes());
    }

    /**
     * When web requests should next consider a refresh, kept in the cache so
     * checking costs a cache read rather than a database query per request.
     *
     * Any Carbon: an application may have made immutable dates its default.
     */
    public function scheduleNextCheck(?CarbonInterface $at = null): void
    {
        $at = $at?->getTimestamp() ?? Carbon::now()->addMinutes($this->staleMinutes())->getTimestamp();

        $this->store()->put($this->dueKey(), $at, max(60, $at - Carbon::now()->getTimestamp() + 60));
    }

    /**
     * Has the cached next-check time passed (or is none known)? Redis and
     * some other stores hand a stored number back as a numeric string.
     */
    public function checkDue(): bool
    {
        $at = $this->store()->get($this->dueKey());

        return ! is_numeric($at) || (int) $at <= Carbon::now()->getTimestamp();
    }

    public function dueKey(): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':sync:due-at';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function locked(callable $callback): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        $lock = $store->lock((string) $this->config->get('xerads.cache.prefix', 'xerads').':sync', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** @return non-empty-list<int> */
    private function backoff(): array
    {
        $backoff = array_values(array_filter((array) $this->config->get('xerads.sync.backoff', self::DEFAULT_BACKOFF), 'is_int'));

        return $backoff !== [] ? $backoff : self::DEFAULT_BACKOFF;
    }

    private function staleMinutes(): int
    {
        return max(1, (int) $this->config->get('xerads.sync.stale_after_minutes', 15));
    }

    /**
     * How much younger than `stale_after_minutes` a copy may be and still be
     * refreshed. Cron starts a command a moment after its minute; without
     * this, a run every 15 minutes would find the copy a few seconds short of
     * 15 minutes old on every second tick, and refresh every 30.
     */
    private function graceSeconds(): int
    {
        return min(self::GRACE_SECONDS, intdiv($this->staleMinutes() * 60, 4));
    }

    private function store(): Cache
    {
        $name = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }
}
