<?php

namespace XerAds\Laravel\Sync\Handlers;

/** `redirects.updated`: the site's redirects changed in the dashboard. */
final class RedirectsUpdatedHandler extends VersionRecordingHandler
{
    protected function stateKey(): string
    {
        return 'redirects_version';
    }
}
