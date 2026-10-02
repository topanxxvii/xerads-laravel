<?php

/**
 * The head shared with Inertia pages. Inertia is detected, never required.
 * The first test uses a stand-in for Inertia's response factory, flushed
 * between requests the way a long-running worker flushes Inertia's shared
 * props; the next one tells ShareHead that Inertia is missing; the rest run
 * against the real adapter, which the package's development dependencies
 * install (they skip where it is not installed).
 */

use Illuminate\Support\Facades\Route;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Seo\Inertia\ShareHead;
use XerAds\Laravel\Seo\Inertia\ShareHeadWithInertia;

/** What Inertia's ResponseFactory does with shared props, and nothing more. */
final class InertiaFactoryStandIn
{
    /** @var array<string, mixed> */
    public array $shared = [];

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function flushShared(): void
    {
        $this->shared = [];
    }
}

beforeEach(function () {
    $this->factory = new InertiaFactoryStandIn;
    app()->bind(ShareHead::class, fn () => new ShareHead(fn (string $key, Closure $value) => $this->factory->share($key, $value)));

    // An Inertia page: the prop is built when the page renders.
    Route::middleware(['web', ShareHeadWithInertia::class])->get('/inertia/{title}', function (string $title) {
        Xerads::head()->title($title);

        return response()->json(($this->factory->shared['xerads'] ?? fn () => null)());
    });
});

it('shares the head and the widget loader on every request, after a worker flushes them', function () {
    $first = $this->get('/inertia/Pertama')->json();

    expect($first['head']['title'])->toBe('Pertama - Laravel')
        ->and($first['head']['link'])->toBe([['rel' => 'canonical', 'href' => 'http://localhost/inertia/Pertama']])
        ->and($first['widgets']['loader_url'])->toBe('https://widgets.xerads.id/v1/loader.js');

    // What a long-running worker does before the next request.
    $this->factory->flushShared();
    app()->forgetScopedInstances();

    $second = $this->get('/inertia/Kedua')->json();

    expect($second['head']['title'])->toBe('Kedua - Laravel');
});

it('registers nothing without Inertia', function () {
    expect((new ShareHead(installed: false))->register())->toBeFalse();

    if (! ShareHead::available()) {
        expect((new ShareHead)->register())->toBeFalse();
    }
});

it('shares through Inertia when it is installed', function () {
    expect((new ShareHead)->register())->toBeTrue()
        ->and(call_user_func(['Inertia\\Inertia', 'getShared'], 'xerads'))->toBeInstanceOf(Closure::class);
})->skip(fn () => ! ShareHead::available(), 'Inertia is not installed.');

it('puts the head in every Inertia page\'s props, at page.props.xerads.head', function () {
    app()->bind(ShareHead::class, fn () => new ShareHead);

    Route::middleware('web')->get('/inertia-page/{title}', function (string $title) {
        Xerads::head()->title($title);

        return call_user_func(['Inertia\\Inertia', 'render'], 'Blog/Show');
    });

    $page = $this->get('/inertia-page/Artikel', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->json();

    expect($page['component'])->toBe('Blog/Show')
        ->and($page['props']['xerads']['head']['title'])->toBe('Artikel - Laravel')
        ->and($page['props']['xerads']['head']['link'])->toBe([['rel' => 'canonical', 'href' => 'http://localhost/inertia-page/Artikel']])
        ->and($page['props']['xerads']['head'])->toHaveKeys(['meta', 'jsonld'])
        ->and($page['props']['xerads']['widgets']['loader_url'])->toBe('https://widgets.xerads.id/v1/loader.js');
})->skip(fn () => ! ShareHead::available(), 'Inertia is not installed.');
