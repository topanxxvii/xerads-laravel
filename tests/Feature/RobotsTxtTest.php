<?php

/**
 * /robots.txt from the settings, and nothing at all for crawlers outside
 * production.
 */

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

function inProductionForRobots(): void
{
    app()->detectEnvironment(fn () => 'production');
}

it('disallows everything outside production', function () {
    holdSettings(['robots' => ['txt' => ['rules' => [['user_agent' => '*', 'allow' => ['/'], 'disallow' => []]], 'extra' => '']]]);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent("User-agent: *\nDisallow: /\n");
});

it('writes the settings\' rules, the extra lines and the sitemap on the site\'s own address', function () {
    inProductionForRobots();
    holdSettings(['site' => ['url' => 'https://toko.test'], 'robots' => ['txt' => [
        'rules' => [
            ['user_agent' => '*', 'allow' => ['/'], 'disallow' => ['/admin', '/cart']],
            ['user_agent' => 'ContohBot', 'allow' => [], 'disallow' => ['/']],
        ],
        'extra' => "# Hubungi kami sebelum merayapi\nCrawl-delay: 5",
    ]]]);

    $this->get('http://evil.example/robots.txt')->assertOk()->assertContent(implode("\n", [
        'User-agent: *',
        'Allow: /',
        'Disallow: /admin',
        'Disallow: /cart',
        '',
        'User-agent: ContohBot',
        'Disallow: /',
        '',
        '# Hubungi kami sebelum merayapi',
        'Crawl-delay: 5',
        '',
        'Sitemap: https://toko.test/sitemap.xml',
    ])."\n");
});

it('prints nothing but robots.txt directives, whatever the settings hold', function () {
    inProductionForRobots();
    holdSettings(['robots' => ['txt' => [
        'rules' => [['user_agent' => "*\nDisallow: /", 'allow' => ['/ok', "/bad\nUser-agent: x", '//evil.example'], 'disallow' => []]],
        'extra' => "<script>alert(1)</script>\nNoindex: /secret\nDisallow: /private",
    ]]]);

    $body = (string) $this->get('/robots.txt')->getContent();

    expect($body)->not->toContain('<script>')
        ->and($body)->not->toContain('Noindex')
        ->and($body)->not->toContain('evil.example')
        ->and($body)->toContain('Disallow: /private')
        // The broken rule is dropped whole; the default stands in for it.
        ->and($body)->toStartWith("User-agent: *\nAllow: /\n");
});

it('leaves the sitemap line out when sitemaps are off', function () {
    inProductionForRobots();
    holdSettings(['sitemap' => ['enabled' => false]]);

    expect((string) $this->get('/robots.txt')->getContent())->not->toContain('Sitemap:');
});

it('leaves a site\'s own robots.txt route alone', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()], [RobotsRouteProvider::class]);

    $this->get('/robots.txt')->assertContent('milik situs');
});

/** A site that serves its own robots.txt. */
class RobotsRouteProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::get('robots.txt', fn () => response('milik situs', 200, ['Content-Type' => 'text/plain']));
    }
}
