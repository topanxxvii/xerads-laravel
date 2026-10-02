<?php

namespace XerAds\Laravel\Sync\Handlers;

/** `settings.updated`: the site's SEO settings changed in the dashboard. */
final class SettingsUpdatedHandler extends VersionRecordingHandler
{
    protected function stateKey(): string
    {
        return 'settings_version';
    }
}
