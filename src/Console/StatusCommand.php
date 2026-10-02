<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Throwable;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Diagnostics;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\Sync\EventRouter;
use XerAds\Laravel\Sync\RemoteState;

/**
 * `php artisan xerads:status`: this site's connection to XerAds at a glance.
 *
 * Which site and key (ids only, never the secret), the last delivery and
 * heartbeat, the settings and redirects versions held against those XerAds
 * announced, the sync's health and article counts. `--json` for scripts and
 * monitoring.
 */
final class StatusCommand extends Command
{
    protected $signature = 'xerads:status {--json : Print the status as JSON}';

    protected $description = 'Show this site\'s XerAds connection';

    public function handle(
        CredentialsResolver $credentials,
        RemoteState $state,
        EventRouter $router,
        Features $features,
        Diagnostics $diagnostics,
        Tables $tables,
        Repository $config,
    ): int {
        $status = $this->status($credentials, $state, $router, $features, $diagnostics, $tables, $config);

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->print($status);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function status(CredentialsResolver $credentials, RemoteState $state, EventRouter $router, Features $features, Diagnostics $diagnostics, Tables $tables, Repository $config): array
    {
        try {
            $current = $credentials->current();
            $previous = $credentials->previous();
            $keyError = null;
        } catch (InvalidSiteKey $exception) {
            $current = $previous = null;
            $keyError = $exception->getMessage();
        }

        $fromEnvironment = is_string($config->get('xerads.credentials.key')) && trim((string) $config->get('xerads.credentials.key')) !== '';
        $available = $state->available();
        $heartbeat = $available ? $state->get('heartbeat') : [];
        $latest = is_string($heartbeat['latest_plugin_version'] ?? null) && $heartbeat['latest_plugin_version'] !== '' ? $heartbeat['latest_plugin_version'] : null;
        $sync = $available ? $state->get('sync') : [];
        $settings = $available ? $state->document('settings') : [];
        $redirects = $available ? $state->document('redirects') : [];

        return [
            'paired' => $current !== null,
            'site_id' => $current?->siteId,
            'key_id' => $current?->keyId,
            'previous_key_id' => $previous?->keyId,
            'key_source' => $current === null ? null : ($fromEnvironment ? 'environment' : 'database'),
            'key_error' => $keyError,
            'revoked' => $available && $state->isRevoked(),
            'version' => Version::VERSION,
            'latest_version' => $latest,
            'outdated' => $latest !== null && version_compare(Version::VERSION, $latest, '<'),
            'contract' => Version::CONTRACT,
            'mode' => (string) $config->get('xerads.content.mode', 'mapped'),
            'features' => $features->enabled(),
            'events' => $router->events(),
            'last_delivery' => $this->lastDelivery($tables),
            'last_heartbeat' => $heartbeat === [] ? null : [
                'at' => $heartbeat['at'] ?? null,
                'status' => $heartbeat['status'] ?? null,
                'entitlements' => $heartbeat['entitlements'] ?? [],
            ],
            'settings' => [
                'version' => $available ? $state->heldVersion('settings') : null,
                'announced_version' => $available ? $state->announcedVersion('settings') : null,
                'fetched_at' => $settings['fetched_at'] ?? null,
            ],
            'redirects' => [
                'version' => $available ? $state->heldVersion('redirects') : null,
                'announced_version' => $available ? $state->announcedVersion('redirects') : null,
                'count' => is_array($redirects['data']['data'] ?? null) ? count($redirects['data']['data']) : 0,
                'fetched_at' => $redirects['fetched_at'] ?? null,
            ],
            'sync' => [
                'last_refresh_at' => $sync['last_refresh_at'] ?? null,
                'failures' => (int) ($sync['failures'] ?? 0),
                'next_refresh_at' => $sync['next_refresh_at'] ?? null,
                'last_error' => $sync['last_error'] ?? null,
                'heartbeat_error' => $sync['heartbeat_error'] ?? null,
            ],
            'articles' => $this->articleCounts($tables),
            'queue' => $diagnostics->queueDriver(),
        ];
    }

    /** @return array{event: string, status: string, received_at: string|null}|null */
    private function lastDelivery(Tables $tables): ?array
    {
        try {
            if (! $tables->exists('deliveries')) {
                return null;
            }

            $row = $tables->connection()->table($tables->name('deliveries'))->orderByDesc('received_at')->orderByDesc('id')->first(['event', 'status', 'received_at']);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : [
            'event' => (string) $row->event,
            'status' => (string) $row->status,
            'received_at' => $row->received_at !== null ? (string) $row->received_at : null,
        ];
    }

    /** @return array<string, int> */
    private function articleCounts(Tables $tables): array
    {
        try {
            if (! $tables->exists('content_map')) {
                return [];
            }

            /** @var array<string, int> $counts */
            $counts = ContentMapEntry::query()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')->map(fn ($total) => (int) $total)->all();

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $status */
    private function print(array $status): void
    {
        if ($status['key_error'] !== null) {
            $this->error((string) $status['key_error']);
        }

        if (! $status['paired']) {
            $this->line('Not paired with XerAds. Run `php artisan xerads:pair` with the code from the dashboard.');
        } else {
            $this->line("Site:       {$status['site_id']}");
            $this->line("Key:        {$status['key_id']} (from the {$status['key_source']})".($status['previous_key_id'] !== null ? ", previous {$status['previous_key_id']}" : ''));
        }

        if ($status['revoked']) {
            $this->error('XerAds removed this site\'s connection. Pair it again from the dashboard.');
        }

        $this->line("Package:    {$status['version']} (contract {$status['contract']}), mode {$status['mode']}");

        if ($status['outdated']) {
            $this->warn("Version {$status['latest_version']} is available: `composer update xerads/laravel`.");
        }

        $delivery = $status['last_delivery'];
        $this->line('Delivery:   '.($delivery !== null ? "{$delivery['event']} ({$delivery['status']}) at {$delivery['received_at']}" : 'none yet'));

        $heartbeat = $status['last_heartbeat'];
        $this->line('Heartbeat:  '.($heartbeat !== null ? "{$heartbeat['at']} ({$heartbeat['status']})" : 'none yet'));

        foreach (['settings', 'redirects'] as $document) {
            $held = $status[$document]['version'];
            $announced = $status[$document]['announced_version'];
            $this->line(str_pad(ucfirst($document).':', 12).($held !== null ? "version {$held}" : 'none held').($announced !== null && $announced !== $held ? " (XerAds has {$announced})" : ''));
        }

        $sync = $status['sync'];
        $this->line('Sync:       '.($sync['last_refresh_at'] !== null ? "last {$sync['last_refresh_at']}" : 'never'));

        if ($sync['failures'] > 0) {
            $this->warn("Sync failed {$sync['failures']} time(s) in a row; next try {$sync['next_refresh_at']}. {$sync['last_error']}");
        }

        if ($sync['heartbeat_error'] !== null) {
            $this->warn("The last heartbeat failed: {$sync['heartbeat_error']}");
        }

        $articles = $status['articles'];
        $this->line('Articles:   '.($articles === [] ? 'none' : implode(', ', array_map(fn ($state, $total) => "{$total} {$state}", array_keys($articles), $articles))));
        $this->line("Queue:      {$status['queue']}");
    }
}
