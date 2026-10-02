<?php

namespace XerAds\CmsBridge\Receivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;
use XerAds\Laravel\Content\ConfigurationReport;

/**
 * The ordinary case: write into an existing Eloquent model, from config alone.
 *
 * Every column is named in `xerads.content.mapped` rather than assumed,
 * because the sites this installs into already have a posts table and none of
 * them agree on what the columns are called. A field mapped to null is simply
 * not written, so a site with no meta-description column does not need a
 * migration to accept articles.
 *
 * On the first delivery of an article it adopts an existing row with the same
 * slug (`xerads.legacy.match_existing_by_slug`, on by default). That is how a
 * XerAds retry after a timeout — which carries no id yet, and a fresh
 * timestamp — updates the post the first attempt created instead of adding a
 * second one. XerAds gives every legacy slug a random suffix, so a match with
 * an unrelated hand-written post is not a realistic risk; a site that still
 * wants it off sets the key to false, and taken slugs then get a `-2` suffix.
 *
 * What it will not do, on purpose:
 * - erase a post's image because a delivery arrived without one;
 * - leave a post in the trash when XerAds sends it again: a re-send is an
 *   explicit request to publish, so a soft-deleted row is restored;
 * - report a public URL for a post that is not published, or one built from
 *   a slug other than the one actually saved.
 *
 * Its helpers are private, so a subclass written against the original
 * receiver keeps its own method names. `validateConfiguration()` runs on
 * "Test connection" for this class itself; a subclass opts in by
 * implementing ValidatesConfiguration.
 */
class EloquentArticleReceiver implements ArticleReceiver
{
    /** Field keys in the order they are written; a later one wins a shared column. */
    private const FIELDS = [
        'title', 'seo_title', 'content', 'slug', 'meta_description', 'image_url', 'status', 'keywords', 'excerpt',
    ];

    public function receive(IncomingArticle $article): array
    {
        $model = $this->newModel();
        $fields = $this->fields();

        $record = $this->findExisting($model, $article, $fields);
        $creating = $record === null;
        $record ??= $model->newInstance();

        $values = $this->values($record, $article, $fields);

        if ($creating) {
            /*
             * Defaults first, so a mapped value wins a shared column. They
             * exist for NOT NULL columns XerAds knows nothing about — an
             * author id, a category — which would otherwise fail the insert.
             */
            $values = array_merge($this->defaults(), $values);
        }

        // In a transaction, so a failed insert leaves nothing half written
        // and, on PostgreSQL, does not poison a transaction the caller holds.
        $record->getConnection()->transaction(function () use ($record, $values) {
            $this->restoreIfTrashed($record);

            $record->forceFill($values)->save();
        });

        return [
            'id' => $record->getKey(),
            // Only once it is public. XerAds keeps the link it already had
            // rather than recording one that 404s.
            'url' => $article->isPublished() ? $this->publicUrl($record, $fields, $article) : null,
        ];
    }

    /**
     * Can an article be stored right now, without storing one?
     *
     * Checks what fails on the first real article: the model class, its
     * table, and every mapped column. Things that would only surprise — a
     * NOT NULL column nothing fills, a public route that does not exist — are
     * warnings, because the model may fill the column itself in an event this
     * cannot see.
     */
    public function validateConfiguration(): ConfigurationReport
    {
        $problems = [];
        $warnings = [];

        $class = $this->modelClass();

        if ($class === '') {
            return new ConfigurationReport(['No post model is configured. Set XERADS_CMS_MODEL to your post model, for example App\Models\Post.']);
        }

        if (! class_exists($class)) {
            return new ConfigurationReport(["The post model {$class} does not exist. Set XERADS_CMS_MODEL to your post model, or bind your own ArticleReceiver."]);
        }

        if (! is_subclass_of($class, Model::class)) {
            return new ConfigurationReport(["{$class} is not an Eloquent model."]);
        }

        /** @var Model $model */
        $model = new $class;
        $table = $model->getTable();

        try {
            $schema = $model->getConnection()->getSchemaBuilder();

            if (! $schema->hasTable($table)) {
                return new ConfigurationReport(["The table {$table} for {$class} does not exist. Run `php artisan migrate`."]);
            }

            $listing = array_map('strtolower', $schema->getColumnListing($table));
            $columns = $schema->getColumns($table);
        } catch (Throwable $exception) {
            return new ConfigurationReport(["The table {$table} for {$class} could not be read: {$exception->getMessage()}"]);
        }

        $written = [];

        foreach ($this->fields() as $field => $column) {
            if (! in_array(strtolower($column), $listing, true)) {
                $problems[] = "xerads.content.mapped.fields.{$field} names the column {$table}.{$column}, which does not exist. Map it to an existing column or to null.";
            }

            $written[] = strtolower($column);
        }

        foreach (array_keys($this->defaults()) as $column) {
            if (! in_array(strtolower($column), $listing, true)) {
                $problems[] = "xerads.content.mapped.defaults sets the column {$table}.{$column}, which does not exist.";
            }

            $written[] = strtolower($column);
        }

        foreach ($this->unfilledRequiredColumns($model, $columns, $written) as $column) {
            $warnings[] = "The column {$table}.{$column} is NOT NULL with no default, and nothing XerAds sends fills it. "
                ."Set xerads.content.mapped.defaults['{$column}'] unless the model fills it itself.";
        }

        $route = (string) config('xerads.content.mapped.public_route', '');

        if ($route !== '' && ! app('router')->has($route)) {
            $warnings[] = "The public route {$route} does not exist, so XerAds receives no public link for published articles.";
        }

        return new ConfigurationReport($problems, $warnings);
    }

