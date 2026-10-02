<?php

namespace XerAds\Laravel\Support;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Which site key is in force, and which one is retiring.
 *
 * ── Where the key comes from ────────────────────────────────────────────────
 * 1. XERADS_SITE_KEY (config `xerads.credentials.key`), when set. A host that
 *    keeps every secret in the environment can pin the key there, and the
 *    environment then wins outright, previous key included.
 * 2. Otherwise the `credentials` row in `xerads_state`, encrypted with the
 *    application key. Pairing writes it there because the database is shared
 *    by every server behind a load balancer and is not frozen by
 *    `config:cache`, so a rotation reaches all of them at once.
 *
 * ── Why rotation keeps two keys ─────────────────────────────────────────────
 * XerAds signs a retried delivery with the key that was current when it first
 * tried. Accepting the previous key until it expires means a rotation never
 * fails a delivery that was already in flight.
 *
 * Resolved once per instance; the container binds this per request (scoped),
 * so a long-running worker sees a new key on its next request.
 *
 * Not final: `xerads.credentials.resolver` may name a subclass that reads a
 * vault instead, by overriding `read()` or `readFromDatabase()`.
 */
class CredentialsResolver
{
    public const STATE_KEY = 'credentials';

    private bool $resolved = false;

    private ?Credentials $current = null;

    private ?Credentials $previous = null;

    private ?CarbonInterface $previousExpiresAt = null;

    public function __construct(
        protected readonly Repository $config,
        protected readonly StateStore $state,
        protected readonly StringEncrypter $encrypter,
    ) {}

    public function current(): ?Credentials
    {
        $this->resolve();

        return $this->current;
    }

    /** The retiring key, or null once it has expired. */
    public function previous(): ?Credentials
    {
        $this->resolve();

        if ($this->previous === null) {
            return null;
        }

        if ($this->previousExpiresAt !== null && Carbon::now()->greaterThanOrEqualTo($this->previousExpiresAt)) {
            return null;
        }

        return $this->previous;
    }

    /** The current or still-valid previous key with this key id. */
    public function byKeyId(string $keyId): ?Credentials
    {
        foreach ([$this->current(), $this->previous()] as $credentials) {
            if ($credentials !== null && $credentials->matchesKeyId($keyId)) {
                return $credentials;
            }
        }

        return null;
    }

    public function configured(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Keep a key pair in the database, encrypted.
     *
     * The whole document is encrypted, not just the secrets, so the row
     * reveals nothing about the site to someone reading a database dump
     * without the application key.
     */
    public function store(Credentials $current, ?Credentials $previous = null, ?CarbonInterface $previousExpiresAt = null): void
    {
        $document = json_encode([
            'current' => $current->siteKey(),
            'previous' => $previous?->siteKey(),
            'previous_expires_at' => $previous !== null ? $previousExpiresAt?->toIso8601String() : null,
        ], JSON_THROW_ON_ERROR);

        $this->state->put(self::STATE_KEY, $this->encrypter->encryptString($document));

        $this->forget();
    }

    /** Drop what this instance remembered, so the next call reads again. */
    public function forget(): void
    {
        $this->resolved = false;
        $this->current = null;
        $this->previous = null;
        $this->previousExpiresAt = null;
    }

    /**
     * Read once, and remember only a complete result.
     *
     * Parsed into locals first: a malformed current key throws here on every
     * call, instead of throwing once and then quietly reading as "not paired".
     */
    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        [$current, $previous, $previousExpiresAt] = $this->read();

        $this->current = $current;
        $this->previous = $previous;
        $this->previousExpiresAt = $previousExpiresAt;
        $this->resolved = true;
    }

    /**
     * The current key, the previous key and when the previous one expires.
     *
     * @return array{0: Credentials|null, 1: Credentials|null, 2: CarbonInterface|null}
     *
     * @throws InvalidSiteKey for a malformed current key
     */
    protected function read(): array
    {
        $fromEnvironment = $this->config->get('xerads.credentials.key');

        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            $previous = $this->config->get('xerads.credentials.previous');

            return [
                Credentials::parse(trim($fromEnvironment)),
                $this->previousOrNull(is_string($previous) ? $previous : null, 'XERADS_SITE_KEY_PREVIOUS'),
                null,
            ];
        }

        return $this->readFromDatabase();
    }

    /**
     * @return array{0: Credentials|null, 1: Credentials|null, 2: CarbonInterface|null}
     *
     * @throws InvalidSiteKey for a malformed current key
     */
    protected function readFromDatabase(): array
    {
        $stored = $this->state->get(self::STATE_KEY);

        if (! is_string($stored) || $stored === '') {
            return [null, null, null];
        }

        try {
            $document = json_decode($this->encrypter->decryptString($stored), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            /*
             * Most often APP_KEY changed without the old one being listed in
             * APP_PREVIOUS_KEYS. Treat the site as unpaired, loudly, rather
             * than failing every request.
             */
            Log::warning('XerAds could not decrypt its stored site key. Pair the site again, or restore the previous APP_KEY.');

            return [null, null, null];
        }

        if (! is_array($document)) {
            return [null, null, null];
        }

        $expiresAt = $document['previous_expires_at'] ?? null;

        return [
            Credentials::parseOrNull(is_string($document['current'] ?? null) ? $document['current'] : null),
            $this->previousOrNull(is_string($document['previous'] ?? null) ? $document['previous'] : null, 'the stored key pair'),
            is_string($expiresAt) && $expiresAt !== '' ? Carbon::parse($expiresAt) : null,
        ];
    }

    /**
     * A malformed previous key is ignored, not fatal.
     *
     * It only matters for deliveries signed before a rotation; refusing to
     * verify anything, including requests signed with a perfectly good
     * current key, would turn a typo in a retiring key into an outage.
     */
    private function previousOrNull(#[SensitiveParameter] ?string $siteKey, string $source): ?Credentials
    {
        try {
            return Credentials::parseOrNull($siteKey);
        } catch (InvalidSiteKey $exception) {
            Log::warning('XerAds ignored the previous site key from '.$source.'. '.$exception->getMessage());

            return null;
        }
    }
}
