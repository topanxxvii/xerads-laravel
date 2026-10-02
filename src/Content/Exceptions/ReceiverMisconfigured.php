<?php

namespace XerAds\Laravel\Content\Exceptions;

use RuntimeException;

/**
 * The receiver cannot store articles as configured: a model that does not
 * exist, a column the table lacks, a NOT NULL column nothing fills.
 *
 * Answered as 422 `RECEIVER_MISCONFIGURED`, which XerAds shows the site owner
 * and does not retry: the same delivery would fail the same way until someone
 * changes the site. A RuntimeException, so code written for the original
 * receiver, which caught those, still does.
 */
final class ReceiverMisconfigured extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $column = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
