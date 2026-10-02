<?php

namespace XerAds\Laravel\Seo\Schema;

/**
 * One JSON-LD `@graph` for the page: nodes with stable `@id`s that point at
 * each other (the article at its web page, the web page at the website, the
 * website at the organisation) instead of repeating themselves.
 *
 * Empty values are left out rather than printed as `""` or `null`, which
 * structured-data validators report as errors. Encoded so nothing in it can
 * close the `<script>` element it is printed in: `<`, `>` and `&` are
 * written as `<`, `>` and `&`.
 */
final class Graph
{
    /**
     * A broken UTF-8 byte in one value becomes U+FFFD instead of failing the
     * whole graph.
     */
    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var array<string, array<string, mixed>> by `@id` */
    private array $nodes = [];

    /**
     * Add a node, or merge into the node with the same `@id`.
     *
     * @param  array<string, mixed>  $node
     */
    public function add(array $node): self
    {
        $id = is_string($node['@id'] ?? null) ? $node['@id'] : '#node-'.count($this->nodes);

        $this->nodes[$id] = array_merge($this->nodes[$id] ?? [], $node);

        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function remove(string $id): self
    {
        unset($this->nodes[$id]);

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** @return array{'@context': string, '@graph': list<array<string, mixed>>} */
    public function toArray(): array
    {
        $nodes = [];

        foreach ($this->nodes as $node) {
            $node = self::withoutEmpty($node);

            if (is_array($node) && $node !== []) {
                $nodes[] = $node;
            }
        }

        return ['@context' => 'https://schema.org', '@graph' => $nodes];
    }

    /** Null when it cannot be encoded at all (then no `<script>` is printed). */
    public function toJson(): ?string
    {
        $json = json_encode($this->toArray(), self::JSON_FLAGS);

        return is_string($json) ? $json : null;
    }

    /**
     * A reference to another node.
     *
     * @return array{'@id': string}
     */
    public static function ref(string $id): array
    {
        return ['@id' => $id];
    }

    private static function withoutEmpty(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? (trim($value) !== '' ? $value : null) : $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            $item = self::withoutEmpty($item);

            if ($item !== null && $item !== []) {
                $clean[$key] = $item;
            }
        }

        // A node left with nothing but its type says nothing.
        if (array_keys($clean) === ['@type']) {
            return null;
        }

        return array_is_list($value) ? array_values($clean) : $clean;
    }
}
