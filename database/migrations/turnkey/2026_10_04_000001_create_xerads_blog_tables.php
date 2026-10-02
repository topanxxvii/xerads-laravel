<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The turnkey blog: articles, categories, tags and the two pivots.
 *
 * Loaded only when `xerads.content.mode` is `turnkey` (or published with
 * `php artisan xerads:install --mode=turnkey`), so a site storing articles in
 * its own model never gets tables it does not use.
 *
 * An article keeps two bodies: `body_source` with `[xerads_widget]`
 * placeholders, which pages expand when they render, and `body_html` with the
 * widget containers compiled in, for anything that prints the body as it is
 * (a feed, an export). Slugs are unique across trashed rows too: a deleted
 * article's address answers 410, and must not be handed to another article.
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

        $this->createUnlessExists($prefix.'categories', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained($prefix.'categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $this->createUnlessExists($prefix.'tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 191)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $this->createUnlessExists($prefix.'articles', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->char('xerads_id', 26)->nullable()->unique();
            $table->string('language', 8)->nullable();
            $table->string('title', 512);
            $table->string('headline', 512)->nullable();
            $table->string('slug', 191)->unique();
            $table->text('excerpt')->nullable();
            $table->longText('body_source');
            $table->longText('body_html');
            $table->json('toc')->nullable();

            // What pages show: XerAds' URL until the image is copied to
            // this site's disk, then the copy (`featured_image_path`).
            $table->string('featured_image_url', 2048)->nullable();
            $table->text('featured_image_alt')->nullable();
            $table->text('featured_image_caption')->nullable();
            $table->unsignedInteger('featured_image_width')->nullable();
            $table->unsignedInteger('featured_image_height')->nullable();
            $table->string('featured_image_mime', 100)->nullable();
            $table->string('featured_image_path', 1024)->nullable();

            $table->json('author')->nullable();
            $table->foreignId('primary_category_id')->nullable()->constrained($prefix.'categories')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('reading_time')->default(0);
            $table->string('revision', 80)->nullable();
            $table->json('widgets')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at'], $prefix.'articles_status_published_at_index');
        });

        $this->createUnlessExists($prefix.'article_category', function (Blueprint $table) use ($prefix) {
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained($prefix.'categories')->cascadeOnDelete();

            $table->primary(['article_id', 'category_id']);
            $table->index('category_id', $prefix.'article_category_category_id_index');
        });

        $this->createUnlessExists($prefix.'article_tag', function (Blueprint $table) use ($prefix) {
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained($prefix.'tags')->cascadeOnDelete();

            $table->primary(['article_id', 'tag_id']);
            $table->index('tag_id', $prefix.'article_tag_tag_id_index');
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        foreach (['article_tag', 'article_category', 'articles', 'tags', 'categories'] as $table) {
            Schema::dropIfExists($prefix.$table);
        }
    }

    /**
     * Create a table unless an earlier, interrupted run already did (see the
     * core migration: MySQL commits each CREATE TABLE on its own).
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
};
