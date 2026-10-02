<?php

namespace XerAds\Laravel\Sync\Handlers;

use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\WebhookReply;

/** Handles one event of the site contract. */
interface EventHandler
{
    public function handle(Envelope $envelope): WebhookReply;
}
