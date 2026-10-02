<?php

namespace XerAds\Laravel\Tests;

use ArrayObject;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Workbench\App\Models\Post;
use XerAds\Laravel\Facades\Xerads;
use XerAds\Laravel\Sync\Client\PairingClientFactory;
use XerAds\Laravel\XeradsServiceProvider;

/**
 * A Laravel app configured the way a legacy install is: the original
 * receiver's secret set, articles mapped onto the workbench Post model.
 *
 * The secret is the one pinned in the v1 golden fixture, so a test can replay
 * XerAds' exact request bytes and signature against the real route.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Config applied before the providers boot, for the few tests that need
     * a differently booted app. Set it through `rebootWith()`.
     *
     * @var array<string, mixed>
     */
    private static array $bootConfig = [];

    /** Boot without the test defaults below, like a real install. */
    private static bool $bootAsInstall = false;

    /** @var list<string> environment variables set for this test only */
    private static array $bootEnvironment = [];

    /** @var list<class-string> providers booted after the package's, like a site's own */
    private static array $bootProviders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->keepOffTheNetwork();
    }

    /**
     * Each request starts with fresh per-request services, as it does on a
     * real server (a new process, or Octane flushing scoped instances): one
     * test's second request must not see the first one's head or trail.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<mixed>  $parameters
     * @param  array<mixed>  $cookies
     * @param  array<mixed>  $files
     * @param  array<mixed>  $server
     * @param  string|null  $content
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app?->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Nothing in the suite may reach the network; a test that needs a
     * response fakes it.
     */
    private function keepOffTheNetwork(): void
    {
        Http::preventStrayRequests();

        // Pairing is sent with a Guzzle client of its own, which
        // Http::fake() does not reach: refused here too, answered by
        // fakeXerads().
        $this->app->instance(PairingClientFactory::class, new PairingClientFactory(function (RequestInterface $request): never {
            throw new RuntimeException('A pairing request to '.$request->getUri().' was not faked. Call fakeXerads() first.');
        }));
    }

    /**
     * Rebuild the application as a turnkey site: the mode is read when the
     * package boots (the blog's routes and migrations), as on a real site.
     * The rebuilt app is migrated, the blog's tables included.
     *
     * @param  array<string, mixed>  $config
     * @param  list<class-string>  $providers  booted after the package's, as a site's own are
     */
    public function bootTurnkey(array $config = [], array $providers = []): void
    {
        self::$bootConfig = ['xerads.content.mode' => 'turnkey'] + $config;
        self::$bootProviders = $providers;

        $this->refreshApplication();
        $this->keepOffTheNetwork();

        Artisan::call('migrate', [
            '--path' => [
                __DIR__.'/../database/migrations/core',
                __DIR__.'/../database/migrations/turnkey',
                __DIR__.'/../workbench/database/migrations',
            ],
            '--realpath' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::$bootEnvironment as $name) {
            putenv($name);
        }

        self::$bootConfig = [];
        self::$bootAsInstall = false;
        self::$bootEnvironment = [];
        self::$bootProviders = [];

        parent::tearDown();
    }

    /**
     * Rebuild the application with this config set over the test defaults,
     * after the package registered and before it boots.
     *
     * The rebuilt app gets a fresh in-memory database without the package
     * tables, so use this only in tests that do not touch the database.
     *
     * @param  array<string, mixed>  $config
     */
    protected function rebootWith(array $config): void
    {
        self::$bootConfig = $config;

        $this->refreshApplication();
    }

    /**
     * Rebuild the application the way an install boots it: from environment
     * variables and config files alone, without this class's defaults.
     *
     * `$config` stands in for config files the site published: it is in place
     * before the package registers, exactly like a file in config/. The
     * rebuilt app is migrated and runs inside a transaction that is rolled
     * back afterwards, so it may store rows.
     *
     * @param  array<string, string>  $environment
     * @param  array<string, mixed>  $config
     */
    protected function rebootAsInstall(array $environment, array $config = []): void
    {
        foreach ($environment as $name => $value) {
            putenv($name.'='.$value);
            self::$bootEnvironment[] = $name;
        }

        self::$bootConfig = $config;
        self::$bootAsInstall = true;

        $this->refreshApplication();

        Artisan::call('migrate', [
            '--path' => [__DIR__.'/../database/migrations/core', __DIR__.'/../workbench/database/migrations'],
            '--realpath' => true,
        ]);

        $connection = $this->app->make('db')->connection();
        $connection->beginTransaction();

        $this->beforeApplicationDestroyed(fn () => $connection->rollBack());
    }

    /**
     * Every warning logged from now on, collected as it is written.
     *
     * A listener rather than a mocked logger, so the framework's own logging
     * (a deprecation notice, say) still has a real logger to write to.
     *
     * @return ArrayObject<int, string>
     */
    protected function captureWarnings(): ArrayObject
    {
        $warnings = new ArrayObject;

        $this->app->make('events')->listen(MessageLogged::class, function (MessageLogged $event) use ($warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });

        return $warnings;
    }

    /** @return array<string, mixed> */
    public static function v1Fixture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/Fixtures/contract/v1/custom-payload.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    public static function v2Vectors(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/Fixtures/contract/v2/signature-v2-vectors.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    protected function getPackageProviders($app): array
    {
        /*
         * Testbench asks for the providers after loading config and before
         * registering them: the one point where config can stand in for a
         * file the site published. defineEnvironment() runs too late for
         * that, after the package has registered.
         */
        if (self::$bootAsInstall) {
            foreach (self::$bootConfig as $key => $value) {
                $app['config']->set($key, $value);
            }
        }

        return [XeradsServiceProvider::class, ...self::$bootProviders];
    }

    protected function getPackageAliases($app): array
    {
        return ['Xerads' => Xerads::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];

        $this->useTestDatabase($config);

        // A fixed, obviously fake key: stored credentials are encrypted with it.
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        // Log events still fire (see captureWarnings); nothing is written.
        $config->set('logging.default', 'null');

        // No DNS lookups from the suite; the address rules still apply.
        $config->set('xerads.http.verify_public_dns', false);

        // Images are copied only where a test asks for it (MediaMirrorTest).
        $config->set('xerads.media.mirror', false);

        if (self::$bootAsInstall) {
            return;
        }

        // Set after the package registered and before it boots, as a test
        // override rather than as a published file would be.
        $config->set('xerads.legacy.secret', self::v1Fixture()['secret']);
        $config->set('xerads.content.mapped.model', Post::class);
        $config->set('xerads.content.mapped.fields.image_url', 'image_url');
        $config->set('xerads.content.mapped.fields.keywords', 'keywords');
        $config->set('xerads.content.mapped.defaults', ['user_id' => 1]);
        $config->set('xerads.content.mapped.public_route', 'posts.show');

        foreach (self::$bootConfig as $key => $value) {
            $config->set($key, $value);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    /** @param  Router  $router */
    protected function defineRoutes($router): void
    {
        $router->get('/posts/{slug}', fn (string $slug) => $slug)->name('posts.show');
    }

    /**
     * SQLite in memory by default; CI's database matrix sets DB_CONNECTION
     * (mysql, mariadb, pgsql) and the DB_* variables Laravel's default
     * connections read.
     */
    private function useTestDatabase(Repository $config): void
    {
        $connection = getenv('DB_CONNECTION') ?: 'testing';

        if ($connection === 'testing' || $connection === 'sqlite') {
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            return;
        }

        $config->set('database.default', $connection);
    }
}
