<?php

namespace XerAds\Laravel\Sync;

/** What one sync did, for the command to print. */
final class SyncReport
{
    /** @var list<string>|null the heartbeat's actions, or null when none was sent */
    public ?array $heartbeat = null;

    /** `updated`, `unchanged`, or null when not pulled. */
    public ?string $settings = null;

    public ?string $redirects = null;
}
