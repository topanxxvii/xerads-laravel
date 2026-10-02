<?php

/*
 * Technical SEO: robots.txt, the sitemaps, llms.txt and the IndexNow key.
 *
 * Outside every middleware group: no session, no cookies, nothing a crawler
 * would carry from one request to the next. Registered after the site's own
 * routes (SeoServiceProvider), so a site that serves its own robots.txt or
 * sitemap keeps it.
 */

use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Seo\Http\LlmsTxtController;
use XerAds\Laravel\Seo\Http\RobotsTxtController;
use XerAds\Laravel\Seo\IndexNow\Http\KeyController;
use XerAds\Laravel\Seo\Sitemap\Http\SitemapController;

// The router keeps the last route registered for a method and path, so one
// added here would silently replace the site's own.
$taken = static function (string $uri): bool {
    foreach (Route::getRoutes()->get('GET') as $route) {
        if ($route->uri() === $uri && $route->getDomain() === null) {
            return true;
        }
    }

    return false;
};

if (config('xerads.robots_txt.enabled', true) && ! $taken('robots.txt')) {
    Route::get('robots.txt', RobotsTxtController::class)->name('xerads.robots');
}

if (config('xerads.sitemap.enabled', true)) {
    if (! $taken('sitemap.xml')) {
        Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('xerads.sitemap.index');
    }

    Route::get('sitemaps/{source}-{page}.xml', [SitemapController::class, 'page'])
        ->where(['source' => '[a-z]+', 'page' => '[0-9]+'])
        ->name('xerads.sitemap.page');
}

if (! $taken('llms.txt')) {
    Route::get('llms.txt', LlmsTxtController::class)->name('xerads.llms');
}

if (config('xerads.indexnow.enabled', true)) {
    Route::get('{key}.txt', KeyController::class)->where('key', '[a-f0-9]{32}')->name('xerads.indexnow.key');
}
