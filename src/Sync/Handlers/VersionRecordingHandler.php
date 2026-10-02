<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Support\StateStore;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\Jobs\RefreshRemoteState;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * A nudge that something in the dashboard changed (`settings.updated`,
 * `redirects.updated`).
 *
 * The event carries only the new version number; the site pulls the data
 * itself, signed, so nothing sensitive travels in a push. The version is
 * recorded (so a later sync knows it is behind if this pull fails) and a
 * pull of that document runs after the response.
 */
abstract class VersionRecordingHandler implements EventHandler
{
    public function __construct(private readonly StateStore $state) {}

    abstract protected function stateKey(): string;

    /** `settings` or `redirects`. */
    private function document(): string
    {
        return str_replace('_version', '', $this->stateKey());
    }

    public function handle(Envelope $envelope): WebhookReply
    {
        $version = $envelope->data['version'] ?? null;
        $version = is_int($version) ? $version : (is_string($version) && ctype_digit($version) ? (int) $version : null);

        if ($version !== null && $this->state->available()) {
            $this->state->put($this->stateKey(), $version);
        }

        // Pull it now rather than at the next routine refresh: XerAds just
        // proved it is reachable, and the owner is waiting to see the change.
        RefreshRemoteState::dispatchAfterResponse([$this->document()], force: true);

        return WebhookReply::ok(['version' => $version]);
    }
}
