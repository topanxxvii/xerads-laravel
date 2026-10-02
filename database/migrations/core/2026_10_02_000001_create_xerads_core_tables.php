<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables every XerAds installation needs, whatever modes it runs.
 *
 * Names and connection come from `xerads.database.*`, read when this runs.
 * Long URLs live in 2048-character columns with a separate hash column for
 * lookups, because MySQL cannot index a column that long and a URL is not
 * something to truncate.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('xerads.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $prefix = $this->prefix();

        // Small named values: the encrypted site key, settings snapshots,
        // version counters. JSON text, so a value comes back byte for byte.
        $this->createUnlessExists($prefix.'state', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Every delivery seen, so a retry is answered from here instead of
        // being applied twice.
        $this->createUnlessExists($prefix.'deliveries', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('delivery_id', 100)->unique();
            $table->string('event', 64);
            $table->string('status', 16)->default('processing');
            // Only the holder of the current claim may record the outcome.
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('processing_until')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            // With a default: MySQL 5.7 and older MariaDB refuse a NOT NULL
            // TIMESTAMP without one.
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->index('received_at', $prefix.'deliveries_received_at_index');
        });

        // Which XerAds article became which row on this site.
        $this->createUnlessExists($prefix.'content_map', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->char('xerads_id', 26)->unique();
            $table->string('target', 16);
            $table->string('model_type')->nullable();
            $table->string('model_id', 64)->nullable();
            $table->string('remote_id', 64)->nullable();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->string('revision', 80)->nullable();
            $table->string('state', 16);
            $table->string('last_delivery_id', 100)->nullable();
            $table->string('last_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id'], $prefix.'content_map_model_index');
        });

        // SEO fields for any model, so a site's own posts table needs no new
        // columns to carry a canonical URL or structured data.
        $this->createUnlessExists($prefix.'seo_meta', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('seoable_type');
            $this->morphKey($table, 'seoable_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('focus_keyword')->nullable();
            $table->json('keywords')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->json('robots')->nullable();
            $table->json('og')->nullable();
            $table->json('twitter')->nullable();
            $table->json('schema')->nullable();
            $table->json('breadcrumbs')->nullable();
            $table->json('extras')->nullable();
            $table->string('source', 16)->default('xerads');
            $table->json('locked_fields')->nullable();
            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id'], $prefix.'seo_meta_seoable_unique');
        });

        // Images copied onto this site's disk, keyed by where they came from.
        $this->createUnlessExists($prefix.'media', function (Blueprint $table) {
            $table->id();
            $table->char('source_url_hash', 40)->unique();
            $table->text('source_url');
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('alt')->nullable();
            $table->timestamps();
        });

        // Redirects: managed in XerAds, created when a slug changes, or added
        // locally. Core rather than SEO-only, so a deleted article's 410 and a
        // renamed article's 301 work whichever modules are on.
        $this->createUnlessExists($prefix.'redirects', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('remote_id', 64)->nullable()->unique();
            $table->string('origin', 16)->default('local');
            $table->string('match', 16)->default('exact');
            $table->string('source', 2048);
            $table->char('source_hash', 40);
            $table->boolean('case_insensitive')->default(false);
            $table->string('target', 2048)->nullable();
            $table->unsignedSmallInteger('status')->default(301);
            $table->boolean('preserve_query')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('source_hash', $prefix.'redirects_source_hash_index');
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        foreach (['redirects', 'media', 'seo_meta', 'content_map', 'deliveries', 'state'] as $table) {
            Schema::dropIfExists($prefix.$table);
        }
    }

    /**
     * Create a table unless an earlier, interrupted run already did.
     *
     * MySQL and MariaDB commit each CREATE TABLE on its own, so a migration
     * that fails halfway leaves the first tables behind and is not recorded
     * as run. Skipping what exists lets the next `migrate` finish the job
     * instead of failing on the first table forever.
     *
     * @param  Closure(Blueprint): void  $definition
     */
    private function createUnlessExists(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    private function prefix(): string
    {
        return (string) config('xerads.database.table_prefix', 'xerads_');
    }

    /**
     * The morph id column, typed like the primary keys it points at. Must
     * match: comparing a bigint column to a UUID string is a type error on
     * PostgreSQL and a silent mismatch on MySQL.
     */
    private function morphKey(Blueprint $table, string $column): void
    {
        match (config('xerads.database.morph_key_type', 'int')) {
            'uuid' => $table->uuid($column),
            'ulid' => $table->ulid($column),
            'string' => $table->string($column, 64),
            default => $table->unsignedBigInteger($column),
        };
    }
};
