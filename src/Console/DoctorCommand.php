<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Diagnostics;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Sync\RemoteState;

/**
 * `php artisan xerads:doctor`: everything that can stop articles or widgets
 * from reaching this site, checked without changing anything.
 *
 * Exits non-zero when something is broken (not merely worth a look), so it
 * can run in a deploy script. `--json` for machines.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'xerads:doctor {--json : Print the checks as JSON}';

    protected $description = 'Check this site\'s XerAds setup';

    /** @var list<array{check: string, status: string, message: string}> */
    private array $checks = [];

    public function handle(Diagnostics $diagnostics, CredentialsResolver $credentials, RemoteState $state, Repository $config): int
    {
        $this->checks = [];

        $this->checkCredentials($credentials, $state);
        $this->checkWebhook($diagnostics);
        $this->checkTables($diagnostics);
        $this->checkReceiver($diagnostics);

        $this->add('app_url', $diagnostics->appUrlIsHttps() ? 'ok' : 'warn', $diagnostics->appUrlIsHttps()
            ? 'APP_URL is https.'
            : 'APP_URL ('.$config->get('app.url').') is not https. XerAds pairs only with https sites, and links it records would be http.');

        $this->add('storage_link', $diagnostics->storageLinked() ? 'ok' : 'warn', $diagnostics->storageLinked()
            ? 'public/storage is linked.'
            : 'public/storage is not linked. Run `php artisan storage:link` so article images copied to this site can be served.');

        $queue = $diagnostics->queueDriver();
        $this->add('queue', $queue === 'sync' ? 'warn' : 'ok', $queue === 'sync'
            ? 'The queue driver is "sync": background work (image copies, search notifications) runs during XerAds\' request and can make it time out.'
            : "The queue driver is \"{$queue}\".");

        $this->checkWidgetLoader($config);

        foreach (['robots.txt', 'sitemap.xml'] as $file) {
            $static = $diagnostics->staticFile($file);
            $this->add('static_'.str_replace('.', '_', $file), $static ? 'warn' : 'ok', $static
                ? "public/{$file} exists. The web server serves it before Laravel, so the package's {$file} will never be seen. Rename it once you rely on the package's."
                : "No static public/{$file}.");
        }

        return $this->report();
    }

    private function checkCredentials(CredentialsResolver $credentials, RemoteState $state): void
    {
        try {
            $current = $credentials->current();
        } catch (InvalidSiteKey $exception) {
            $this->add('credentials', 'fail', $exception->getMessage());

            return;
        }

        if ($state->available() && $state->isRevoked()) {
            $this->add('credentials', 'fail', 'XerAds disconnected this site. Pair it again from the XerAds dashboard.');

            return;
        }

        $this->add('credentials', $current !== null ? 'ok' : 'warn', $current !== null
            ? 'Paired as '.$current->masked().'.'
            : 'Not paired with XerAds yet. Paired deliveries are refused until it is; the original endpoint works without pairing.');
    }

    private function checkWebhook(Diagnostics $diagnostics): void
    {
        if ($diagnostics->webhookRoute() === null) {
            $this->add('webhook_route', 'fail', 'The webhook route is not registered. Run `php artisan route:clear` (or route:cache again), and check that xerads.modules.sync is on.');

            return;
        }

        $web = $diagnostics->webhookWebMiddleware();

        $this->add('webhook_route', $web === [] ? 'ok' : 'fail', $web === []
            ? 'The webhook route runs outside the web middleware group.'
            : 'The webhook route runs browser middleware ('.implode(', ', $web).'); its CSRF check answers every delivery with 419. Keep the route out of the web group.');

        $conflicts = $diagnostics->routeConflicts();

        $this->add('route_conflicts', $conflicts === [] ? 'ok' : 'warn', $conflicts === []
            ? 'No other routes under the package\'s prefix.'
            : 'Routes of this site share the package\'s prefix: '.implode(', ', $conflicts).'. Change xerads.routes.prefix or move them.');
    }

    private function checkTables(Diagnostics $diagnostics): void
    {
        $missing = $diagnostics->missingTables();

        $this->add('migrations', $missing === [] ? 'ok' : 'fail', $missing === []
            ? 'The package\'s tables exist.'
            : 'Missing tables: '.implode(', ', $missing).'. Run `php artisan migrate`.');
    }

    private function checkReceiver(Diagnostics $diagnostics): void
    {
        $report = $diagnostics->receiverReport();

        if ($report === null) {
            $this->add('receiver', 'ok', 'Content is turned off; no articles are stored.');

            return;
        }

        foreach ($report->problems as $problem) {
            $this->add('receiver', 'fail', $problem);
        }

        foreach ($report->warnings as $warning) {
            $this->add('receiver', 'warn', $warning);
        }

        if ($report->problems === [] && $report->warnings === []) {
            $this->add('receiver', 'ok', 'The receiver can store articles.');
        }
    }

    /**
     * Stored bodies hold widget containers (content_format html), but
     * nothing adds the loader to pages that print them: the containers stay
     * empty unless the layout has `<x-xerads::scripts spa />`.
     */
    private function checkWidgetLoader(Repository $config): void
    {
        if (! $config->get('xerads.modules.widgets', true)) {
            return;
        }

        $unloaded = ! $config->get('xerads.widgets.inject_loader', true)
            && $config->get('xerads.content.mapped.content_format', 'html') === 'html';

        $this->add('widget_loader', $unloaded ? 'warn' : 'ok', $unloaded
            ? 'Stored articles hold widget containers, but xerads.widgets.inject_loader is off: put <x-xerads::scripts spa /> in the layout, or the widgets stay empty.'
            : 'Pages with widgets get the loader.');
    }

    private function add(string $check, string $status, string $message): void
    {
        $this->checks[] = ['check' => $check, 'status' => $status, 'message' => $message];
    }

    private function report(): int
    {
        $failed = array_filter($this->checks, fn (array $check) => $check['status'] === 'fail') !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => ! $failed, 'checks' => $this->checks], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Check', 'Status', 'Detail'], array_map(
            fn (array $check) => [$check['check'], strtoupper($check['status']), $check['message']],
            $this->checks,
        ));

        $failed
            ? $this->error('Something needs fixing before XerAds can deliver to this site.')
            : $this->info('Nothing is broken.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