    /**
     * The row this article updates, if any.
     *
     * Keyed on the id THIS site issued, not on the slug. A slug can be edited
     * on either side, and the moment it diverges a slug-keyed upsert stops
     * finding the row and starts creating duplicates. The primary key does
     * not drift.
     *
     * Global scopes are ignored: a "published only" scope would otherwise hide
     * the draft this site created last time, a tenant scope reads the signed-in
     * user a webhook does not have, and either way the re-sync would create a
     * second copy. That includes the soft-delete scope; a trashed row found
     * here is restored before it is updated.
     *
     * @param  array<string, string>  $fields
     */
    private function findExisting(Model $model, IncomingArticle $article, array $fields): ?Model
    {
        if ($article->remoteId !== null && $this->isPlausibleKey($model, $article->remoteId)) {
            $record = $model->newQueryWithoutScopes()->find($article->remoteId);

            if ($record instanceof Model) {
                return $record;
            }
        }

        $slugColumn = $fields['slug'] ?? null;

        if ($slugColumn !== null && $article->slug !== '' && (bool) config('xerads.legacy.match_existing_by_slug', true)) {
            // A first delivery, or a retry of one that timed out after this
            // site had stored it: the id has not reached XerAds yet, so the
            // slug is the only link back to the row.
            $record = $model->newQueryWithoutScopes()->where($slugColumn, $article->slug)->first();

            if ($record instanceof Model) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function values(Model $record, IncomingArticle $article, array $fields): array
    {
        $incoming = [
            'title' => $article->title,
            'seo_title' => $article->seoTitle,
            'content' => $article->content,
            'slug' => $article->slug !== '' ? $article->slug : Str::slug($article->title),
            'meta_description' => $article->metaDescription,
            'image_url' => $article->imageUrl,
            'status' => $this->mapStatus($article->status),
            'keywords' => $article->keywords,
            'excerpt' => $article->excerpt,
        ];

        $values = [];

        foreach (self::FIELDS as $field) {
            $column = $fields[$field] ?? null;

            if ($column === null) {
                continue;
            }

            $value = $incoming[$field];

            // A delivery without an image (or, from this endpoint, without an
            // excerpt) is not a request to delete the one the post has.
            if (($field === 'image_url' || $field === 'excerpt') && $value === null) {
                continue;
            }

            $values[$column] = match ($field) {
                'keywords' => $this->keywordsValue($record, $column, $article->keywords),
                'slug' => $this->availableSlug($record, $column, (string) $value),
                default => $value,
            };
        }

        return $values;
    }

    /**
     * Translate XerAds' two statuses into whatever this site calls them.
     *
     * 'draft' and 'publish' are the only two that reach a receiver; a site
     * using booleans, integers or different words maps them in config rather
     * than receiving a string its own scopes do not recognise.
     */
    private function mapStatus(string $status): mixed
    {
        $map = (array) config('xerads.content.mapped.status_map', []);

        return $map[$status] ?? $status;
    }

    /**
     * Where the article can now be read, from what was actually saved.
     *
     * A route name is the usual answer, since that is what a Laravel site
     * already has. The parameter is read back from the saved model, because a
     * slug the site de-duplicated or a model event rewrote is the one the
     * route will match. Returning null is legitimate: XerAds records no
     * permalink rather than guessing a URL that 404s.
     *
     * @param  array<string, string>  $fields
     */
    private function publicUrl(Model $record, array $fields, IncomingArticle $article): ?string
    {
        $route = (string) config('xerads.content.mapped.public_route', '');

        if ($route === '' || ! app('router')->has($route)) {
            return null;
        }

        $parameter = (string) config('xerads.content.mapped.public_route_parameter', 'slug');
        $column = config('xerads.content.mapped.public_route_column');

        if (is_string($column) && $column !== '') {
            $value = $record->getAttribute($column);
        } elseif ($parameter === 'slug') {
            $value = $this->savedSlug($record, $fields, $article);
        } else {
            $value = $record->getKey();
        }

        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        try {
            return route($route, [$parameter => $value]);
        } catch (Throwable) {
            // A route that needs parameters this cannot supply is a config
            // mistake, not a reason to fail a publish that already happened.
            return null;
        }
    }

    /**
     * The slug, or the slug with a `-2`, `-3`… suffix when another post has it.
     *
     * Another post holding the slug is either an unrelated post (which must
     * not be overwritten) or a unique index waiting to fail the insert. A
     * suffix avoids both, and the URL reported back uses whichever was saved.
     */
    private function availableSlug(Model $record, string $column, string $slug): string
    {
        if ($slug === '') {
            return $slug;
        }

        $candidate = $slug;

        for ($suffix = 2; $this->slugTaken($record, $column, $candidate); $suffix++) {
            $candidate = $suffix <= 50 ? $slug.'-'.$suffix : $slug.'-'.Str::lower(Str::random(6));
        }

        return $candidate;
    }

    /**
     * The slug the route should receive.
     *
     * The mapped slug column when there is one. Without one, the model's own
     * `slug` attribute (a model that derives its slug in an event), and
     * failing that the slug XerAds sent, which is what the original receiver
     * reported.
     *
     * @param  array<string, string>  $fields
     */
    private function savedSlug(Model $record, array $fields, IncomingArticle $article): mixed
    {
        if (isset($fields['slug'])) {
            return $record->getAttribute($fields['slug']);
        }

        // Read only if present: a strict model throws on a missing attribute.
        if (array_key_exists('slug', $record->getAttributes())) {
            return $record->getAttribute('slug');
        }

        return $article->slug;
    }

    /**
     * Bring a soft-deleted row back before updating it.
     *
     * Through the model's own `restore()`, so its restoring and restored
     * events fire as they would for a restore from the site's admin.
     */
    private function restoreIfTrashed(Model $record): void
    {
        if ($record->exists && method_exists($record, 'trashed') && method_exists($record, 'restore') && $record->trashed()) {
            $record->restore();
        }
    }

    private function slugTaken(Model $record, string $column, string $slug): bool
    {
        $query = $record->newQueryWithoutScopes()->where($column, $slug);

        if ($record->exists) {
            $query->whereKeyNot($record->getKey());
        }

        return $query->exists();
    }

    /**
     * A list for an array or JSON cast, a comma-separated string otherwise,
     * so a plain text column does not receive the word "Array".
     *
     * @param  list<string>  $keywords
     * @return list<string>|string
     */
    private function keywordsValue(Model $record, string $column, array $keywords): array|string
    {
        $cast = $record->getCasts()[$column] ?? null;

        if (is_string($cast)) {
            $base = strtolower($cast);

            if (in_array($base, ['array', 'json', 'collection', 'object', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'], true)
                || str_contains($cast, 'AsArrayObject')
                || str_contains($cast, 'AsCollection')
                || str_contains($cast, 'AsEncrypted')) {
                return $keywords;
            }
        }

        return implode(', ', $keywords);
    }

    /**
     * Could this be a primary key of this model? An integer key queried with
     * a non-numeric id is an error on PostgreSQL, not an empty result.
     */
    private function isPlausibleKey(Model $model, string $id): bool
    {
        return ! in_array($model->getKeyType(), ['int', 'integer'], true) || ctype_digit($id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  list<string>  $written  lowercased column names this receiver writes
     * @return list<string>
     */
    private function unfilledRequiredColumns(Model $model, array $columns, array $written): array
    {
        $filled = $written;

        if ($model->getIncrementing() || $model->usesUniqueIds()) {
            $filled[] = strtolower($model->getKeyName());
        }

        if ($model->usesTimestamps()) {
            $filled[] = strtolower((string) $model->getCreatedAtColumn());
            $filled[] = strtolower((string) $model->getUpdatedAtColumn());
        }

        foreach (array_keys($model->getAttributes()) as $attribute) {
            $filled[] = strtolower((string) $attribute);
        }

        $missing = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');

            $optional = ($column['nullable'] ?? true)
                || ($column['default'] ?? null) !== null
                || ($column['auto_increment'] ?? false)
                || ($column['generation'] ?? null) !== null;

            if ($name !== '' && ! $optional && ! in_array(strtolower($name), $filled, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private function newModel(): Model
    {
        $class = $this->modelClass();

        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new \RuntimeException(
                'xerads.content.mapped.model (XERADS_CMS_MODEL) is not set to an existing Eloquent model. Point it at your post model, or bind your own ArticleReceiver.'
            );
        }

        return new $class;
    }

    private function modelClass(): string
    {
        return ltrim(trim((string) config('xerads.content.mapped.model', '')), '\\');
    }

    /**
     * Mapped fields only: field key => column name.
     *
     * @return array<string, string>
     */
    private function fields(): array
    {
        $fields = [];

        foreach ((array) config('xerads.content.mapped.fields', []) as $field => $column) {
            if (is_string($field) && is_string($column) && trim($column) !== '') {
                $fields[$field] = trim($column);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        $defaults = config('xerads.content.mapped.defaults', []);

        return is_array($defaults) ? array_filter($defaults, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }
}
