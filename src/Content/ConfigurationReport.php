<?php

namespace XerAds\Laravel\Content;

/**
 * What is wrong with a receiver's configuration, before an article finds out.
 *
 * Problems block: an article sent now would fail or land in the wrong place.
 * Warnings do not: the receiver can store articles, but something will not
 * work the way the site owner probably expects (no public link, a column the
 * table requires that only the model's own events might fill).
 */
final class ConfigurationReport
{
    /**
     * @param  list<string>  $problems
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $problems = [],
        public readonly array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->problems === [];
    }
}
