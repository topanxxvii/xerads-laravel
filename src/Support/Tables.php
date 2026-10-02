<?php

namespace XerAds\Laravel\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Names and connection of the package's own tables.
 *
 * Both come from config (`xerads.database.*`) so a site can move the tables to
 * a second database or rename them around an existing table, and every class
 * that touches them asks here rather than spelling out "xerads_".
 */
final class Tables
{
    /** @var array<string, bool> */
    private array $exists = [];

    public function __construct(private readonly DatabaseManager $database) {}

    /** `state` → `xerads_state`, with the configured prefix. */
    public function name(string $table): string
    {
        return (string) config('xerads.database.table_prefix', 'xerads_').$table;
    }

    public function connection(): Connection
    {
        $name = config('xerads.database.connection');

        return $this->database->connection(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * Has the migration for this table run?
     *
     * Remembered for the life of this object, which is one request under a
     * long-running worker. Features that can degrade — replay protection,
     * stored credentials — check this instead of failing a request on a site
     * that installed the package and has not migrated yet.
     */
    public function exists(string $table): bool
    {
        $name = $this->name($table);

        if (! array_key_exists($name, $this->exists)) {
            try {
                $this->exists[$name] = $this->connection()->getSchemaBuilder()->hasTable($name);
            } catch (Throwable $exception) {
                Log::warning('XerAds could not check for its database table.', [
                    'table' => $name,
                    'error' => $exception->getMessage(),
                ]);

                $this->exists[$name] = false;
            }
        }

        return $this->exists[$name];
    }
}
