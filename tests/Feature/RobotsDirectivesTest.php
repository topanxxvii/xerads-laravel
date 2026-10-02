<?php

/**
 * What a page tells search engines, in its robots tag and its X-Robots-Tag
 * header, and which decisions nothing can override.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Seo\RobotsDirectives;

function inProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

function robotsOf(string $head): ?string
{
    return metaContent($head, 'robots')[0] ?? null;
}

it('writes the directives with the preview limits from the settings', function () {
    expect((string) new RobotsDirectives)->toBe('index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1')
        ->and((string) RobotsDirectives::fromSettings(['max_snippet' => 160, 'max_image_preview' => 'standard', 'max_video_preview' => 0]))
        ->toBe('index, follow, max-snippet:160, max-image-preview:standard, max-video-preview:0')
        // Nothing to preview on a page that is not indexed.
        ->and((new RobotsDirectives)->with('noindex')->toString())->toBe('noindex, follow')
        ->and((new RobotsDirectives)->with('none')->toString())->toBe('noindex, nofollow')
        ->and((new RobotsDirectives)->with(['follow' => false])->toString())->toStartWith('index, nofollow');
});

it('keeps every page out of search engines outside production', function () {
    $response = headPage($this, '/about');

    expect(robotsOf(headOf($response)))->toBe('noindex, follow');
    $response->assertHeader('X-Robots-Tag', 'noindex, follow');

    // Unless the site says otherwise.
    config(['xerads.seo.noindex_non_production' => false]);
    $response = headPage($this, '/about');

    expect(robotsOf(headOf($response)))->toStartWith('index, follow');
    $response->assertHeaderMissing('X-Robots-Tag');
});

it('indexes in production, and sends no header that only repeats the default', function () {
    inProduction();

    $response = headPage($this, '/about');

    expect(robotsOf(headOf($response)))->toBe('index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1');
    $response->assertHeaderMissing('X-Robots-Tag');
});

it('keeps the kinds of page the settings name out of search engines', function (string $kind, string $setting, ?string $query) {
    inProduction();
    holdSettings(['robots' => ['noindex' => [$setting => true]]]);

    $response = headPage($this, '/kind'.($query ?? ''), fn () => Xerads::head()->page($kind, term: 'Rumah', query: 'kpr'));

    expect(robotsOf(headOf($response)))->toBe('noindex, follow');
    $response->assertHeader('X-Robots-Tag', 'noindex, follow');
})->with([
    'search' => ['search', 'search', null],
    'categories' => ['category', 'categories', null],
    'tags' => ['tag', 'tags', null],
    'paginated' => ['archive', 'paginated', '?page=2'],
]);

it('indexes those kinds when the settings allow it', function () {
    inProduction();
    holdSettings(['robots' => ['noindex' => ['categories' => false, 'tags' => false, 'search' => false, 'paginated' => false]]]);

    $head = headOf(headPage($this, '/kind?page=2', fn () => Xerads::head()->page('category', term: 'Rumah')));

    expect(robotsOf($head))->toStartWith('index, follow');
});

it('applies a path rule to the path and everything under it', function () {
    inProduction();
    holdSettings(['robots' => ['paths' => [
        ['pattern' => '/account', 'index' => false, 'follow' => false],
        ['pattern' => '/account/open', 'index' => true, 'follow' => true],
        ['pattern' => '/thanks/', 'index' => false, 'follow' => true],
        ['pattern' => '/drafts/*/edit', 'index' => false, 'follow' => true],
    ]]]);

    expect(robotsOf(headOf(headPage($this, '/account'))))->toBe('noindex, nofollow')
        // What the dashboard promises: "a path and everything under it".
        ->and(robotsOf(headOf(headPage($this, '/account/settings'))))->toBe('noindex, nofollow')
        ->and(robotsOf(headOf(headPage($this, '/account/settings/billing'))))->toBe('noindex, nofollow')
        // On a segment boundary only.
        ->and(robotsOf(headOf(headPage($this, '/accounts'))))->toStartWith('index, follow')
        // The longest pattern wins.
        ->and(robotsOf(headOf(headPage($this, '/account/open/faq'))))->toStartWith('index, follow')
        ->and(robotsOf(headOf(headPage($this, '/thanks'))))->toBe('noindex, follow')
        ->and(robotsOf(headOf(headPage($this, '/thanks-a-lot'))))->toStartWith('index, follow')
        // `*` is a wildcard, anywhere in the pattern.
        ->and(robotsOf(headOf(headPage($this, '/drafts/12/edit'))))->toBe('noindex, follow')
        ->and(robotsOf(headOf(headPage($this, '/drafts/12'))))->toStartWith('index, follow');

    // Also for a response without a head.
    Route::get('/account/statement.pdf', fn () => response('%PDF', 200, ['Content-Type' => 'application/pdf']));
    $this->get('/account/statement.pdf')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('never lets an article lift a noindex the settings or the route put on its path', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
    inProduction();
    holdSettings(['robots' => ['paths' => [['pattern' => '/blog', 'index' => false, 'follow' => true]]]]);

    // Every XerAds article says index: true, follow: true.
    deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

    expect(Article::sole()->xeradsSeo()?->robots)->toBe(['index' => true, 'follow' => true]);

    $response = $this->get('/blog/panduan-kpr-2026');

    expect(robotsOf(headOf($response)))->toBe('noindex, follow');
    $response->assertHeader('X-Robots-Tag', 'noindex, follow');
});

