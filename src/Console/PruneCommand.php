<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Throwable;
use XerAds\Laravel\Seo\NotFound\NotFoundEntry;
use XerAds\Laravel\Support\Tables;

/**
 * `php artisan xerads:prune`: what the package keeps that has outlived its
 * use.
 *
 * - 404 paths not seen for `monitor_404.retention_days`;
 * - delivery records older than `webhook.delivery_retention_days`, past the
 *   point where XerAds still retries them;
 * - the package's expired entries in a database cache store, which Laravel
 *   leaves in the table until they are read again.
 *
 * Scheduled daily unless `xerads.prune.scheduled` is off.
 */
final class PruneCommand extends Command
{
    protected $signature = 'xerads:prune';

    protected $description = 'Delete old 404 records, delivery records and expired cache entries';

    public function handle(Tables $tables, Repository $config, DatabaseManager $database): int
    {
        $notFound = 0;

        if ($tables->exists('not_found')) {
            $days = max(1, (int) $config->get('xerads.monitor_404.retention_days', 30));
            $notFound = NotFoundEntry::query()->where('last_seen_at', '<', Carbon::now()->subDays($days))->delete();
        }

        $deliveries = 0;

        if ($tables->exists('deliveries')) {
            $days = max(1, (int) $config->get('xerads.webhook.delivery_retention_days', 7));
            $deliveries = $tables->connection()->table($tables->name('deliveries'))
                ->where('received_at', '<', Carbon::now()->subDays($days))
                ->where('status', '!=', 'processing')
                ->delete();
        }

        $cache = $this->pruneCache($config, $database);

        $this->line("Pruned {$notFound} 404 path(s), {$deliveries} delivery record(s) and {$cache} expired cache entr".($cache === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }

    /** Expired package entries, when the cache store is a database table. */
    private function pruneCache(Repository $config, DatabaseManager $database): int
    {
        $name = $config->get('xerads.cache.store') ?: $config->get('cache.default');
        $store = is_string($name) ? $config->get("cache.stores.{$name}") : null;

        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            return 0;
        }

        try {
            $prefix = (string) ($store['prefix'] ?? $config->get('cache.prefix', ''));
            $connection = $store['connection'] ?? null;

            return $database->connection(is_string($connection) ? $connection : null)
                ->table((string) ($store['table'] ?? 'cache'))
                ->where('key', 'like', $prefix.$config->get('xerads.cache.prefix', 'xerads').':%')
                ->where('expiration', '<=', Carbon::now()->getTimestamp())
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }
}
