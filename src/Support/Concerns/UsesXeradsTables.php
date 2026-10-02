<?php

namespace XerAds\Laravel\Support\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * For the package's own models: table name and connection from
 * `xerads.database.*`, read when the model is used, so a site that renamed
 * or moved the tables needs no model of its own.
 *
 * @phpstan-require-extends Model
 */
trait UsesXeradsTables
{
    /** The table name without the configured prefix. */
    abstract protected function xeradsTable(): string;

    public function getTable(): string
    {
        return (string) config('xerads.database.table_prefix', 'xerads_').$this->xeradsTable();
    }

    public function getConnectionName(): ?string
    {
        $connection = config('xerads.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }
}
