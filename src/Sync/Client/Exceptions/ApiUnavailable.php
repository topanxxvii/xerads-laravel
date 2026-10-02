<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

/**
 * XerAds could not be reached (no connection, a timeout, an address the
 * URL guard refused) or answered with something that is not its API: an
 * error page, a redirect, a 5xx. Worth retrying later; nothing about this
 * site's setup is known to be wrong.
 */
final class ApiUnavailable extends XeradsApiException {}
