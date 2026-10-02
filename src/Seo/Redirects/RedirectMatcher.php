<?php

namespace XerAds\Laravel\Seo\Redirects;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use XerAds\Laravel\Support\Tables;

/**
 * Which redirect applies to a path: the exact rule first, else the prefix
 * rule with the longest source. Between rules for the same source, the
 * site's own win, then XerAds', then the package's automatic ones.
 *
 * The active rules are read once and cached as a lookup table, under a
 * generation every change moves on (Redirect::changed()), so a request that
 * misses (most 404s) costs a cache read, not a query.
 *
 * A prefix rule sends everything under its source to its target as it is:
 * `/promo` → `/` sends `/promo/summer` to `/`, not to `/summer`.
 *
 * Nothing is written while answering: a redirect costs a cache read, never
 * a database write.
 */
final class RedirectMatcher
{
    private const CACHE_SECONDS = 86_400;

    public function __construct(
        private readonly Tables $tables,
        private readonly CacheFactory $cache,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    /**
     * Whether HandleRedirects runs as global middleware. Where it does not,
     * a controller that answers 404 itself (the turnkey blog's) asks
     * answer() instead.
     */
    public function runsGlobally(): bool
    {
        return $this->config->get('xerads.middleware.global', true) !== false
            && $this->config->get('xerads.middleware.redirects', true) !== false;
    }

    /**
     * What a redirect answers this request with, or null when none applies:
     * only GET and HEAD (a form posted to an old address must not become a
     * GET), only while `xerads.redirects.enabled`.
     */
    public function answer(Request $request): ?SymfonyResponse
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || ! $this->config->get('xerads.redirects.enabled', true)) {
            return null;
        }

        try {
            $rule = $this->match($request->getPathInfo());
        } catch (Throwable) {
            return null;
        }

