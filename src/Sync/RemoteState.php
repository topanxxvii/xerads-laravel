<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Support\StateStore;

/**
 * What this site last learned from XerAds, kept in `xerads_state`.
 *
 * - `site`: which XerAds site this installation is paired as, and when.
 * - `settings`, `redirects`: the last documents pulled, with their version,
 *   ETag and the site they belong to. A failed or "not modified" pull leaves
 *   them as they are, so the site always has a last known good copy.
 * - `settings_version`, `redirects_version`: the newest versions XerAds has
 *   announced (a push nudge or a heartbeat), which may be ahead of what is held.
 * - `heartbeat`: XerAds' last reply to one: status, latest plugin version,
 *   entitlements, actions.
 * - `sync`: when this site last refreshed both documents, how often it has
 *   failed since, when it may try again, and the last heartbeat error.
 * - `site_status`: set when XerAds removed the site, with the key it removed.
 *
 * Everything but `indexnow_key` belongs to one XerAds site. The key in force
 * can change without a pairing on this database (a new XERADS_SITE_KEY), so
 * held documents and a revocation are only taken as this site's when they
 * name the site and key in force, and the first sync with another site's key
 * starts that site's state afresh (`adopt()`).
 *
 * The SEO output reads the settings from here, RedirectSync the redirects,
 * and the heartbeat the outcome of the last IndexNow submission.
 */
final class RemoteState
{
    public const STATE_KEYS = ['site', 'settings', 'redirects', 'redirects_applied', 'settings_version', 'redirects_version', 'heartbeat', 'sync', 'indexnow_key', 'indexnow_last', 'site_status'];

    /**
     * What another site's key starts afresh. `redirects_applied` names its
     * site, so it needs no reset.
     */
    public const SITE_SCOPED_KEYS = ['settings', 'redirects', 'settings_version', 'redirects_version', 'heartbeat', 'sync', 'site_status', 'indexnow_last'];

    public function __construct(
        private readonly StateStore $state,
        private readonly CredentialsResolver $credentials,
    ) {}

    public function available(): bool
    {
        return $this->state->available();
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $value = $this->state->get($key);

        return is_array($value) ? $value : [];
    }

    public function scalar(string $key): mixed
    {
        return $this->state->get($key);
    }

    /** @param  array<string, mixed>|int|string|null  $value */
    public function put(string $key, array|int|string|null $value): void
    {
        $this->state->put($key, $value);
    }

    public function forget(string $key): void
    {
        $this->state->forget($key);
    }

    /** @param  array<string, mixed>  $values */
    public function merge(string $key, array $values): void
    {
        $this->put($key, array_merge($this->get($key), $values));
    }

    /**
     * A held document (`settings`, `redirects`), or nothing when none is held
     * for the site whose key is in force. Another site's copy is no copy of
     * this one's, and its ETag must not turn a pull into a 304 that keeps it.
     *
     * @return array<string, mixed>
     */
    public function document(string $document): array
    {
        $held = $this->get($document);
        $siteId = $this->current()?->siteId;

        return $siteId !== null && ($held['site_id'] ?? null) === $siteId ? $held : [];
    }

    /**
     * Start afresh when the key in force is another site's than the one this
     * state was kept for. Called at the start of every sync.
     */
    /** @return bool whether the state was another site's, and was started afresh */
    public function adopt(Credentials $credentials): bool
    {
        $recorded = $this->get('site')['site_id'] ?? null;

        if ($recorded === $credentials->siteId) {
            return false;
        }

        // With no site recorded (a key set in .env, never paired here), what
        // is held came from deliveries signed with this very key.
        if ($recorded !== null) {
            foreach (self::SITE_SCOPED_KEYS as $key) {
                $this->forget($key);
            }
        }

        $this->put('site', [
            'site_id' => $credentials->siteId,
            'key_id' => $credentials->keyId,
            'api_url' => null,
            'delivery_mode' => null,
            'paired_at' => null,
        ]);

        return true;
    }

    /** Record that XerAds removed this site, and which key it removed. */
    public function markRevoked(?string $siteId = null): void
    {
        $current = $this->current();

        $this->put('site_status', [
            'status' => 'revoked',
            'site_id' => $siteId ?? $current?->siteId,
            'key_id' => $current?->keyId,
            'at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Did XerAds remove this site? Not once a key issued since is in force:
     * a new pairing (or another site's key) is not what was removed.
     */
    public function isRevoked(): bool
    {
        $status = $this->get('site_status');

        if (($status['status'] ?? null) !== 'revoked') {
            return false;
        }

        $current = $this->current();
        $revokedKey = $status['key_id'] ?? null;

        return $current === null || ! is_string($revokedKey) || $revokedKey === $current->keyId;
    }

    /** The version of the document held for this site, or null when there is none. */
    public function heldVersion(string $document): ?int
    {
        $version = $this->document($document)['version'] ?? null;

        return is_int($version) ? $version : null;
    }

    /** The newest version XerAds has announced for a document, if any. */
    public function announcedVersion(string $document): ?int
    {
        $version = $this->scalar($document.'_version');

        return is_int($version) ? $version : (is_string($version) && ctype_digit($version) ? (int) $version : null);
    }

    /** Is the held document missing, or older than one XerAds announced? */
    public function behind(string $document): bool
    {
        $held = $this->heldVersion($document);
        $announced = $this->announcedVersion($document);

        return $held === null || ($announced !== null && $announced > $held);
    }

    public function lastRefreshAt(): ?Carbon
    {
        return $this->time($this->get('sync')['last_refresh_at'] ?? null);
    }

    public function nextRefreshAt(): ?Carbon
    {
        return $this->time($this->get('sync')['next_refresh_at'] ?? null);
    }

    public function lastHeartbeatAt(): ?Carbon
    {
        return $this->time($this->get('heartbeat')['at'] ?? null);
    }

    private function current(): ?Credentials
    {
        try {
            return $this->credentials->current();
        } catch (InvalidSiteKey) {
            return null;
        }
    }

    private function time(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
