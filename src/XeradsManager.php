<?php

namespace XerAds\Laravel;

use Illuminate\Contracts\Container\Container;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\Version;

/**
 * What the `Xerads` facade answers.
 *
 * Collaborators are resolved on each call rather than held, because some of
 * them (the credentials) are bound per request and this manager outlives a
 * request under a long-running worker.
 */
final class XeradsManager
{
    public function __construct(private readonly Container $container) {}

    public function version(): string
    {
        return Version::VERSION;
    }

    public function contract(): int
    {
        return Version::CONTRACT;
    }

    /** @return list<string> */
    public function features(): array
    {
        return $this->container->make(Features::class)->enabled();
    }

    /** The site key in force, or null while the site is not paired. */
    public function credentials(): ?Credentials
    {
        return $this->container->make(CredentialsResolver::class)->current();
    }
}
