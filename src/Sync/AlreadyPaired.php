<?php

namespace XerAds\Laravel\Sync;

use RuntimeException;
use XerAds\Laravel\Support\Credentials;

/**
 * A pairing without `--rotate` on a site that already holds a key. Pairing
 * replaces the key, so it is never done by accident.
 */
final class AlreadyPaired extends RuntimeException
{
    public function __construct(public readonly Credentials $current)
    {
        parent::__construct('This site is already paired as '.$current->masked().'. Pair again with --rotate to replace its key.');
    }
}
