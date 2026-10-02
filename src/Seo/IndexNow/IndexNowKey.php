<?php

namespace XerAds\Laravel\Seo\IndexNow;

use Illuminate\Contracts\Config\Repository;
use XerAds\Laravel\Seo\SettingsRepository;
use XerAds\Laravel\Sync\RemoteState;

/**
 * The site's IndexNow key: the one in the settings (`indexnow.key`, set by
 * XerAds), else the one pairing received. Served at `/{key}.txt`, where
 * search engines check that a submission came from the site.
 */
final class IndexNowKey
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RemoteState $state,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('xerads.indexnow.enabled', true)
            && $this->settings->get('indexnow.enabled', true) !== false;
    }

    public function current(): ?string
    {
        foreach ([$this->settings->string('indexnow.key'), $this->state->scalar('indexnow_key')] as $key) {
            if (is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key) === 1) {
                return $key;
            }
        }

        return null;
    }
}
