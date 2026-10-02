<?php

namespace XerAds\Laravel\Content\Exceptions;

use RuntimeException;

/**
 * A write lost a race: two articles delivered at the same moment both chose
 * the same slug, and the database's unique index refused the second.
 *
 * Answered as 409, which XerAds retries in 30 seconds. By then the first
 * article holds the slug and the retry picks the next free one; nothing
 * about the site needs changing, so this must not be the non-retryable 422
 * a configuration problem gets.
 */
final class WriteConflict extends RuntimeException {}
