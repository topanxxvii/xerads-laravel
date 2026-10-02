<?php

namespace XerAds\Laravel\Support;

use JsonSerializable;
use SensitiveParameter;
use Stringable;

/**
 * One site key: which site, which key, and the secret that signs with it.
 *
 * One string (`XERADS_SITE_KEY=xsk_{site_id}.{key_id}.{secret}`) rather than
 * three variables, because three variables is how one of them ends up from a
 * different pairing. The key id travels in every signed request, so a site
 * holding two keys during a rotation knows which one to check without trying
 * both.
 *
 * ── Why the secret is never printed ─────────────────────────────────────────
 * This object travels through logs, `dd()`, exception reports and queued job
 * payloads. Casting it to a string, dumping it or encoding it to JSON shows
 * the site and key ids — enough to tell two keys apart — and never the
 * secret. The full key is available only through `siteKey()`, by name.
 *
 * The secret is not even a property of the object. Plenty of tools read
 * properties directly and print private ones too, whatever `__debugInfo()`
 * says: the VarDumper behind `dd()`, Tinker and debug toolbars,
 * `var_export()`, an `(array)` cast. So the secret lives in a static
 * WeakMap keyed by the instance, which none of them walk, and which drops
 * the entry when the instance is garbage collected.
 */
final class Credentials implements JsonSerializable, Stringable
{
    public const PREFIX = 'xsk_';

    private const SITE_ID_PATTERN = '/^site_[0-9a-z]{26}$/';

    private const KEY_ID_PATTERN = '/^sk_[0-9a-z]{26}$/';

    private const SECRET_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/';

    private const MASK = '********';

    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $secrets = null;

    private function __construct(
        public readonly string $siteId,
        public readonly string $keyId,
        #[SensitiveParameter] string $secret,
    ) {
        self::secrets()[$this] = $secret;
    }

    /**
     * Read `xsk_{site_id}.{key_id}.{secret}`.
     *
     * Strict on purpose: a key with a stray quote, a trailing space from a
     * copy-paste or a missing part fails here, by name, instead of producing
     * signatures that never match for a reason nobody can see.
     *
     * @throws InvalidSiteKey
     */
    public static function parse(#[SensitiveParameter] string $siteKey): self
    {
        if (! str_starts_with($siteKey, self::PREFIX)) {
            throw InvalidSiteKey::because('it must start with "xsk_".');
        }

        $parts = explode('.', substr($siteKey, strlen(self::PREFIX)));

        if (count($parts) !== 3) {
            throw InvalidSiteKey::because('it must have exactly three parts separated by dots.');
        }

        [$siteId, $keyId, $secret] = $parts;

        if (preg_match(self::SITE_ID_PATTERN, $siteId) !== 1) {
            throw InvalidSiteKey::because('the site id must be "site_" followed by 26 lowercase letters or digits.');
        }

        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw InvalidSiteKey::because('the key id must be "sk_" followed by 26 lowercase letters or digits.');
        }

        if (preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            throw InvalidSiteKey::because('the secret must be 32 to 128 letters, digits, "-" or "_".');
        }

        return new self($siteId, $keyId, $secret);
    }

    /** Parse, or null for an empty value. A malformed value still throws. */
    public static function parseOrNull(#[SensitiveParameter] ?string $siteKey): ?self
    {
        $siteKey = trim((string) $siteKey);

        return $siteKey === '' ? null : self::parse($siteKey);
    }

    /** The HMAC secret. Named, so every use of it is easy to find. */
    public function secret(): string
    {
        return self::secrets()[$this];
    }

    /** The full key, for storing it. Never log this. */
    public function siteKey(): string
    {
        return self::PREFIX.$this->siteId.'.'.$this->keyId.'.'.$this->secret();
    }

    public function matchesKeyId(string $keyId): bool
    {
        return hash_equals($this->keyId, $keyId);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->siteKey(), $other->siteKey());
    }

    /** The key with its secret masked: safe to log and to show in a terminal. */
    public function masked(): string
    {
        return self::PREFIX.$this->siteId.'.'.$this->keyId.'.'.self::MASK;
    }

    public function __toString(): string
    {
        return $this->masked();
    }

    /** @return array{site_id: string, key_id: string, secret: string} */
    public function jsonSerialize(): array
    {
        return ['site_id' => $this->siteId, 'key_id' => $this->keyId, 'secret' => self::MASK];
    }

    /** @return array{siteId: string, keyId: string, secret: string} */
    public function __debugInfo(): array
    {
        return ['siteId' => $this->siteId, 'keyId' => $this->keyId, 'secret' => self::MASK];
    }

    /**
     * Refuse to be serialised.
     *
     * A serialised copy would carry the secret into a queue payload, a cache
     * entry or a session, none of which are encrypted by default. Pass the key
     * id and resolve the credentials again where they are needed.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('XerAds credentials cannot be serialised. Resolve them again where they are needed.');
    }

    /**
     * Not clonable: a clone would be a new key in the WeakMap, without a
     * secret. Credentials are immutable, so there is nothing to clone for.
     */
    private function __clone() {}

    /** @return \WeakMap<self, string> */
    private static function secrets(): \WeakMap
    {
        return self::$secrets ??= new \WeakMap;
    }
}
