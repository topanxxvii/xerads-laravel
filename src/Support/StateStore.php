<?php

namespace XerAds\Laravel\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Small named values the package keeps between requests (`xerads_state`).
 *
 * The database rather than the cache or a file, because every server behind a
 * load balancer has to see the same credentials and settings, a cache flush
 * must not unpair a site, and a read-only filesystem is common on the hosts
 * these sites run on.
 *
 * Values are stored as JSON text, not a JSON column: MySQL normalises a JSON
 * column (it reorders keys and rewrites numbers), and a stored value should
 * come back exactly as it was written.
 */
final class StateStore
{
    public function __construct(private readonly Tables $tables) {}

    public function available(): bool
    {
        return $this->tables->exists('state');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->available()) {
            return $default;
        }

        $value = $this->query()->where('key', $key)->value('value');

        if (! is_string($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /** @throws \RuntimeException when the table has not been migrated */
    public function put(string $key, mixed $value): void
    {
        if (! $this->available()) {
            throw new \RuntimeException(
                'The '.$this->tables->name('state').' table does not exist yet. Run `php artisan migrate` first.'
            );
        }

        $this->query()->upsert(
            [[
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => Carbon::now(),
            ]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    public function forget(string $key): void
    {
        if ($this->available()) {
            $this->query()->where('key', $key)->delete();
        }
    }

    private function query(): Builder
    {
        return $this->tables->connection()->table($this->tables->name('state'));
    }
}
