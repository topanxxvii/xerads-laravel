<?php

namespace XerAds\Laravel\Seo;

/**
 * Fills a title template from the settings (`titles.*`).
 *
 * The tokens are a closed list, the same XerAds validates:
 * `{title} {sep} {site} {tagline} {term} {page} {query}`. Anything else in
 * braces is literal text. A separator left at either end, or next to another
 * one, once its neighbour came out empty is dropped: "{site} {sep} {tagline}"
 * without a tagline is "Site", not "Site -".
 *
 * Plain text in, plain text out; escaping is the renderer's.
 */
final class TitleTemplate
{
    public const TOKENS = ['{title}', '{sep}', '{site}', '{tagline}', '{term}', '{page}', '{query}'];

    /** Stands for a separator while the template is filled; no title contains it. */
    private const MARK = "\u{E000}";

    /** @param  array<string, string|null>  $values  by token name, without braces */
    public static function render(string $template, string $separator, array $values): string
    {
        $replacements = [];

        foreach (self::TOKENS as $token) {
            $name = trim($token, '{}');

            $replacements[$token] = $name === 'sep'
                ? ' '.self::MARK.' '
                : self::clean($values[$name] ?? '');
        }

        $filled = strtr($template, $replacements);

        // Separators with nothing but space (or another separator) between
        // them become one; one at either end goes.
        $filled = (string) preg_replace('/'.self::MARK.'(\s*'.self::MARK.')+/u', self::MARK, $filled);
        $filled = (string) preg_replace('/^\s*'.self::MARK.'|'.self::MARK.'\s*$/u', '', trim($filled));

        $title = str_replace(self::MARK, self::clean($separator), $filled);

        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }

    private static function clean(?string $value): string
    {
        return trim(str_replace(self::MARK, '', (string) preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