        return $rule === null ? null : $this->respond($rule, $request);
    }

    /**
     * @return array{id: int, origin: string, match: string, source: string, target: string|null, status: int, preserve_query: bool, case_insensitive: bool}|null
     */
    public function match(string $path): ?array
    {
        $path = Redirect::normalize($path);
        $rules = $this->rules();

        foreach ([$path, mb_strtolower($path)] as $candidate) {
            foreach ($rules['exact'][$candidate] ?? [] as $rule) {
                if (! $rule['case_insensitive'] && $rule['source'] !== $path) {
                    continue;
                }

                return $rule;
            }
        }

        foreach ($rules['prefix'] as $rule) {
            $subject = $rule['case_insensitive'] ? mb_strtolower($path) : $path;
            $source = $rule['case_insensitive'] ? mb_strtolower($rule['source']) : $rule['source'];

            if (self::covers('prefix', $source, $subject)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The response a rule answers with: a redirect, or the site's own error
     * page for 410 and 451. The address is relative when the target is a
     * path, so the visitor stays on whatever host they came in on and no
     * Host header is trusted. The visitor's query goes before the target's
     * fragment, where a browser reads it.
     *
     * @param  array{target: string|null, status: int, preserve_query: bool}  $rule
     */
    public function respond(array $rule, Request $request): SymfonyResponse
    {
        if (in_array($rule['status'], Redirect::GONE, true) || $rule['target'] === null || $rule['target'] === '') {
            return $this->gone(in_array($rule['status'], Redirect::GONE, true) ? $rule['status'] : 410, $request);
        }

        $target = $rule['target'];
        // As the visitor sent it: getQueryString() would reorder the parameters.
        $query = $request->server->get('QUERY_STRING');

        if ($rule['preserve_query'] && is_string($query) && $query !== '') {
            [$address, $fragment] = array_pad(explode('#', $target, 2), 2, null);
            $target = $address.(str_contains($address, '?') ? '&' : '?').$query.($fragment !== null ? '#'.$fragment : '');
        }

        $status = in_array($rule['status'], [301, 302, 303, 307, 308], true) ? $rule['status'] : 301;

        return new RedirectResponse($target, $status);
    }

    /**
     * 410 or 451 through the application's exception handler, so visitors
     * see the site's own error page (`errors::410`, `errors::4xx`, else the
     * framework's) saying the page is gone. HEAD gets no body.
     */
    private function gone(int $status, Request $request): SymfonyResponse
    {
        try {
            $response = $this->container->make(ExceptionHandler::class)->render($request, new HttpException($status));
        } catch (Throwable) {
            $response = new Response('', $status);
        }

        if ($response->getStatusCode() !== $status) {
            $response->setStatusCode($status);
        }

        if ($request->isMethod('HEAD')) {
            $response->setContent('');
        }

        return $response;
    }

    /** Whether a rule with this match and source applies to a path (both normalised). */
    public static function covers(string $match, string $source, string $path): bool
    {
        if ($path === $source) {
            return true;
        }

        return $match === 'prefix' && str_starts_with($path, rtrim($source, '/').'/');
    }

    public function forget(): void
    {
        try {
            if ($this->store()->increment($this->generationKey()) === false) {
                $this->store()->forever($this->generationKey(), 1);
            }
        } catch (Throwable) {
            // Nothing cached to drop.
        }
    }

    /**
     * @return array{exact: array<string, list<array{id: int, origin: string, match: string, source: string, target: string|null, status: int, preserve_query: bool, case_insensitive: bool}>>, prefix: list<array{id: int, origin: string, match: string, source: string, target: string|null, status: int, preserve_query: bool, case_insensitive: bool}>}
     */
    private function rules(): array
    {
        $key = $this->cacheKey();

        try {
            $cached = $this->store()->get($key);

            if (is_array($cached) && isset($cached['exact'], $cached['prefix'])) {
                return $cached;
            }
        } catch (Throwable) {
            // Read the table instead.
        }

        $rules = $this->load();

        try {
            $this->store()->put($key, $rules, self::CACHE_SECONDS);
        } catch (Throwable) {
            // Uncached, the next 404 reads the table again.
        }

        return $rules;
    }

    /**
     * @return array{exact: array<string, list<array{id: int, origin: string, match: string, source: string, target: string|null, status: int, preserve_query: bool, case_insensitive: bool}>>, prefix: list<array{id: int, origin: string, match: string, source: string, target: string|null, status: int, preserve_query: bool, case_insensitive: bool}>}
     */
    private function load(): array
    {
        $exact = [];
        $prefix = [];

        if (! $this->tables->exists('redirects')) {
            return ['exact' => [], 'prefix' => []];
        }

        foreach (Redirect::query()->where('active', true)->orderBy('id')->get() as $redirect) {
            $rule = [
                'id' => (int) $redirect->getKey(),
                'origin' => $redirect->origin,
                'match' => $redirect->match === 'prefix' ? 'prefix' : 'exact',
                'source' => Redirect::normalize($redirect->source),
                'target' => $redirect->target,
                'status' => $redirect->status,
                'preserve_query' => $redirect->preserve_query,
                'case_insensitive' => $redirect->case_insensitive,
            ];

            if ($rule['match'] === 'exact') {
                $exact[$rule['case_insensitive'] ? mb_strtolower($rule['source']) : $rule['source']][] = $rule;
            } else {
                $prefix[] = $rule;
            }
        }

        $priority = fn (array $rule) => Redirect::PRIORITY[$rule['origin']] ?? 3;

        foreach ($exact as $source => $candidates) {
            usort($candidates, fn (array $a, array $b) => [$priority($a), $a['id']] <=> [$priority($b), $b['id']]);
            $exact[$source] = $candidates;
        }

        usort($prefix, fn (array $a, array $b) => [mb_strlen($b['source']), $priority($a), $a['id']] <=> [mb_strlen($a['source']), $priority($b), $b['id']]);

        return ['exact' => $exact, 'prefix' => $prefix];
    }

    private function cacheKey(): string
    {
        try {
            $generation = $this->store()->get($this->generationKey());
        } catch (Throwable) {
            $generation = null;
        }

        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':redirects:'.(is_numeric($generation) ? (int) $generation : 0);
    }

    private function generationKey(): string
    {
        return (string) $this->config->get('xerads.cache.prefix', 'xerads').':redirects:generation';
    }

    private function store(): Cache
    {
        $store = $this->config->get('xerads.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
