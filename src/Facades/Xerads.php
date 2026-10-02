<?php

namespace XerAds\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use XerAds\Laravel\XeradsManager;

/**
 * @method static string version()
 * @method static int contract()
 * @method static list<string> features()
 * @method static \XerAds\Laravel\Support\Credentials|null credentials()
 *
 * @see XeradsManager
 */
final class Xerads extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return XeradsManager::class;
    }
}
