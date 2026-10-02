<?php

namespace XerAds\Laravel\Content\Receivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;
use XerAds\Laravel\Content\Exceptions\WriteConflict;

/**
 * The site's own post model, as `xerads.content.mapped` describes it.
 *
 * Shared by the paired receiver (EloquentMappedReceiver) and the original
 * endpoint's receiver, so both read the same field map, check the same
 * columns, pick slugs and build public links the same way.
 */
final class MappedModel
{
    public function modelClass(): string
    {
        return ltrim(trim((string) config('xerads.content.mapped.model', '')), '\\');
    }

    /** @throws ReceiverMisconfigured */
    public function newModel(): Model
    {
        $class = $this->modelClass();

        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new ReceiverMisconfigured(
                'xerads.content.mapped.model (XERADS_CONTENT_MODEL or XERADS_CMS_MODEL) is not set to an existing Eloquent model. Point it at your post model, or bind your own receiver.'
            );
        }

        return new $class;
    }

    /**
     * Mapped fields only: field key => column name.
     *
     * @return array<string, string>
     */
    public function fields(): array
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
    public function defaults(): array
    {
        $defaults = config('xerads.content.mapped.defaults', []);

        return is_array($defaults) ? array_filter($defaults, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * Translate XerAds' two statuses into whatever this site calls them.
     *
     * 'draft' and 'publish' are the only two that reach a receiver; a site
     * using booleans, integers or different words maps them in config rather
     * than receiving a string its own scopes do not recognise.
     */
    public function statusValue(string $status): mixed
    {
        $map = (array) config('xerads.content.mapped.status_map', []);

        return $map[$status] ?? $status;
    }

    /**
     * A row by its primary key, ignoring global scopes: a "published only"
     * scope would hide the draft this site stored last time, and a tenant
     * scope reads a signed-in user a webhook does not have. The soft-delete
     * scope goes too; a trashed row found here is restored by the caller.
     */
    public function find(Model $model, string|int|null $id): ?Model
    {
        if ($id === null || $id === '' || ! $this->isPlausibleKey($model, (string) $id)) {
            return null;
        }

        $record = $model->newQueryWithoutScopes()->find($id);

        return $record instanceof Model ? $record : null;
    }

    public function findBySlug(Model $model, string $column, string $slug): ?Model
    {
        if ($slug === '') {
            return null;
        }

        $record = $model->newQueryWithoutScopes()->where($column, $slug)->first();

        return $record instanceof Model ? $record : null;
    }

    /**
     * The slug, or the slug with a `-2`, `-3`… suffix when another row has it.
     *
     * Another row holding the slug is either an unrelated post (which must
     * not be overwritten) or a unique index waiting to fail the insert. A
     * suffix avoids both, and the URL reported back uses whichever was saved.
     */
    public function availableSlug(Model $record, string $column, string $slug): string
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
     * A list for an array or JSON cast, a comma-separated string otherwise,
     * so a plain text column does not receive the word "Array".
     *
     * @param  list<string>  $keywords
     * @return list<string>|string
     */
    public function keywordsValue(Model $record, string $column, array $keywords): array|string
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
     * Save in a transaction, restoring a trashed row first unless told not
     * to (taking an article offline must not bring it back from the trash).
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ReceiverMisconfigured when the site's setup refuses the article
     * @throws WriteConflict when another write took the same unique value
     * @throws QueryException for anything else, to be retried
     */
    public function save(Model $record, array $values, bool $restoreTrashed = true): void
    {
        try {
            $record->getConnection()->transaction(function () use ($record, $values, $restoreTrashed) {
                if ($restoreTrashed) {
                    $this->restoreIfTrashed($record);
                }

                $record->forceFill($values)->save();
            });
        } catch (QueryException $exception) {
            throw $this->translate($exception, $record);
        }
    }

    /**
     * What a database error means for the delivery.
     *
     * Only errors that name the site's setup become RECEIVER_MISCONFIGURED, a
     * 422 XerAds shows the owner and does not retry: a column that does not
     * exist, a required column nothing fills, a value too long for its
     * column, a related row that is missing. A unique index refusing a value
     * another delivery took a moment earlier is a WriteConflict, retried.
     * Everything else (a lost connection, a lock timeout) is returned as it
     * is, answered 500 and retried with backoff.
     */
    public function translate(QueryException $exception, Model $record): Throwable
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return new WriteConflict('Another delivery took the same unique value (usually the slug) a moment ago. Retry and the next free one is used.', 0, $exception);
        }

        $message = $exception->getMessage();
        $column = self::columnFrom($message);

        if ($column !== null) {
            return new ReceiverMisconfigured(
                "The article could not be stored: the database refused the value for {$record->getTable()}.{$column} (a column that is missing, required, or too short for it). Map it in xerads.content.mapped.fields, give it a value in xerads.content.mapped.defaults, or widen it.",
                $column,
                $exception,
            );
        }

        if (preg_match('/foreign key constraint/i', $message) === 1) {
            return new ReceiverMisconfigured(
                "The article could not be stored in {$record->getTable()}: a related row it needs (or that still needs it) is missing. Check xerads.content.mapped.defaults and xerads.content.mapped.on_delete.",
                null,
                $exception,
            );
        }

        return $exception;
    }

    /** A value of the wrong type for its column, such as a UUID key in an integer column. */
    public static function isTypeMismatch(string $message): bool
    {
        return preg_match('/invalid input syntax for type|Incorrect integer value|Truncated incorrect|datatype mismatch/i', $message) === 1;
    }

    public function isTrashed(Model $record): bool
    {
        return $record->exists && method_exists($record, 'trashed') && $record->trashed();
    }

    /**
     * Bring a soft-deleted row back before updating it: XerAds sending the
     * article again is a request to have it on the site.
     *
     * Through the model's own `restore()`, so its restoring and restored
     * events fire as they would for a restore from the site's admin.
     */
    public function restoreIfTrashed(Model $record): void
    {
        if ($this->isTrashed($record) && method_exists($record, 'restore')) {
            $record->restore();
        }
    }

    /**
     * Where the article can be read, from what was actually saved.
     *
     * A route name is the usual answer, since that is what a Laravel site
     * already has. The parameter is read back from the saved model, because a
     * slug the site de-duplicated or a model event rewrote is the one the
     * route will match. Without a slug column, the model's own `slug`
     * attribute, then `$fallbackSlug`. Null is a legitimate answer: XerAds
     * records no address rather than one that 404s.
     *
     * @param  array<string, string>  $fields
     */
    public function publicUrl(Model $record, array $fields, ?string $fallbackSlug = null): ?string
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
            $value = match (true) {
                isset($fields['slug']) => $record->getAttribute($fields['slug']),
                // Read only if present: a strict model throws on a missing attribute.
                array_key_exists('slug', $record->getAttributes()) => $record->getAttribute('slug'),
                default => $fallbackSlug,
            };
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
     * Can an article be stored right now, without storing one?
     *
     * Checks what fails on the first real article: the model class, its
     * table, and every mapped column. Things that would only surprise — a
     * NOT NULL column nothing fills, a public route that does not exist — are
     * warnings, because the model may fill the column itself in an event this
     * cannot see.
     */
    public function validate(): ConfigurationReport
    {
        $class = $this->modelClass();

        if ($class === '') {
            return new ConfigurationReport(['No post model is configured. Set XERADS_CONTENT_MODEL (or XERADS_CMS_MODEL) to your post model, for example App\Models\Post.']);
        }

        if (! class_exists($class)) {
            return new ConfigurationReport(["The post model {$class} does not exist. Set XERADS_CONTENT_MODEL (or XERADS_CMS_MODEL) to your post model, or bind your own receiver."]);
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

        $problems = [];
        $warnings = [];
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
     * The column a database error names, from the messages SQLite, MySQL,
     * MariaDB and PostgreSQL use for a missing value or a missing column.
     */
    public static function columnFrom(string $message): ?string
    {
        $patterns = [
            '/NOT NULL constraint failed: (?:\w+\.)?(\w+)/i',                // SQLite
            "/Field '(\w+)' doesn't have a default value/i",              // MySQL, MariaDB
            "/Column '(\w+)' cannot be null/i",                            // MySQL, MariaDB
            "/Unknown column '(?:\w+\.)?(\w+)'/i",                         // MySQL, MariaDB
            '/null value in column "(\w+)"/i',                             // PostgreSQL
            '/column "(\w+)" (?:of relation "\w+" )?does not exist/i',     // PostgreSQL
            '/table \w+ has no column named (\w+)/i',                      // SQLite
            "/Data too long for column '(\w+)'/i",                        // MySQL, MariaDB
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match) === 1) {
                return $match[1];
            }
        }

        return null;
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
     * Could this be a primary key of this model? An integer key queried with
     * a non-numeric id is an error on PostgreSQL, not an empty result.
     */
    private function isPlausibleKey(Model $model, string $id): bool
    {
        return ! in_array($model->getKeyType(), ['int', 'integer'], true) || ctype_digit($id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  list<string>  $written  lowercased column names the receiver writes
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
}
