<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Throwable;
use XerAds\Laravel\Content\Models\ContentMapEntry;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Diagnostics;
use XerAds\Laravel\Support\ErrorScrubber;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\Sync\Client\XeradsClient;

/**
 * `POST /heartbeat`: this site reports in, and learns what changed.
 *
 * The report is what the XerAds dashboard shows about the site: versions,
 * mode, what it can do, the settings and redirects versions it holds, the
 * checks `xerads:doctor` runs, a few counts and the last sync error. The
 * reply says which versions are current and what to pull, and is stored.
 *
 * Signed with the key in use, it also confirms to XerAds that this site holds
 * that key: after a re-pair, XerAds switches to the new key on the first
 * heartbeat signed with it.
 */
final class HeartbeatReporter
{
    public function __construct(
        private readonly XeradsClient $client,
        private readonly RemoteState $state,
        private readonly CredentialsResolver $credentials,
        private readonly EventRouter $router,
        private readonly Features $features,
        private readonly Diagnostics $diagnostics,
        private readonly ErrorScrubber $scrubber,
        private readonly Tables $tables,
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    /**
     * Send the report and keep the reply.
     *
     * @return array<string, mixed> XerAds' reply
     */
    public function report(): array
    {
        $reply = $this->client->heartbeat($this->payload());

        $this->state->put('heartbeat', [
            'at' => Carbon::now()->toIso8601String(),
            'server_time' => $reply['server_time'] ?? null,
            'status' => $reply['status'] ?? null,
            'delivery_mode' => $reply['delivery_mode'] ?? null,
            'latest_plugin_version' => $reply['latest_plugin_version'] ?? null,
            'min_plugin_version' => $reply['min_plugin_version'] ?? null,
            'entitlements' => is_array($reply['entitlements'] ?? null) ? $reply['entitlements'] : [],
            'actions' => $this->actions($reply),
        ]);

        foreach (['settings', 'redirects'] as $document) {
            if (is_int($reply[$document.'_version'] ?? null)) {
                $this->state->put($document.'_version', $reply[$document.'_version']);
            }
        }

        return $reply;
    }

    /**
     * What the heartbeat says to pull: `pull_settings`, `pull_redirects`.
     *
     * @param  array<string, mixed>  $reply
     * @return list<string>
     */
    public function actions(array $reply): array
    {
        return array_values(array_filter(is_array($reply['actions'] ?? null) ? $reply['actions'] : [], 'is_string'));
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $checks = $this->diagnostics->pingChecks();

        return [
            'plugin_version' => Version::VERSION,
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'mode' => (string) $this->config->get('xerads.content.mode', 'mapped'),
            'contract' => [Version::CONTRACT],
            'events' => $this->router->events(),
            'features' => $this->features->enabled(),
            'key_id' => $this->credentials->current()?->keyId,
            'settings_version' => $this->state->heldVersion('settings'),
            'redirects_version' => $this->state->heldVersion('redirects'),
            'checks' => [
                // A static file in public/ is served before Laravel sees the
                // request, so it hides the package's own route.
                'robots_static_file' => $checks['robots_static_file'],
                'sitemap_static_file' => $checks['sitemap_static_file'],
                'storage_link' => $checks['storage_link'],
                'queue' => $checks['queue'],
                'app_url_https' => $checks['app_url_https'],
                'loader_injection' => (bool) $this->config->get('xerads.widgets.inject_loader', true),
                'receiver' => $checks['receiver'],
            ],
            'counts' => $this->counts(),
            'last_error' => $this->scrubber->scrub($this->lastError()),
        ];
    }

    /** @return array{articles: int|null, redirects: int, not_found_open: int|null} */
    private function counts(): array
    {
        $articles = null;

        try {
            if ($this->tables->exists('content_map')) {
                $articles = ContentMapEntry::query()->where('state', 'published')->count();
            }
        } catch (Throwable) {
            // A count is informational; a report without it is still a report.
        }

        // The document is `{version, data: [...]}`: the rules are its list.
        $redirects = $this->state->document('redirects')['data']['data'] ?? [];

        return [
            'articles' => $articles,
            'redirects' => is_array($redirects) ? count($redirects) : 0,
            // The 404 monitor arrives in a later release.
            'not_found_open' => null,
        ];
    }

    private function lastError(): ?string
    {
        $sync = $this->state->get('sync');
        $error = $sync['last_error'] ?? $sync['heartbeat_error'] ?? null;

        return is_string($error) ? $error : null;
    }
}
