<?php

namespace XerAds\Laravel\Sync\Client\Exceptions;

/**
 * XerAds refused the pairing code: unknown, used or expired
 * (`PAIRING_CODE_INVALID`), meant for another host (`SITE_URL_MISMATCH`), or
 * the site cannot receive deliveries (`WEBHOOK_INVALID`, `WEBHOOK_UNREACHABLE`,
 * `CONTRACT_UNSUPPORTED`). The message is XerAds' own and says which.
 */
final class PairingRejected extends XeradsApiException {}
