<?php

namespace XerAds\Laravel\Seo\Redirects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use XerAds\Laravel\Support\Concerns\UsesXeradsTables;

/**
 * A row of `xerads_redirects`: a path that moved (301, 302…) or is gone (410).
 *
 * `origin` says who owns it: `xerads` rows mirror the redirects managed in
 * the dashboard, `local` rows are the site's own, and `auto` rows are the
 * package's, written when a turnkey article changed its slug or was deleted.
 * The package only ever changes its own `auto` rows.
 *
 * Only exact matches are looked up here; prefix and pattern rules, and the
 * global middleware that applies them to every 404, come with the SEO module.
 *
 * @property int $id
 * @property string|null $remote_id
 * @property string $origin
 * @property string $match
 * @property string $source
 * @property string $source_hash
 * @property bool $case_insensitive
 * @property string|null $target
 * @property int $status
 * @property bool $preserve_query
 * @property bool $active
 * @property int $hits
 * @property Carbon|null $last_hit_at
 */
class Redirect extends Model
{
    use UsesXeradsTables;

    public const ORIGIN_AUTO = 'auto';

    protected $guarded = [];

    protected $casts = [
        'case_insensitive' => 'boolean',
        'preserve_query' => 'boolean',
        'active' => 'boolean',
        'status' => 'integer',
        'hits' => 'integer',
        'last_hit_at' => 'datetime',
    ];

    /** `/blog/old/` and `blog/old?x=1` are both `/blog/old`. */
    public static function normalize(string $path): string
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? '');

        return '/'.trim($path, '/');
    }

    /**
     * The hash a source is looked up by. A case-insensitive rule is stored
     * under its lowercase form, so one lookup finds either kind.
     */
    public static function hashOf(string $source, bool $caseInsensitive = false): string
    {
        return sha1($caseInsensitive ? mb_strtolower($source) : $source);
    }

    /** The active exact rule for a path, if any. */
    public static function forPath(string $path): ?self
    {
        $path = self::normalize($path);

        return static::query()
            ->where('active', true)
            ->where('match', 'exact')
            ->whereIn('source_hash', array_unique([self::hashOf($path), self::hashOf($path, true)]))
            ->orderBy('id')
            ->get()
            ->first(fn (self $redirect) => $redirect->case_insensitive
                ? mb_strtolower($redirect->source) === mb_strtolower($path)
                : $redirect->source === $path);
    }

    /**
     * The package's own rule for a path: moved to `$target`, or gone (410)
     * when `$target` is null. Replaces the package's earlier rule for the
     * same path.
     *
     * A move also points the rules that led to the path straight at the new
     * target, so readers never follow a chain. A removal leaves them alone:
     * they keep leading to the path, which answers 410 while the article is
     * deleted and the article again once it is restored.
     */
    public static function auto(string $source, ?string $target, int $status): self
    {
        $source = self::normalize($source);
        $target = $target !== null ? self::normalize($target) : null;

        if ($target !== null) {
            static::query()
                ->where('origin', self::ORIGIN_AUTO)
                ->where('target', $source)
                ->update(['target' => $target, 'status' => $status, 'updated_at' => Carbon::now()]);
        }

        /** @var self $redirect */
        $redirect = static::query()
            ->where('origin', self::ORIGIN_AUTO)
            ->where('source_hash', self::hashOf($source))
            ->firstOrNew();

        $redirect->forceFill([
            'origin' => self::ORIGIN_AUTO,
            'match' => 'exact',
            'source' => $source,
            'source_hash' => self::hashOf($source),
            'case_insensitive' => false,
            'target' => $target,
            'status' => $status,
            'preserve_query' => true,
            'active' => true,
        ])->save();

        return $redirect;
    }

    /**
     * Does the path still lead somewhere else, by any rule (the package's,
     * one from XerAds or the site's own)? Such a path is not free for a new
     * article: links to the old page would land on an unrelated one. A rule
     * leading to `$except` (the asking article's own address) does not count.
     */
    public static function movesAway(string $path, ?string $except = null): bool
    {
        $rule = self::forPath($path);

        return $rule !== null
            && ! $rule->isGone()
            && ($except === null || self::normalize((string) $rule->target) !== self::normalize($except));
    }

    /** Drop the package's rule for a path that is served again. */
    public static function forgetAuto(string $source): void
    {
        static::query()
            ->where('origin', self::ORIGIN_AUTO)
            ->where('source_hash', self::hashOf(self::normalize($source)))
            ->delete();
    }

    public function isGone(): bool
    {
        return $this->status === 410 || $this->target === null || $this->target === '';
    }

    /** Counted without touching `updated_at`, which says when the rule changed. */
    public function recordHit(): void
    {
        static::query()->whereKey($this->getKey())->toBase()->increment('hits', 1, ['last_hit_at' => Carbon::now()]);
    }

    protected function xeradsTable(): string
    {
        return 'redirects';
    }
}
