<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

/** XerAds asked this site to slow down (429). Retried later, never at once. */
final class RateLimited extends XeradsApiException {}
