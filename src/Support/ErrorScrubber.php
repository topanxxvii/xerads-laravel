<?php

namespace XerAds\Laravel\Support;

use Throwable;

/**
 * An error message made safe to send to XerAds or print.
 *
 * Heartbeats report the last sync error so the dashboard can show why a site
 * is behind. A raw exception message can carry a site key, an authorization
 * header or the server's directory layout; this keeps the reason and drops
 * those, and caps the length.
 */
final class ErrorScrubber
{
    public const MAX_LENGTH = 500;

    public function __construct(private readonly CredentialsResolver $credentials) {}

    public function scrub(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        foreach ($this->secrets() as $secret) {
            $message = str_replace($secret, '[hidden]', $message);
        }

        $message = (string) preg_replace('/xsk_[A-Za-z0-9_.\-]+/', 'xsk_[hidden]', $message);
        $message = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/i', '$1 [hidden]', $message);
        $message = (string) preg_replace('/((?:secret|password|token|signature)["\']?\s*[:=]\s*["\']?)[^\s"\',;]+/i', '$1[hidden]', $message);

        // Paths inside the application become relative; any other absolute
        // path says nothing XerAds needs about how the server is laid out.
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $message = str_replace($base, '', $message);
        $message = (string) preg_replace('#(?<![\w.:/])(?:/[\w.@\-]+){2,}/?#', '[path]', $message);
        $message = (string) preg_replace('#\b[A-Za-z]:\\\\(?:[\w.\-]+\\\\?)+#', '[path]', $message);

        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_substr($message, 0, self::MAX_LENGTH);
    }

    /** @return list<string> */
    private function secrets(): array
    {
        $secrets = [];

        try {
            foreach ([$this->credentials->current(), $this->credentials->previous()] as $credentials) {
                if ($credentials !== null) {
                    $secrets[] = $credentials->secret();
                }
            }
        } catch (Throwable) {
            // A malformed key cannot be in a message as a key; nothing to hide.
        }

        return $secrets;
    }
}
