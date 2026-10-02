<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Support\StateStore;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * A nudge that something in the dashboard changed (`settings.updated`,
 * `redirects.updated`).
 *
 * The event carries only the new version number; the site pulls the data
 * itself, signed, so nothing sensitive travels in a push. Until the pull
 * arrives in a later release, the version is recorded, so the first sync
 * knows it is behind.
 */
abstract class VersionRecordingHandler implements EventHandler
{
    public function __construct(private readonly StateStore $state) {}

    abstract protected function stateKey(): string;

    public function handle(Envelope $envelope): WebhookReply
    {
        $version = $envelope->data['version'] ?? null;
        $version = is_int($version) ? $version : (is_string($version) && ctype_digit($version) ? (int) $version : null);

        if ($version !== null && $this->state->available()) {
            $this->state->put($this->stateKey(), $version);
        }

        return WebhookReply::ok(['version' => $version]);
    }
}
