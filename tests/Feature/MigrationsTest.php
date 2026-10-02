<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const CORE_MIGRATION = __DIR__.'/../../database/migrations/core/2026_10_02_000001_create_xerads_core_tables.php';

it('creates the core tables with their columns', function (string $table, array $columns) {
    expect(Schema::hasTable($table))->toBeTrue()
        ->and(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    ['xerads_state', ['key', 'value', 'updated_at']],
    ['xerads_deliveries', ['delivery_id', 'event', 'status', 'claim_token', 'processing_until', 'response_code', 'response', 'error', 'received_at', 'processed_at']],
    ['xerads_content_map', ['xerads_id', 'target', 'model_type', 'model_id', 'remote_id', 'sequence', 'revision', 'state', 'last_delivery_id', 'last_url', 'created_at', 'updated_at']],
    ['xerads_seo_meta', ['seoable_type', 'seoable_id', 'title', 'description', 'focus_keyword', 'keywords', 'canonical_url', 'robots', 'og', 'twitter', 'schema', 'breadcrumbs', 'extras', 'source', 'locked_fields']],
    ['xerads_media', ['source_url_hash', 'source_url', 'disk', 'path', 'mime', 'width', 'height', 'bytes', 'alt']],
    ['xerads_redirects', ['remote_id', 'origin', 'match', 'source', 'source_hash', 'case_insensitive', 'target', 'status', 'preserve_query', 'active', 'hits', 'last_hit_at']],
]);

it('enforces one ledger row per delivery and one SEO row per model', function () {
    DB::table('xerads_deliveries')->insert(['delivery_id' => 'd1', 'event' => 'ping', 'received_at' => now()]);

    // In a savepoint, so the failed insert does not abort the test's
    // transaction on PostgreSQL.
    expect(fn () => DB::transaction(fn () => DB::table('xerads_deliveries')->insert(['delivery_id' => 'd1', 'event' => 'ping', 'received_at' => now()])))
        ->toThrow(QueryException::class);

    DB::table('xerads_seo_meta')->insert(['seoable_type' => 'post', 'seoable_id' => 1]);

    expect(fn () => DB::transaction(fn () => DB::table('xerads_seo_meta')->insert(['seoable_type' => 'post', 'seoable_id' => 1])))
        ->toThrow(QueryException::class);
});

it('accepts many redirects without a remote id', function () {
    foreach (['/a', '/b'] as $source) {
        DB::table('xerads_redirects')->insert([
            'source' => $source,
            'source_hash' => sha1($source),
            'target' => '/c',
        ]);
    }

    expect(DB::table('xerads_redirects')->whereNull('remote_id')->count())->toBe(2);
});

it('names the tables from the configured prefix and drops them again', function () {
    config(['xerads.database.table_prefix' => 'acme_', 'xerads.database.morph_key_type' => 'uuid']);

    /** @var Migration $migration */
    $migration = require CORE_MIGRATION;
    $migration->up();

    expect(Schema::hasTable('acme_state'))->toBeTrue()
        ->and(Schema::hasTable('acme_redirects'))->toBeTrue();

    DB::table('acme_seo_meta')->insert(['seoable_type' => 'post', 'seoable_id' => '0192b3c4-d5e6-7f80-9a1b-2c3d4e5f6a7b']);

    $migration->down();

    expect(Schema::hasTable('acme_state'))->toBeFalse()
        ->and(Schema::hasTable('acme_redirects'))->toBeFalse();
})->skip(fn () => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'DDL commits the surrounding test transaction on MySQL and MariaDB.');

it('finishes a run that an earlier attempt left half done', function () {
    // Every table already exists, as after a run that failed on the last
    // one: running again must skip them rather than fail on the first.
    /** @var Migration $migration */
    $migration = require CORE_MIGRATION;
    $migration->up();

    expect(Schema::hasTable('xerads_redirects'))->toBeTrue();
});

it('stamps when a delivery arrived if the writer does not', function () {
    DB::table('xerads_deliveries')->insert(['delivery_id' => 'd-now', 'event' => 'ping']);

    expect(DB::table('xerads_deliveries')->where('delivery_id', 'd-now')->value('received_at'))->not->toBeNull();
});
