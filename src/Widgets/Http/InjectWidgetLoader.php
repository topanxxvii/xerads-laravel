<?php

namespace XerAds\Laravel\Widgets\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use XerAds\Laravel\Widgets\Runtime;

/**
 * Adds the widget loader to a page that has a widget container and no loader.
 *
 * This is what makes widgets work with no template change: a site that
 * prints `{!! $post->body !!}` gets the containers from the stored body, and
 * this adds the one script that mounts them. It touches only an ordinary,
 * complete HTML page:
 *
 * - not a streamed, file, JSON or redirect response (only Illuminate's own
 *   Response holds its body in memory);
 * - not an Inertia response, whose page is rendered in the browser (use
 *   `<x-xerads::scripts spa />` in the root view);
 * - not a page that already has a loader, from the scripts component or a
 *   pasted embed code;
 * - not a fragment without `</body>`;
 * - not a path listed in `widgets.inject_except` (the back office, by
 *   default).
 *
 * ── Why it looks for a real element ─────────────────────────────────────────
 * Stored bodies contain containers, so the attribute name also turns up as
 * text: an edit form's textarea, JSON state in a script or an attribute, a
 * comment. A third-party script has no business on those pages, admin pages
 * least of all, so only an actual start tag carrying the attribute counts,
 * outside scripts, styles, textareas, templates and comments.
 *
 * Turn it off entirely with `xerads.widgets.inject_loader`.
 */
final class InjectWidgetLoader
{
    /** Where markup is text, not elements. */
    private const TEXT_CONTEXTS = '#<(script|style|textarea|template|noscript|title|xmp)\b[^>]*>.*?</\1\s*>|<!--.*?-->#is';

    /**
     * A start tag whose attributes include `data-xerads-widget`, stepping over
     * whole attributes (quoted values included, atomically) so the name only
     * counts where an attribute name can be, not inside another's value.
     */
    private const CONTAINER_TAG = '/<[a-z][a-z0-9-]*+(?>\s++[^\s"\'>\/=]++(?>\s*+=\s*+(?>"[^"]*+"|\'[^\']*+\'|[^\s"\'=<>`]++))?)*?\s++data-xerads-widget\s*+=/i';

    public function __construct(private readonly Runtime $runtime) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('xerads.widgets.inject_loader', true)
            || ! $response instanceof IlluminateResponse
            || $request->headers->has('X-Inertia')
            || $response->headers->has('X-Inertia')
            || $this->excluded($request)) {
            return $response;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));

        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, 'data-xerads-widget') || ! $this->hasContainer($content) || Runtime::hasLoader($content)) {
            return $response;
        }

        $position = strripos($content, '</body>');

        if ($position === false) {
            return $response;
        }

        $tag = $this->runtime->loaderTag(Vite::cspNonce());

        $response->setContent(substr($content, 0, $position).$tag.substr($content, $position));
        $response->headers->remove('Content-Length');

        return $response;
    }

    /** A container element, as markup the browser will build. */
    private function hasContainer(string $html): bool
    {
        $markup = preg_replace(self::TEXT_CONTEXTS, '', $html);

        // A page too large for the pattern engine gets no loader rather than
        // one it may not need.
        return is_string($markup) && preg_match(self::CONTAINER_TAG, $markup) === 1;
    }

    private function excluded(Request $request): bool
    {
        $patterns = array_values(array_filter((array) config('xerads.widgets.inject_except', []), 'is_string'));

        return $patterns !== [] && ($request->is(...$patterns) || $request->routeIs(...$patterns));
    }
}
