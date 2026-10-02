<?php

/**
 * The head shared with Inertia pages. Inertia is detected, never required:
 * this package's own suite runs without it, so a stand-in for Inertia's
 * response factory receives what `Inertia::share` would, and is flushed
 * between requests the way a long-running worker flushes Inertia's.
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
    expect((new ShareHead)->register())->toBeFalse();
})->skip(fn () => ShareHead::available(), 'Inertia is installed here.');

it('shares through Inertia when it is installed', function () {
    expect((new ShareHead)->register())->toBeTrue()
        ->and(call_user_func(['Inertia\\Inertia', 'getShared'], 'xerads'))->toBeInstanceOf(Closure::class);
})->skip(fn () => ! ShareHead::available(), 'Inertia is not installed in this package\'s development dependencies.');
