<?php

namespace Workbench\App\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * A site's own routes under the blog's prefix, registered the way
 * routes/web.php is: while the application boots, after the package's
 * providers have booted.
 */
final class SiteRoutesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $router->middleware('web')->group(function (Router $router) {
            $router->get('blog/feed', fn () => 'the site\'s own feed')->name('site.feed');
        });
    }
}
