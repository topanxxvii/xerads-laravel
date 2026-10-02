<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Sync\Client\Exceptions\XeradsApiException;
use XerAds\Laravel\Sync\Synchronizer;
use XerAds\Laravel\Sync\SyncReport;

/**
 * `php artisan xerads:sync`: bring this site's copy of its XerAds settings
 * and redirects up to date.
 *
 * Without options it is the routine refresh the scheduler runs: it does
 * nothing until the copy is stale (or behind a change XerAds announced), and
 * waits out the backoff after a failure. The options run a part now.
 */
final class SyncCommand extends Command
{
    protected $signature = 'xerads:sync
        {--heartbeat : Report to XerAds now}
        {--settings : Pull the SEO settings now}
        {--redirects : Pull the redirects now}
        {--report-404 : Report the paths that answered 404}
        {--full : All of the above, now, whatever the backoff}';

    protected $description = 'Sync settings and redirects with XerAds';

    public function handle(Synchronizer $synchronizer, Tables $tables): int
    {
        if (! $synchronizer->canSync()) {
            $this->line('Nothing to sync: this site is not paired with XerAds (or XerAds removed it). Run `php artisan xerads:pair`.');

            return self::SUCCESS;
        }

        $full = (bool) $this->option('full');
        $heartbeat = $full || $this->option('heartbeat');
        $settings = $full || $this->option('settings');
        $redirects = $full || $this->option('redirects');
        $notFound = $full || $this->option('report-404');

        if ($notFound) {
            $this->line($tables->exists('not_found')
                ? 'Reporting 404s is not enabled in this release.'
                : '404 reports arrive with the 404 monitor in a later release; nothing to report.');
        }

        $explicit = $heartbeat || $settings || $redirects;

        // Only --report-404 was asked for: done above.
        if (! $explicit && $notFound) {
            return self::SUCCESS;
        }

        try {
            // Explicit parts run now, whatever the backoff; the routine
            // refresh respects it.
            $report = $explicit
                ? $synchronizer->run($heartbeat, $settings, $redirects)
                : $synchronizer->refreshIfDue();
        } catch (XeradsApiException $exception) {
            $this->error('The sync failed'.($exception->errorCode !== null ? " ({$exception->errorCode})" : '').': '.$exception->getMessage());
            $this->line('The last good copy is kept. The next attempt waits for the backoff; --full tries now.');

            return self::FAILURE;
        }

        if ($report === null) {
            $this->line($explicit
                ? 'Another sync is running; this one was skipped.'
                : 'Nothing is due: the copy is current, or a retry is waiting out its backoff. Use --full to sync now.');

            return self::SUCCESS;
        }

        $this->describe($report);

        return self::SUCCESS;
    }

    private function describe(SyncReport $report): void
    {
        if ($report->heartbeat !== null) {
            $this->line('Heartbeat sent'.($report->heartbeat !== [] ? '; XerAds asked for: '.implode(', ', $report->heartbeat) : '').'.');
        }

        foreach (['settings' => $report->settings, 'redirects' => $report->redirects] as $document => $outcome) {
            if ($outcome !== null) {
                $this->line(ucfirst($document).': '.($outcome === 'updated' ? 'updated' : 'already current').'.');
            }
        }
    }
}
