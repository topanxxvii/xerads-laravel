<?php

namespace XerAds\Laravel\Content\Media;

use RuntimeException;

/**
 * An image that was not copied: an address the guard refused, a download
 * that failed or grew too large, or a file that is not an allowed image.
 * The page keeps showing the original address.
 */
final class MediaRefused extends RuntimeException {}
