<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the content map was missing to order events and keep URLs safely.
 *
 * - `site_id`: sequences count per XerAds site. A site that is added again
 *   (a new domain, say) starts counting from 1, and without knowing which
 *   site an entry's sequence came from every event of the new one would look
 *   older than what is stored.
 * - `first_published_at`: once an article has been public its slug is a
 *   promise, whatever its state is now.
 * - `remote_id`, `model_id`: as wide as XerAds stores a site's own id (191),
 *   so a long id a site returns never fails after its post was stored.
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
        $table = $this->table();

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'site_id')) {
                $blueprint->string('site_id', 40)->nullable()->after('target');
            }

            if (! Schema::hasColumn($table, 'first_published_at')) {
                $blueprint->timestamp('first_published_at')->nullable()->after('last_url');
            }

            $blueprint->string('model_id', 191)->nullable()->change();
            $blueprint->string('remote_id', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        $table = $this->table();

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            foreach (['site_id', 'first_published_at'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }

    private function table(): string
    {
        return (string) config('xerads.database.table_prefix', 'xerads_').'content_map';
    }
};
