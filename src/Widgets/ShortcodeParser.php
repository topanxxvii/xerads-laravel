<?php

namespace XerAds\Laravel\Widgets;

/**
 * Finds `[xerads_widget id="w_…" lang="id"]` in text.
 *
 * The shortcode is the form that survives every editor between XerAds and the
 * page (sites contract, "Content"), so it is parsed leniently: single, double,
 * HTML-encoded (`&quot;`) and typographic (“…”) quotes, any attribute order,
 * any case. `[[xerads_widget …]]` is an escaped literal, printed as
 * `[xerads_widget …]` so an article can show the syntax itself.
 *
 * Only the id and the language are read. There is no list of widget types
 * here, on purpose: the runtime decides what a widget is and whether it
 * shows, and gains new types without a package release.
 */
final class ShortcodeParser
{
    public const PATTERN = '/(\[)?\[xerads_widget\b([^\]]*)\](\])?/i';

    public const ID_PATTERN = '/^w_[a-z0-9]{16}$/';

    public const LANG_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';

    public static function validId(?string $id): bool
    {
        return $id !== null && preg_match(self::ID_PATTERN, $id) === 1;
    }

    public static function validLang(?string $lang): bool
    {
        return $lang !== null && preg_match(self::LANG_PATTERN, $lang) === 1;
    }

    /**
     * Every placeholder in the text, escaped ones excluded.
     *
     * @return list<Shortcode>
     */
    public function parse(string $text): array
    {
        return array_values(array_filter($this->segments($text), fn (string|Shortcode $segment) => $segment instanceof Shortcode));
    }

    /**
     * Replace each placeholder with whatever `$render` returns for it.
     *
     * Escaped placeholders become their literal text and never reach
     * `$render`. A lone extra bracket on one side is kept as text.
     *
     * @param  callable(Shortcode): string  $render
     */
    public function replace(string $text, callable $render): string
    {
        $replaced = '';

        foreach ($this->segments($text) as $segment) {
            $replaced .= $segment instanceof Shortcode ? $render($segment) : $segment;
        }

        return $replaced;
    }

    /**
     * The text cut into plain text and placeholders, in order.
     *
     * Text is returned as it is, whatever characters it holds (escaped
     * placeholders as their literal), so a caller can rebuild it exactly
     * with each placeholder replaced. Adjacent text is merged.
     *
     * @return list<string|Shortcode>
     */
    public function segments(string $text): array
    {
        if (stripos($text, 'xerads_widget') === false
            || ! preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return [$text];
        }

        $segments = [];
        $offset = 0;

        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $open = (string) $match[1][0];
            $close = (string) ($match[3][0] ?? '');
            $literal = substr((string) $whole, strlen($open), strlen((string) $whole) - strlen($open) - strlen($close));

            $segments[] = substr($text, $offset, $start - $offset);

            if ($open !== '' && $close !== '') {
                $segments[] = $literal;
            } else {
                $segments[] = $open;
                $segments[] = $this->shortcode((string) $match[2][0], $literal);
                $segments[] = $close;
            }

            $offset = $start + strlen((string) $whole);
        }

        $segments[] = substr($text, $offset);

        return $this->mergeText($segments);
    }

    /**
     * A placeholder from its attribute text (what follows `xerads_widget`).
     */
    public function shortcode(string $attributes, string $text = ''): Shortcode
    {
        $parsed = $this->attributes($attributes);
        $id = $parsed['id'] ?? null;
        $lang = $parsed['lang'] ?? null;

        return new Shortcode(
            self::validId($id) ? $id : null,
            self::validLang($lang) ? $lang : null,
            $text,
        );
    }

    /**
     * `id="…" lang='…'` into an array, whatever quotes the editor produced.
     *
     * @return array<string, string>
     */
    public function attributes(string $attributes): array
    {
        $attributes = html_entity_decode($attributes, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $attributes = str_replace(['“', '”', '„', '″', '‘', '’', '‚', '′'], ['"', '"', '"', '"', "'", "'", "'", "'"], $attributes);

        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/i', $attributes, $pairs, PREG_SET_ORDER);

        $parsed = [];

        foreach ($pairs as $pair) {
            $value = ($pair[2] ?? '') !== '' ? $pair[2] : ((($pair[3] ?? '') !== '') ? $pair[3] : ($pair[4] ?? ''));
            $parsed[strtolower($pair[1])] = trim($value);
        }

        return $parsed;
    }

    /** The canonical placeholder text, as XerAds' own editor writes it. */
    public static function text(string $id, ?string $lang = null): string
    {
        return '[xerads_widget id="'.$id.'"'.($lang !== null ? ' lang="'.$lang.'"' : '').']';
    }

    /**
     * @param  list<string|Shortcode>  $segments
     * @return list<string|Shortcode>
     */
    private function mergeText(array $segments): array
    {
        $merged = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $last = array_key_last($merged);

            if (is_string($segment) && $last !== null && is_string($merged[$last])) {
                $merged[$last] .= $segment;
            } else {
                $merged[] = $segment;
            }
        }

        return $merged;
    }
}
