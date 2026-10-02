<?php

namespace XerAds\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use XerAds\Laravel\XeradsManager;

/**
 * @method static string version()
 * @method static int contract()
 * @method static list<string> features()
 * @method static \XerAds\Laravel\Support\Credentials|null credentials()
 * @method static \XerAds\Laravel\Seo\HeadManager head()
 * @method static \XerAds\Laravel\Seo\Breadcrumbs\BreadcrumbTrail breadcrumbs()
 * @method static \XerAds\Laravel\Seo\SettingsRepository settings()
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
