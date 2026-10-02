<?php

namespace XerAds\Laravel\Seo\Redirects;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use XerAds\Laravel\Seo\NotFound\NotFoundEntry;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\InvalidSiteKey;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Sync\RemoteState;

/**
 * Makes the `xerads` rows of `xerads_redirects` match the redirects document
 * pulled from XerAds (`{version, data: [{id, match, source, target, status,
 * preserve_query, case_insensitive}]}`).
 *
 * The `xerads` rows are replaced as a whole; the package's `auto` rows and
 * the site's `local` rows are never touched. Each rule is checked again
 * here, as XerAds checked it when it was saved: a plain path for a source, a
 * path or an https address for a target, a known status, no target for 410
 * and 451, and no loop, with itself or with another rule (an `auto` one
 * included). A rule that fails is left out and logged; the others apply.
 */
final class RedirectSync
{
    /** XerAds allows 2,000 per site; anything far beyond is not from XerAds. */
    private const MAX_RULES = 5000;

    private const STATUSES = [301, 302, 307, 308, 410, 451];

    /** A path on the site, as XerAds validates one. */
    private const PATH = '/^\/(?![\/\\\\])[^\x00-\x20\x7F<>"\'`\\\\]*$/D';

    public function __construct(
        private readonly RemoteState $state,
        private readonly CredentialsResolver $credentials,
        private readonly Tables $tables,
    ) {}

    /**
     * Apply the held document, unless it is the one applied last.
     *
     * @return bool whether the rules changed
     */
    public function syncFromState(): bool
    {
        if (! $this->tables->exists('redirects')) {
            return false;
        }

        $document = $this->state->document('redirects');
        $marker = [
            'site_id' => $this->siteId(),
            'version' => is_int($document['version'] ?? null) ? $document['version'] : null,
        ];

        if ($this->state->get('redirects_applied') === $marker) {
            return false;
        }

        $items = $document['data']['data'] ?? [];
        $this->apply(is_array($items) ? array_values(array_filter($items, 'is_array')) : []);
        $this->state->put('redirects_applied', $marker);

        return true;
    }

