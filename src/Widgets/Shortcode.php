<?php

namespace XerAds\Laravel\Widgets;

/**
 * One `[xerads_widget id="…" lang="…"]` placeholder.
 *
 * `id` and `lang` are null when missing or malformed. A placeholder without a
 * valid id renders nothing: the runtime could not load it, and an empty box
 * on a live page is worse than no box.
 */
final class Shortcode
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $lang,
        /** The placeholder as written, for the escaped `[[…]]` form. */
        public readonly string $text,
    ) {}

    public function isValid(): bool
    {
        return $this->id !== null;
    }
}
