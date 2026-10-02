<?php

namespace XerAds\Laravel\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Throwable;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Sync\AlreadyPaired;
use XerAds\Laravel\Sync\Client\Exceptions\PairingRejected;
use XerAds\Laravel\Sync\Client\Exceptions\XeradsApiException;
use XerAds\Laravel\Sync\Pairer;
use XerAds\Laravel\Sync\Synchronizer;

/**
 * `php artisan xerads:pair xpc_…`: connect this site to XerAds.
 *
 * The code comes from the XerAds dashboard and works once, within 30
 * minutes. The key it is exchanged for is stored encrypted in the database
 * and never printed; `--print-env` prints it once as a `.env` line instead,
 * for hosts that keep every secret in the environment. (Not `--env`: Artisan
 * already uses that name to pick the environment a command runs in.)
 */
final class PairCommand extends Command
{
    protected $signature = 'xerads:pair
        {code? : The pairing code from the XerAds dashboard (xpc_…)}
        {--rotate : Replace the key this site already holds}
        {--print-env : Print an XERADS_SITE_KEY line for .env instead of storing the key}
        {--show : Show which key this site holds (never the secret) and stop}';

    protected $description = 'Pair this site with XerAds';

    public function handle(Pairer $pairer, CredentialsResolver $credentials, Synchronizer $synchronizer, Repository $config): int
    {
        if ($this->option('show')) {
            return $this->show($credentials, $config);
        }

        $code = $this->argument('code');
        $code = is_string($code) && $code !== '' ? $code : (string) $this->secret('Pairing code from the XerAds dashboard');

        if (preg_match('/^xpc_[A-Za-z0-9_-]{8,}$/', trim($code)) !== 1) {
            $this->error('A pairing code starts with "xpc_". Copy it again from the XerAds dashboard.');

            return self::INVALID;
        }

        try {
            $result = $pairer->pair($code, (bool) $this->option('rotate'), store: ! $this->option('print-env'));
        } catch (AlreadyPaired $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (PairingRejected $exception) {
            $this->error('XerAds refused the code'.($exception->errorCode !== null ? " ({$exception->errorCode})" : '').': '.$exception->getMessage());

            return self::FAILURE;
        } catch (XeradsApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('print-env')) {
            $this->info('Paired as '.$result->credentials->masked().'. Add this line to .env (it is shown only now):');
            $this->newLine();
            $this->line('XERADS_SITE_KEY='.$result->credentials->siteKey());
            $this->newLine();

            if ($result->previous !== null) {
                $this->line('Move the key it replaces to XERADS_SITE_KEY_PREVIOUS for a day, so deliveries already signed with it still verify.');
            }

            $this->warnings($config);

            return self::SUCCESS;
        }

        $this->info('Paired as '.$result->credentials->masked().'.');

        if ($result->previous !== null) {
            $this->line('The previous key ('.$result->previous->masked().') stays valid for '.Pairer::PREVIOUS_KEY_HOURS.' hours.');
        }

        $this->warnings($config);
        $this->firstSync($synchronizer);

        return self::SUCCESS;
    }

    private function show(CredentialsResolver $credentials, Repository $config): int
    {
        try {
            $current = $credentials->current();
            $previous = $credentials->previous();
        } catch (InvalidSiteKey $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($current === null) {
            $this->line('This site is not paired with XerAds.');

            return self::SUCCESS;
        }

        $source = $this->fromEnvironment($config) ? 'XERADS_SITE_KEY' : 'the database';

        $this->line("Site:     {$current->siteId}");
        $this->line("Key:      {$current->keyId} (from {$source})");

        if ($previous !== null) {
            $this->line("Previous: {$previous->keyId}");
        }

        return self::SUCCESS;
    }

    /**
     * Report the key at once: the first heartbeat signed with it is what
     * makes XerAds sign deliveries with it. Then the first settings pull.
     */
    private function firstSync(Synchronizer $synchronizer): void
    {
        try {
            if ($synchronizer->run() === null) {
                $this->line('Another sync is running. XerAds switches to the new key at the next heartbeat (within the hour); `php artisan xerads:sync --heartbeat` sends one now.');

                return;
            }

            $this->line('Reported to XerAds and pulled the site\'s settings and redirects.');
        } catch (Throwable $exception) {
            $this->warn('Paired, but the first sync failed: '.$exception->getMessage().' It is retried automatically; `php artisan xerads:sync --full` tries now.');
        }
    }

    private function warnings(Repository $config): void
    {
        if ($this->fromEnvironment($config) && ! $this->option('print-env')) {
            $this->warn('XERADS_SITE_KEY is set in the environment and takes precedence over the stored key. Remove it, or put the new key there.');
        }

        if ($this->laravel instanceof CachesConfiguration && $this->laravel->configurationIsCached()) {
            $this->warn('The configuration is cached. Run `php artisan config:cache` again after changing .env, or `php artisan config:clear`.');
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('laravel/octane')) {
            $this->line('Octane workers keep their configuration: run `php artisan octane:reload` if you changed .env.');
        }
    }

    private function fromEnvironment(Repository $config): bool
    {
        $key = $config->get('xerads.credentials.key');

        return is_string($key) && trim($key) !== '';
    }
}
