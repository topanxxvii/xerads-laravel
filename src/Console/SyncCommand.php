<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use XerAds\Laravel\Seo\NotFound\NotFoundReporter;
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

    public function handle(Synchronizer $synchronizer, NotFoundReporter $notFoundReporter): int
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
        $explicit = $heartbeat || $settings || $redirects;

        // Only --report-404 was asked for.
        if (! $explicit && $notFound) {
            return $this->reportNotFound($notFoundReporter, explicit: true);
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
        } else {
            $this->describe($report);
        }

        // The routine run (the scheduler's) reports the 404s counted since.
        if ($notFound || ! $explicit) {
            return $this->reportNotFound($notFoundReporter, explicit: $notFound);
        }

        return self::SUCCESS;
    }

    private function reportNotFound(NotFoundReporter $reporter, bool $explicit): int
    {
        if (! $reporter->enabled()) {
            if ($explicit) {
                $this->line('404 reporting is off (monitor_404 in the settings or xerads.monitor_404).');
            }

            return self::SUCCESS;
        }

        try {
            $reported = $reporter->report();
        } catch (XeradsApiException $exception) {
            $this->error('The 404 report failed'.($exception->errorCode !== null ? " ({$exception->errorCode})" : '').': '.$exception->getMessage());

            return $explicit ? self::FAILURE : self::SUCCESS;
        }

        if ($explicit || $reported > 0) {
            $this->line($reported > 0 ? "Reported {$reported} path(s) that answered 404." : 'No new 404s to report.');
        }

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
