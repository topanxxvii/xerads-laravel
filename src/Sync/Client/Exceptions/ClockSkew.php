<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

/**
 * This server's clock is more than five minutes from XerAds'
 * (`SITE_TIMESTAMP_SKEW`). Not a key problem: fix the clock and retry.
 */
final class ClockSkew extends XeradsApiException
{
    /** XerAds' time when it refused the request, as it reported it. */
    public function serverTime(): ?int
    {
        $time = $this->payload['server_time'] ?? null;

        return is_int($time) ? $time : null;
    }
}
