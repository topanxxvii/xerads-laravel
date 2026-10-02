<?php

namespace XerAds\Laravel\Support;

/**
 * The package version and the contract it speaks.
 *
 * Constants rather than Composer's installed-versions lookup, because the
 * version is reported to XerAds on every ping and heartbeat and must not
 * depend on how the package was installed (path repository, dist, source).
 */
final class Version
{
    public const VERSION = '1.0.0-dev';

    /**
     * The site contract this package implements. XerAds sends only what a
     * plugin declared; a delivery for another contract is refused rather
     * than half understood.
     */
    public const CONTRACT = 2;

    private function __construct() {}
}
