<?php

namespace XerAds\Laravel\Content;

use Illuminate\Support\Str;

/**
 * The heading-id rule both sides of the site contract apply (sites contract,
 * "Heading ids"). A port of XerAds' own implementation, line for line, and
 * tested with the same cases.
 *
 * Both sides assign ids, because the dashboard's editor drops them on save:
 * whichever side gives `Apa itu KPR` its id, a link to `#apa-itu-kpr` keeps
 * working. Change it in both places or not at all.
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
