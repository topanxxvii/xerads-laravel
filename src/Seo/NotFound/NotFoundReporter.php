<?php

namespace XerAds\Laravel\Seo\NotFound;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Sync\Client\Exceptions\XeradsApiException;
use XerAds\Laravel\Sync\Client\XeradsClient;

/**
 * Tells XerAds which paths answered 404 since the last report
 * (`POST /api/site/v1/not-found`), so the owner can redirect them from the
 * dashboard.
 *
 * Only what is new: each row sends the hits XerAds has not heard about yet,
 * and they are marked reported once XerAds accepted the batch. Batches of at
 * most 500 paths (XerAds' limit), paths only: no query string, no IP
 * address, no user agent. `monitor_404.report` in the settings turns it off.
 */
final class NotFoundReporter
{
    public const BATCH = 500;

    /** XerAds takes 30 reports a minute; one run never needs more than this. */
    private const MAX_BATCHES = 10;

    public function __construct(
        private readonly XeradsClient $client,
        private readonly SettingsRepository $settings,
        private readonly Tables $tables,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('xerads.monitor_404.enabled', true)
            && $this->settings->get('monitor_404.enabled', true) !== false
            && $this->settings->get('monitor_404.report', true) !== false
            && $this->tables->exists('not_found');
    }

    /**
     * @return int how many paths were reported
     *
     * @throws XeradsApiException
     */
    public function report(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $reported = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $rows = NotFoundEntry::query()
                ->whereColumn('hits', '>', 'reported_hits')
                ->orderByDesc('last_seen_at')
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $this->client->notFound($rows->map(fn (NotFoundEntry $row) => [
                'path' => $row->path,
                'hits' => $row->hits - $row->reported_hits,
                'first_seen_at' => $row->first_seen_at?->toIso8601String(),
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
                'referrer_host' => $row->last_referrer_host,
            ])->values()->all());

            $now = Carbon::now();

            foreach ($rows as $row) {
                // By what was sent: hits counted meanwhile go in the next report.
                NotFoundEntry::query()->whereKey($row->getKey())->toBase()
                    ->increment('reported_hits', $row->hits - $row->reported_hits, ['reported_at' => $now]);
            }

            $reported += $rows->count();

            if ($rows->count() < self::BATCH) {
                break;
            }
        }

        return $reported;
    }

    /** Paths still open, for the heartbeat; null when there is no monitor. */
    public function openCount(): ?int
    {
        if (! $this->tables->exists('not_found')) {
            return null;
        }

        return NotFoundEntry::query()->where('status', NotFoundEntry::OPEN)->count();
    }
}
