<?php

/**
 * Under Octane one application serves request after request. The head and
 * the breadcrumb trail are scoped bindings, flushed between requests as
 * Octane flushes them, and hold no static state: nothing one page set
 * reaches the next.
 */

use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Seo\Breadcrumbs\BreadcrumbTrail;
use XerAds\Laravel\Seo\HeadManager;
use XerAds\Laravel\Seo\SettingsRepository;

it('starts every request with a fresh head and trail', function () {
    $first = headOf(headPage($this, '/first', fn () => Xerads::head()->title('Halaman pertama')->noindex()->breadcrumbs()->push('Pertama')));

    expect($first)->toContain('<title>Halaman pertama - Laravel</title>')
        ->and($first)->toContain('"name":"Pertama"');

    $second = headOf(headPage($this, '/second'));

    expect($second)->not->toContain('Halaman pertama')
        ->and($second)->not->toContain('Pertama')
        ->and($second)->toContain('<title>Laravel</title>');
});

it('binds the head, the trail and the settings per request, the way Octane flushes them', function () {
    $head = app(HeadManager::class)->title('Dari permintaan sebelumnya');
    app(BreadcrumbTrail::class)->push('Sebelumnya');

    expect(app(HeadManager::class))->toBe($head);

    // What Octane does between requests.
    app()->forgetScopedInstances();

    expect(app(HeadManager::class))->not->toBe($head)
        ->and(app(HeadManager::class)->toArray()['title'])->toBe('Laravel')
        ->and(app(BreadcrumbTrail::class)->isEmpty())->toBeTrue();
});

it('holds no static state', function () {
    foreach ([HeadManager::class, BreadcrumbTrail::class, SettingsRepository::class] as $class) {
        $static = array_filter((new ReflectionClass($class))->getProperties(), fn (ReflectionProperty $property) => $property->isStatic());

        expect($static)->toBe([]);
    }
});
