<?php

namespace XerAds\Laravel\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * What this installation can do, as XerAds needs to hear it.
 *
 * XerAds sends only the events a site declared, so this list is the switch
 * that keeps it from delivering articles to a site that turned content off.
 * Derived from config on every call rather than cached, because config can
 * change between requests under a long-running worker.
 */
final class Features
{
    public function __construct(private readonly Repository $config) {}

    /** @return list<string> */
    public function enabled(): array
    {
        $features = [];

        if ($this->contentEnabled()) {
            $features[] = 'articles';
        }

        return $features;
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->enabled(), true);
    }

    private function contentEnabled(): bool
    {
        return (bool) $this->config->get('xerads.modules.content', true)
            && $this->config->get('xerads.content.mode', 'mapped') !== 'off';
    }
}
