<?php

namespace XerAds\Laravel\Sync;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Tells the site's listeners what happened, after the fact.
 *
 * By the time a listener runs, the article is committed. A listener that
 * throws (a mail that cannot be sent, a search index that is down) must not
 * turn that into a failed delivery: XerAds would retry an event this site has
 * already applied, and never learn where the article is. The exception is
 * reported, like any other, and the delivery still succeeds.
 */
final class Announcer
{
    public function __construct(private readonly Dispatcher $events) {}

    public function announce(object ...$events): void
    {
        foreach ($events as $event) {
            try {
                $this->events->dispatch($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