    /**
     * Replace the `xerads` rows with these rules.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{applied: int, rejected: list<string>}
     */
    public function apply(array $items): array
    {
        $rejected = [];
        $rules = [];
        $existing = $this->otherRules();

        foreach (array_slice($items, 0, self::MAX_RULES) as $item) {
            $rule = $this->rule($item);

            if (is_string($rule)) {
                $rejected[] = $rule;

                continue;
            }

            $loop = $this->loop($rule, [...$existing, ...$rules]);

            if ($loop !== null) {
                $rejected[] = $loop;

                continue;
            }

            $rules[] = $rule;
        }

        Redirect::query()->getConnection()->transaction(function () use ($rules) {
            Redirect::query()->where('origin', Redirect::ORIGIN_XERADS)->delete();

            $now = Carbon::now();

            foreach (array_chunk($rules, 200) as $chunk) {
                Redirect::query()->insert(array_map(fn (array $rule) => [
                    'remote_id' => $rule['remote_id'],
                    'origin' => Redirect::ORIGIN_XERADS,
                    'match' => $rule['match'],
                    'source' => $rule['source'],
                    'source_hash' => Redirect::hashOf($rule['source'], $rule['case_insensitive']),
                    'case_insensitive' => $rule['case_insensitive'],
                    'target' => $rule['target'],
                    'status' => $rule['status'],
                    'preserve_query' => $rule['preserve_query'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        Redirect::changed();
        $this->resolveNotFound($rules);

        foreach ($rejected as $reason) {
            Log::warning('XerAds sent a redirect this site did not apply: '.$reason);
        }

        return ['applied' => count($rules), 'rejected' => $rejected];
    }

    /**
     * One item, checked; a string saying why when it cannot be applied.
     *
     * @param  array<string, mixed>  $item
     * @return array{remote_id: string|null, match: string, source: string, target: string|null, target_path: string|null, status: int, preserve_query: bool, case_insensitive: bool}|string
     */
    private function rule(array $item): array|string
    {
        $source = is_string($item['source'] ?? null) ? trim($item['source']) : '';
        $match = $item['match'] ?? 'exact';
        $status = is_int($item['status'] ?? null) ? $item['status'] : 301;
        $target = is_string($item['target'] ?? null) ? trim($item['target']) : null;

        if (! in_array($match, ['exact', 'prefix'], true)) {
            return "{$source}: match must be exact or prefix.";
        }

        if ($source === '' || strlen($source) > 2048 || preg_match(self::PATH, $source) !== 1) {
            return "{$source}: the source is not a path on this site.";
        }

        if (! in_array($status, self::STATUSES, true)) {
            return "{$source}: status {$status} is not one XerAds sends.";
        }

        $targetPath = null;

        if (in_array($status, Redirect::GONE, true)) {
            $target = null;
        } elseif ($target !== null && str_starts_with($target, '/')) {
            if (preg_match(self::PATH, $target) !== 1) {
                return "{$source}: the target is not a path on this site.";
            }

            $targetPath = Redirect::normalize($target);
        } elseif ($target === null || ! $this->isHttpsUrl($target)) {
            return "{$source}: the target must be a path on this site or an https address.";
        }

        return [
            'remote_id' => isset($item['id']) && (is_int($item['id']) || is_string($item['id'])) ? (string) $item['id'] : null,
            'match' => $match,
            'source' => Redirect::normalize($source),
            'target' => $target,
            'target_path' => $targetPath,
            'status' => $status,
            'preserve_query' => ($item['preserve_query'] ?? true) !== false,
            'case_insensitive' => ($item['case_insensitive'] ?? false) === true,
        ];
    }

    /**
     * Would this rule send visitors round in circles: back to itself, or to
     * another rule that sends them back?
     *
     * @param  array{source: string, match: string, target_path: string|null, case_insensitive: bool}  $rule
     * @param  list<array{source: string, match: string, target_path: string|null, case_insensitive: bool}>  $others
     */
    private function loop(array $rule, array $others): ?string
    {
        $target = $rule['target_path'];

        if ($target === null) {
            return null;
        }

        $fold = fn (string $path, bool $insensitive) => $insensitive ? mb_strtolower($path) : $path;

        if (RedirectMatcher::covers($rule['match'], $fold($rule['source'], $rule['case_insensitive']), $fold($target, $rule['case_insensitive']))) {
            return "{$rule['source']}: it would send visitors back to itself.";
        }

        foreach ($others as $other) {
            if ($other['target_path'] === null) {
                continue;
            }

            $catches = RedirectMatcher::covers($other['match'], $fold($other['source'], $other['case_insensitive']), $fold($target, $other['case_insensitive']));
            $sendsBack = RedirectMatcher::covers($rule['match'], $fold($rule['source'], $rule['case_insensitive']), $fold($other['target_path'], $rule['case_insensitive']));

            if ($catches && $sendsBack) {
                return "{$rule['source']}: {$other['source']} already redirects back to it.";
            }
        }

        return null;
    }

    /**
     * The rules XerAds' rows do not replace, for the loop check.
     *
     * @return list<array{source: string, match: string, target_path: string|null, case_insensitive: bool}>
     */
    private function otherRules(): array
    {
        $rules = [];

        foreach (Redirect::query()->where('origin', '!=', Redirect::ORIGIN_XERADS)->where('active', true)->get() as $redirect) {
            $target = $redirect->target;

            $rules[] = [
                'source' => Redirect::normalize($redirect->source),
                'match' => $redirect->match === 'prefix' ? 'prefix' : 'exact',
                'target_path' => is_string($target) && str_starts_with($target, '/') ? Redirect::normalize($target) : null,
                'case_insensitive' => $redirect->case_insensitive,
            ];
        }

        return $rules;
    }

    /**
     * Paths the 404 monitor counted that a rule now catches by their exact
     * source: resolved, so they stop showing as open.
     *
     * @param  list<array{source: string, match: string}>  $rules
     */
    private function resolveNotFound(array $rules): void
    {
        if (! $this->tables->exists('not_found') || $rules === []) {
            return;
        }

        foreach (array_chunk(array_map(fn (array $rule) => sha1($rule['source']), $rules), 500) as $hashes) {
            NotFoundEntry::query()->whereIn('path_hash', $hashes)->update(['status' => NotFoundEntry::RESOLVED]);
        }
    }

    private function siteId(): ?string
    {
        try {
            return $this->credentials->current()?->siteId;
        } catch (InvalidSiteKey) {
            return null;
        }
    }

    private function isHttpsUrl(string $value): bool
    {
        if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && preg_match('/^[a-z0-9.-]+$/i', (string) ($parts['host'] ?? '')) === 1;
    }
}
