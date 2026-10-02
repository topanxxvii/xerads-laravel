<?php

namespace XerAds\Laravel\Content\Exceptions;

use RuntimeException;

/**
 * A signed delivery whose data cannot be used: no article, no article id.
 *
 * Answered as 422 `INVALID_PAYLOAD`. The signature proves XerAds sent it, so
 * this is a contract mismatch, not an attack; retrying would not help.
 */
final class InvalidPayload extends RuntimeException {}
