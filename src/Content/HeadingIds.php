<?php

namespace XerAds\Laravel\Content;

use Illuminate\Support\Str;

/**
 * The id each `<h2>` and `<h3>` of an article gets, by the same rule XerAds
 * uses, so a link to `#apa-itu-kpr` reaches the same section whichever side
 * named it.
 *
 * Headings are taken in document order. A heading keeps its own id when that
 * id is valid (lowercase letters, digits and hyphens, starting with a letter
 * or a digit, at most 80 characters) and not taken yet. Otherwise its text is
 * slugged (`Apa itu KPR` becomes `apa-itu-kpr`) and cut to 80 characters, or
 * becomes `section` when nothing is left; a slug already taken gets `-2`,
 * `-3`, … appended, its base shortened so the whole stays within 80
 * characters. Ids used elsewhere in the document count as taken.
 */
final class HeadingIds
{
    public const PATTERN = '/^[a-z0-9][a-z0-9-]{0,79}$/';

    public const MAX_LENGTH = 80;

    /** @var array<string, true> */
    private array $used = [];

    /** @param  list<string>  $reserved  ids already used elsewhere in the document */
    public function __construct(array $reserved = [])
    {
        foreach ($reserved as $id) {
            $this->used[$id] = true;
        }
    }

    /** The id for the next heading in document order. */
    public function assign(?string $existing, string $text): string
    {
        $existing = $existing !== null ? trim($existing) : null;

        if ($existing !== null && preg_match(self::PATTERN, $existing) === 1 && ! isset($this->used[$existing])) {
            return $this->use($existing);
        }

        $base = Str::slug($text);
        $base = trim(substr($base, 0, self::MAX_LENGTH), '-');
        $base = $base !== '' ? $base : 'section';

        if (! isset($this->used[$base])) {
            return $this->use($base);
        }

        for ($n = 2; ; $n++) {
            $suffix = '-'.$n;
            $candidate = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-').$suffix;

            if (! isset($this->used[$candidate])) {
                return $this->use($candidate);
            }
        }
    }

    private function use(string $id): string
    {
        $this->used[$id] = true;

        return $id;
    }
}
