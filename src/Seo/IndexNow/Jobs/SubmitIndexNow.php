<?php

namespace XerAds\Laravel\Seo\IndexNow\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;
use XerAds\Laravel\Seo\IndexNow\IndexNowQueue;
use XerAds\Laravel\Seo\IndexNow\IndexNowSubmitter;
use XerAds\Laravel\Support\PackageQueue;

/**
 * Submits everything IndexNowQueue collected since the last submission.
 * Queued with a delay of `indexnow.debounce_seconds`, so the changes of a
 * burst go in one request; without a worker, after the response.
 */
final class SubmitIndexNow implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public static function dispatchFor(Repository $config, int $debounceSeconds): void
    {
        PackageQueue::send(self::dispatch(), $config, $debounceSeconds);
    }

    public function handle(IndexNowQueue $queue, IndexNowSubmitter $submitter): void
    {
        try {
            $submitter->submit($queue->take());
        } catch (Throwable $exception) {
            Log::warning('XerAds could not submit changed addresses to IndexNow.', ['error' => mb_substr($exception->getMessage(), 0, 300)]);
        }
    }
}
