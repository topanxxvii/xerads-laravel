<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use SensitiveParameter;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\Sync\Client\Exceptions\ApiUnavailable;
use XerAds\Laravel\Sync\Client\XeradsClient;

/**
 * Pairing: a one-time code from the XerAds dashboard, exchanged for this
 * site's key (sites contract, "Pairing").
 *
 * The key is stored encrypted in the database, where every server of the
 * site reads it and `config:cache` cannot freeze an old one. Pairing again
 * rotates it: XerAds keeps accepting the old key for a day, and this site
 * keeps it as the previous key for as long, so deliveries already signed
 * with it still verify.
 */
final class Pairer
{
    /** How long XerAds keeps a replaced key valid. */
    public const PREVIOUS_KEY_HOURS = 24;

    public function __construct(
        private readonly XeradsClient $client,
        private readonly CredentialsResolver $credentials,
        private readonly RemoteState $state,
        private readonly EventRouter $router,
        private readonly Features $features,
        private readonly Repository $config,
        private readonly UrlGenerator $urls,
        private readonly Application $app,
    ) {}

    /**
     * @param  bool  $store  false to only obtain the key (to put it in .env)
     *
     * @throws AlreadyPaired when a key is held and $rotate is false
     */
    public function pair(#[SensitiveParameter] string $code, bool $rotate = false, bool $store = true): PairingResult
    {
        $current = $this->currentOrNull();

        if ($current !== null && ! $rotate) {
            throw new AlreadyPaired($current);
        }

        // Checked before the code is spent: XerAds accepts it once.
        if ($store && ! $this->state->available()) {
            throw new \RuntimeException('The XerAds tables do not exist yet. Run `php artisan migrate`, then pair with the same code.');
        }

        $reply = $this->client->pair([
            'code' => trim($code),
            'site_url' => rtrim((string) $this->config->get('app.url'), '/'),
            'webhook_url' => $this->urls->route('xerads.webhook'),
            'plugin_version' => Version::VERSION,
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'mode' => (string) $this->config->get('xerads.content.mode', 'mapped'),
            'contract' => [Version::CONTRACT],
            'events' => $this->router->events(),
            'features' => $this->features->enabled(),
        ]);

        $credentials = $this->credentialsFrom($reply);
        $previous = $current !== null && ! $current->equals($credentials) ? $current : null;

        if ($store) {
            $this->credentials->store($credentials, $previous, $previous !== null ? Carbon::now()->addHours(self::PREVIOUS_KEY_HOURS) : null);
        }

        // Also when the key goes to .env: a revocation or another site's
        // documents must not outlive the pairing that replaced them.
        $this->remember($credentials, $reply);

        return new PairingResult($credentials, $previous, $reply);
    }

    private function currentOrNull(): ?Credentials
    {
        try {
            return $this->credentials->current();
        } catch (InvalidSiteKey) {
            // A broken key is no reason to refuse a pairing that replaces it.
            return null;
        }
    }

    /**
     * The key from XerAds' reply, checked: a reply whose parts disagree is
     * not stored, rather than stored and found broken at the first delivery.
     *
     * @param  array<string, mixed>  $reply
     */
    private function credentialsFrom(array $reply): Credentials
    {
        try {
            $credentials = Credentials::parse((string) ($reply['site_key'] ?? ''));
        } catch (InvalidSiteKey $exception) {
            throw new ApiUnavailable('XerAds answered the pairing without a usable site key: '.$exception->getMessage());
        }

        if (($reply['site_id'] ?? $credentials->siteId) !== $credentials->siteId || ($reply['key_id'] ?? $credentials->keyId) !== $credentials->keyId) {
            throw new ApiUnavailable('XerAds answered the pairing with a site key that does not match its own site and key ids.');
        }

        return $credentials;
    }

    /** @param  array<string, mixed>  $reply */
    private function remember(Credentials $credentials, array $reply): void
    {
        if (! $this->state->available()) {
            return;
        }

        $before = $this->state->get('site')['site_id'] ?? null;

        // Another site's settings and redirects are no copy of this one's;
        // the same site's are kept as the last known good copy.
        foreach ($before !== $credentials->siteId ? RemoteState::SITE_SCOPED_KEYS : ['site_status', 'sync'] as $key) {
            $this->state->forget($key);
        }

        $this->state->put('site', [
            'site_id' => $credentials->siteId,
            'key_id' => $credentials->keyId,
            'api_url' => is_string($reply['api_url'] ?? null) ? $reply['api_url'] : null,
            'delivery_mode' => is_string($reply['delivery_mode'] ?? null) ? $reply['delivery_mode'] : null,
            'paired_at' => Carbon::now()->toIso8601String(),
        ]);

        foreach (['settings_version', 'redirects_version'] as $key) {
            if (is_int($reply[$key] ?? null)) {
                $this->state->put($key, $reply[$key]);
            }
        }

        if (is_string($reply['indexnow_key'] ?? null) && preg_match('/^[a-f0-9]{32}$/', $reply['indexnow_key']) === 1) {
            $this->state->put('indexnow_key', $reply['indexnow_key']);
        }
    }
}
