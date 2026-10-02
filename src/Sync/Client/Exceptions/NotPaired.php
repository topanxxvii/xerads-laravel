<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

/** This site holds no site key yet, so it has nothing to sign a request with. */
final class NotPaired extends XeradsApiException
{
    public function __construct()
    {
        parent::__construct('This site is not paired with XerAds yet. Run `php artisan xerads:pair` with the code from the dashboard.');
    }
}
