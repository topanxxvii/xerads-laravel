<?php

namespace XerAds\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Http\Controllers\ArticleWebhookController;
use XerAds\CmsBridge\Http\Middleware\VerifyXerAdsSignature;
use XerAds\CmsBridge\Receivers\EloquentArticleReceiver;
use XerAds\Laravel\Compat\LegacyConfig;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Diagnostics;
use XerAds\Laravel\Support\Features;
use XerAds\Laravel\Support\Signature\V1Verifier;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Support\Signature\V2Verifier;
use XerAds\Laravel\Support\StateStore;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Support\UrlGuard;
use XerAds\Laravel\Sync\DeliveryLedger;
use XerAds\Laravel\Widgets\WidgetExpander;

/**
 * Wire the package into the host application.
 *
 * The only provider package discovery registers. Everything else — articles,
 * SEO, widgets, sync — hangs off it as a module provider, registered only
 * when `xerads.modules.<name>` is on, so a module a site turned off costs
 * nothing at boot.
 *
 * The whole install stays `composer require` plus environment variables: the
 * routes register themselves, so there is no step a person can forget and
 * then spend an afternoon on.
 */
final class XeradsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/xerads.php', 'xerads');

        LegacyConfig::apply($this->app->make('config'), readEnvironment: ! $this->configurationIsCached());
        LegacyConfig::mirror($this->app->make('config'));

        $this->registerSupport();
        $this->registerLegacyReceiver();

        $this->registerModules();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/xerads.php' => $this->app->configPath('xerads.php'),
            ], 'xerads-config');

            // The original receiver's config file, for sites that still keep
            // it. Its values are mapped onto `xerads.*` by LegacyConfig.
            $this->publishes([
                __DIR__.'/../config/xerads-cms.php' => $this->app->configPath('xerads-cms.php'),
            ], 'xerads-cms-config');

            /*
             * Published under the same file names, not re-dated: the migrator
             * keys migrations by name, so a published copy replaces the
             * package's own instead of running a second time.
             */
            $this->publishes([
                __DIR__.'/../database/migrations/core' => $this->app->databasePath('migrations'),
            ], 'xerads-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/core');

        $this->registerViews();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\DoctorCommand::class,
                Console\SimulateCommand::class,
            ]);
        }

        $this->registerLegacyRoute();
    }

    /**
     * Blade components and directives, always registered: a template written
     * with `<x-xerads::content>` must keep rendering when the widgets module
     * is turned off, and then simply prints no widgets.
     */
    private function registerViews(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'xerads');

        Blade::componentNamespace('XerAds\\Laravel\\View\\Components', 'xerads');

        Blade::directive('xeradsWidget', fn (string $expression): string => '<?php echo app(\\'.WidgetExpander::class.'::class)->render('.$expression.'); ?>');
    }

    /**
     * Each part of the package behind its `xerads.modules.<name>` switch, so a
     * part a site turned off registers no routes, middleware or listeners.
     */
    private function registerModules(): void
    {
        $modules = (array) $this->app->make('config')->get('xerads.modules', []);

        foreach ([
            'content' => Content\ContentServiceProvider::class,
            'widgets' => Widgets\WidgetsServiceProvider::class,
            'sync' => Sync\SyncServiceProvider::class,
        ] as $module => $provider) {
            if (($modules[$module] ?? true) !== false) {
                $this->app->register($provider);
            }
        }
    }

    /**
     * Shared services.
     *
     * Stateless ones are singletons. Anything that remembers a database read
     * — table existence, the stored site key — is scoped, so a long-running
     * worker starts each request fresh and sees a rotated key or a newly run
     * migration without a restart.
     */
    private function registerSupport(): void
    {
        $this->app->singleton(XeradsManager::class);
        $this->app->singleton(Features::class);
        $this->app->singleton(UrlGuard::class);
        $this->app->singleton(V2Signer::class);
        $this->app->singleton(V1Verifier::class);

        $this->app->scoped(Tables::class);
        $this->app->scoped(StateStore::class);
        $this->app->scoped(DeliveryLedger::class);
        $this->app->scoped(V2Verifier::class);
        $this->app->bind(Diagnostics::class);

        $this->app->scoped(CredentialsResolver::class, function (Application $app): CredentialsResolver {
            $class = $app->make('config')->get('xerads.credentials.resolver');

            if (is_string($class) && $class !== '') {
                if (! is_a($class, CredentialsResolver::class, true)) {
                    throw new InvalidArgumentException(
                        'xerads.credentials.resolver must name a class extending '.CredentialsResolver::class.", {$class} does not."
                    );
                }

                return $app->make($class);
            }

            return new CredentialsResolver($app->make('config'), $app->make(StateStore::class), $app->make('encrypter'));
        });
    }

    /**
     * Bound, not final. `EloquentArticleReceiver` covers a site whose articles
     * are rows in one model; anything else — a page builder, a static
     * generator, a queue — binds its own implementation in AppServiceProvider
     * and inherits the signature check, the replay protection, the test
     * handling and the response contract unchanged.
     */
    private function registerLegacyReceiver(): void
    {
        $this->app->bind(ArticleReceiver::class, EloquentArticleReceiver::class);
    }

    /**
     * The original custom endpoint, `POST /api/xerads/articles`.
     *
     * Registered only while a legacy secret is set, so a site that never used
     * it exposes nothing. Setting the route to null keeps the receiver and
     * the middleware but no route, for a site that mounts its own controller.
     */
    private function registerLegacyRoute(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $config = $this->app->make('config');
        $secret = $config->get('xerads.legacy.secret');
        $path = $config->get('xerads.legacy.route');

        if (! is_string($secret) || trim($secret) === '' || ! is_string($path) || trim($path) === '') {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make('router');

        $router->middleware(array_merge(
            (array) $config->get('xerads.legacy.middleware', ['api']),
            [VerifyXerAdsSignature::class],
        ))->post($path, ArticleWebhookController::class)->name('xerads.articles.receive');
    }

    /**
     * `mergeConfigFrom`, but group by group.
     *
     * Laravel's merge is one level deep, so a published config/xerads.php from
     * an older release would replace a whole group and silently drop keys a
     * newer release added to it. Here nested groups merge too; lists (route
     * middleware, ignore patterns) are still replaced whole, because merging
     * two lists of middleware is never what anyone meant.
     */
    private function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set($key, self::mergeRecursively(require $path, (array) $config->get($key, [])));
    }

    /**
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $overrides
     * @return array<mixed>
     */
    private static function mergeRecursively(array $defaults, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            $default = $defaults[$name] ?? null;

            $defaults[$name] = is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? self::mergeRecursively($default, $value)
                : $value;
        }

        return $defaults;
    }

    private function configurationIsCached(): bool
    {
        return $this->app instanceof CachesConfiguration && $this->app->configurationIsCached();
    }
}
