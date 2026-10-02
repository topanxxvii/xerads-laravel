<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 404 monitor: one row per path that answered 404, with a count.
 *
 * Paths only. No query string, no IP address, no user agent: a 404 log is
 * otherwise a log of personal data (tokens and emails in URLs, who visited).
 * `reported_hits` is how many of the hits XerAds has been told about, so a
 * report sends only what is new.
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
        $prefix = (string) config('xerads.database.table_prefix', 'xerads_');

        if (Schema::hasTable($prefix.'not_found')) {
            return;
        }

        Schema::create($prefix.'not_found', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('path', 512);
            $table->char('path_hash', 40)->unique();
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('reported_hits')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_referrer_host', 253)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'hits'], $prefix.'not_found_status_hits_index');
            $table->index('last_seen_at', $prefix.'not_found_last_seen_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('xerads.database.table_prefix', 'xerads_').'not_found');
    }
};
