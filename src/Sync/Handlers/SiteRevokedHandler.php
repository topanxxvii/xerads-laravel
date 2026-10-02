<?php

namespace XerAds\Laravel\Sync\Handlers;

use Illuminate\Contracts\Config\Repository;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\StateStore;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\RemoteState;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * `site.revoked`: the site was disconnected in the dashboard.
 *
 * The stored key is deleted, so nothing signed with it is accepted again,
 * and the site is marked revoked for `xerads:doctor` to report. A key set
 * in the environment cannot be removed from here; the reply says so, and
 * XerAds has already stopped accepting it either way.
 */
final class SiteRevokedHandler implements EventHandler
{
    public function __construct(
        private readonly StateStore $state,
        private readonly RemoteState $remote,
        private readonly CredentialsResolver $credentials,
        private readonly Repository $config,
    ) {}

    public function handle(Envelope $envelope): WebhookReply
    {
        if ($this->state->available()) {
            // Recorded with the key in force before it is deleted: a key
            // from a later pairing is not the one XerAds removed.
            $this->remote->markRevoked($envelope->siteId);
            $this->state->forget(CredentialsResolver::STATE_KEY);
        }

        $this->credentials->forget();

        $fromEnvironment = $this->config->get('xerads.credentials.key');

        return WebhookReply::ok(array_filter([
            'state' => 'revoked',
            'message' => is_string($fromEnvironment) && trim($fromEnvironment) !== ''
                ? 'XERADS_SITE_KEY is set in this site\'s environment and must be removed there.'
                : null,
        ]));
    }
}