it('lets an article restrict itself', function () {
    inProduction();

    $head = headOf(headPage($this, '/post', fn () => Xerads::head()->for(['title' => 'Rahasia', 'robots' => ['index' => false, 'follow' => true]])));

    expect(robotsOf($head))->toBe('noindex, follow');
});

it('keeps a route\'s robots choice out of the controller\'s arguments', function () {
    inProduction();

    Route::middleware('web')->get('/settings/{tab?}', [RobotsProbeController::class, 'show'])->xeradsRobots('noindex');

    $response = $this->get('/settings');

    expect((string) $response->getContent())->toContain('tab=NULL')
        ->and(robotsOf(headOf($response)))->toBe('noindex, follow');
    $response->assertHeader('X-Robots-Tag', 'noindex, follow');
});

it('reads no database for the header of a response without a head', function () {
    Route::get('/health', fn () => response()->json(['ok' => true]));

    holdSettings(['robots' => ['paths' => [['pattern' => '/private', 'index' => false, 'follow' => true]]]]);

    // Outside production: decided without the settings.
    DB::enableQueryLog();
    $this->get('/health')->assertHeader('X-Robots-Tag', 'noindex, follow');
    expect(DB::getQueryLog())->toBe([]);

    // In production: the settings come from the cache once it is warm.
    inProduction();
    $this->get('/health');
    DB::flushQueryLog();

    $this->get('/health')->assertHeaderMissing('X-Robots-Tag');
    expect(DB::getQueryLog())->toBe([]);
});

it('keeps the whole site out when the settings say so, whatever a page says', function () {
    inProduction();
    holdSettings(['robots' => ['index_site' => false]]);

    $head = headOf(headPage($this, '/about', fn () => Xerads::head()->robots('index, follow')));

    expect(robotsOf($head))->toBe('noindex, follow');
});

it('never indexes a preview or a not-found page', function () {
    inProduction();

    expect(robotsOf(headOf(headPage($this, '/draft', fn () => Xerads::head()->preview()->robots('index')))))->toBe('noindex, nofollow')
        ->and(robotsOf(headOf(headPage($this, '/missing', fn () => Xerads::head()->page('not_found')))))->toBe('noindex, follow');
});

it('takes a route\'s own default', function () {
    inProduction();

    Route::middleware('web')->get('/account', fn () => Blade::render('<html><head>@xeradsHead</head></html>'))
        ->xeradsRobots('noindex, nofollow');

    $response = $this->get('/account');

    expect(robotsOf(headOf($response)))->toBe('noindex, nofollow');
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('leaves a header the response set itself alone', function () {
    Route::get('/feed', fn () => response('<rss/>', 200, ['X-Robots-Tag' => 'noarchive']));

    $this->get('/feed')->assertHeader('X-Robots-Tag', 'noarchive');
});

/** A controller with an optional parameter, as a site's own would have. */
class RobotsProbeController
{
    public function show(Request $request, ?string $tab = null): string
    {
        return Blade::render('<html><head>@xeradsHead</head><body>tab='.var_export($tab, true).'</body></html>');
    }
}
