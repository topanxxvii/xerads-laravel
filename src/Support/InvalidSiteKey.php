<?php

namespace XerAds\Laravel\Support;

use InvalidArgumentException;

/**
 * A site key that is not in the `xsk_{site_id}.{key_id}.{secret}` shape.
 *
 * The message names the part that is wrong and never repeats the value: a
 * key pasted into the wrong variable is still a secret, and exception
 * messages end up in logs and error trackers.
 */
final class InvalidSiteKey extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self(
            'The XerAds site key is not in the expected format xsk_{site_id}.{key_id}.{secret}: '.$reason
        );
    }
}
