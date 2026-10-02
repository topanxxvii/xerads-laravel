<?php

namespace XerAds\Laravel\Seo\Inertia;

use Closure;
use XerAds\Laravel\Seo\HeadManager;
use XerAds\Laravel\Widgets\Runtime;

/**
 * For Inertia pages: the head, as data, in a shared `xerads` prop, so the
 * client can set it on every visit (`page.props.xerads.head`).
 *
 * Inertia is detected, never required: without `inertiajs/inertia-laravel`
 * nothing is registered. Shared on every request (ShareHeadWithInertia, in
 * the `web` group), because a long-running worker flushes Inertia's shared
 * props before each request. The prop is a closure, resolved when the page
 * renders, from the application serving that request, after its controller
 * said what the page is. The first, full page load still needs
 * `@xeradsHead` in the root view, so crawlers that do not run JavaScript see
 * the tags.
 */
final class ShareHead
{
    /** @param  (Closure(string, Closure): mixed)|null  $share  how to share a prop; Inertia::share by default */
    public function __construct(private readonly ?Closure $share = null) {}

    public static function available(): bool
    {
        return class_exists('Inertia\\Inertia');
    }

    public function register(): bool
    {
        $share = $this->share ?? (self::available() ? Closure::fromCallable(['Inertia\\Inertia', 'share']) : null);

        if ($share === null) {
            return false;
        }

        $share('xerads', fn () => self::props());

        return true;
    }

    /** @return array{head: array<string, mixed>, widgets: array{loader_url: string}} */
    public static function props(): array
    {
        return [
            'head' => app(HeadManager::class)->toArray(),
            'widgets' => ['loader_url' => app(Runtime::class)->loaderUrl()],
        ];
    }
}
