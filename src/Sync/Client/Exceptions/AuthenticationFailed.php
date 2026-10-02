<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

/**
 * XerAds did not accept this site's signature (`SITE_KEY_INVALID`,
 * `SITE_SIGNATURE_INVALID`, `SITE_REPLAY`): the key is unknown or rotated
 * out, or something altered the request on its way.
 */
final class AuthenticationFailed extends XeradsApiException {}
