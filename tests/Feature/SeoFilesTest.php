<?php

/**
 * robots.txt, the sitemaps, llms.txt and the IndexNow key stay reachable on
 * a site whose own routes include a catch-all, and the doctor and the
 * heartbeat say so when they are not.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Seo\Http\ServeSeoFiles;
use XerAds\Laravel\Sync\HeartbeatReporter;

const SEO_FILES_KEY = 'abababababababababababababababab';

/** A CMS-style page route: unknown slugs answer 404. */
class SlugPagesProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get('{slug}', fn (string $slug) => $slug === 'tentang' ? 'halaman tentang' : abort(404));
    }
}

/** A single-page app: every path is the app's page. */
class SpaCatchAllProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get('{any}', fn () => 'spa')->where('any', '.*');
    }
}

/** A site that serves its own robots.txt, and has no catch-all. */
class OwnRobotsProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get('robots.txt', fn () => response('milik situs', 200, ['Content-Type' => 'text/plain']));
    }
}

function shadowedChecks(): array
{
    app()->forgetScopedInstances();

    return array_intersect_key(app(HeartbeatReporter::class)->payload()['checks'], array_flip(['robots_route_shadowed', 'sitemap_route_shadowed', 'indexnow_key_route_shadowed']));
}

function doctorStatus(string $check): ?string
{
    Artisan::call('xerads:doctor', ['--json' => true]);

    foreach (json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['checks'] as $entry) {
        if ($entry['check'] === $check) {
            return $entry['status'];
        }
    }

    return null;
}

it('serves the package\'s files past a route of the site\'s with parameters', function (string $provider, string $fallthrough) {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()], [$provider]);
    holdSettings(['llms_txt' => ['enabled' => true]]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('Sitemap: http://localhost/sitemap.xml', false);
    $this->get('/llms.txt')->assertOk()->assertSee('# Toko Rumah', false);
    $this->get('/'.SEO_FILES_KEY.'.txt')->assertOk()->assertContent(SEO_FILES_KEY);

    // Nothing to list yet: the site's route answers, as it would have.
    expect($this->get('/sitemap.xml')->getStatusCode())->toBe($fallthrough === 'spa' ? 200 : 404);

    // Everything else is still the site's.
    expect((string) $this->get('/tentang')->getContent())->toBe($fallthrough === 'spa' ? 'spa' : 'halaman tentang');
    expect($this->get('/'.str_repeat('cd', 16).'.txt')->getStatusCode())->toBe($fallthrough === 'spa' ? 200 : 404);

    expect(shadowedChecks())->toBe(['robots_route_shadowed' => false, 'sitemap_route_shadowed' => false, 'indexnow_key_route_shadowed' => false]);
})->with([
    'a page route' => [SlugPagesProvider::class, '404'],
    'an SPA catch-all' => [SpaCatchAllProvider::class, 'spa'],
]);

it('serves a sitemap past a catch-all once there is something to list', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()], [SpaCatchAllProvider::class]);
    deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertSuccessful();

    // No suffix gets past a route that answers every path: the slug is kept,
    // where looking for a free one used to go on for ever.
    expect(Article::sole()->slug)->toBe('panduan-kpr-2026');

    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $this->get('/sitemaps/articles-1.xml')->assertOk()->assertSee('/blog/panduan-kpr-2026', false);
});

it('lets the site answer llms.txt while the package has none', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()], [SpaCatchAllProvider::class]);

    $this->get('/llms.txt')->assertOk()->assertContent('spa');
});

it('reports the paths a catch-all takes, with the middleware off', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey(), 'xerads.middleware.seo_files' => false], [SpaCatchAllProvider::class]);
    holdSettings();

    $this->get('/robots.txt')->assertContent('spa');

    expect(shadowedChecks())->toBe(['robots_route_shadowed' => true, 'sitemap_route_shadowed' => true, 'indexnow_key_route_shadowed' => true])
        ->and(doctorStatus('robots_route'))->toBe('warn')
        ->and(doctorStatus('sitemap_route'))->toBe('warn')
        ->and(doctorStatus('indexnow_key_route'))->toBe('warn');
});

it('reports nothing where the site added the middleware itself', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey(), 'xerads.middleware.global' => false], [SpaCatchAllProvider::class]);
    app(Kernel::class)->pushMiddleware(ServeSeoFiles::class);
    holdSettings();

    $this->get('/robots.txt')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect(shadowedChecks())->toBe(['robots_route_shadowed' => false, 'sitemap_route_shadowed' => false, 'indexnow_key_route_shadowed' => false]);
});

it('reports nothing for a site that answers robots.txt itself, or has no catch-all', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey(), 'xerads.middleware.seo_files' => false], [OwnRobotsProvider::class]);
    holdSettings();

    expect(shadowedChecks())->toBe(['robots_route_shadowed' => false, 'sitemap_route_shadowed' => false, 'indexnow_key_route_shadowed' => false])
        ->and(doctorStatus('robots_route'))->toBe('ok');
});
