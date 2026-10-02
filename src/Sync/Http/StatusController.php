<?php

namespace XerAds\Laravel\Sync\Http;

use Illuminate\Http\JsonResponse;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\Version;

/**
 * `GET /xerads/v1/status`: public, unsigned, and so deliberately short.
 *
 * Enough for XerAds (and a person with a browser) to see that the package is
 * installed and which contract and features it speaks. No PHP or Laravel
 * versions and nothing about the configuration: those would tell a scanner
 * which known vulnerabilities to try.
 */
final class StatusController
{
    public function __invoke(Features $features): JsonResponse
    {
        return new JsonResponse([
            'plugin' => 'xerads',
            'platform' => 'laravel',
            'version' => Version::VERSION,
            'contract' => Version::CONTRACT,
            'features' => $features->enabled(),
        ]);
    }
}
