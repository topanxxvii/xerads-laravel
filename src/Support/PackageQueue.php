<?php

namespace XerAds\Laravel\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Where the package's jobs go: `xerads.queue.connection` and `.queue`, after
 * the database commit. Where the queue is `sync` (no worker), a job runs
 * after the response instead, so no visitor and no XerAds delivery waits on
 * it.
 */
final class PackageQueue
{
    public static function send(PendingDispatch $pending, Repository $config, int $delaySeconds = 0): void
    {
        $connection = $config->get('xerads.queue.connection');
        $queue = $config->get('xerads.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $pending->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $pending->onQueue($queue);
        }

        if (self::driver($config) === 'sync') {
            $pending->afterResponse();

            return;
        }

        $pending->afterCommit();

        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    public static function driver(Repository $config): string
    {
        $connection = $config->get('xerads.queue.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : $config->get('queue.default');

        if (! is_string($connection)) {
            return 'sync';
        }

        $driver = $config->get("queue.connections.{$connection}.driver", $connection);

        return is_string($driver) ? $driver : 'sync';
    }
}
