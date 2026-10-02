<?php

namespace XerAds\Laravel\Seo\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Keeps robots.txt, the sitemaps, llms.txt and the IndexNow key reachable on
 * a site with a catch-all route.
 *
 * The package's routes are registered after the site's own, so a site route
 * like `/{slug}` or an SPA's `/{any}` would answer these paths first: with a
 * 404 from its CMS lookup, or with the SPA's page. For one of these paths,
 * when the router would hand the request to a route of the site's with
 * parameters, this answers with the package's route instead. A site route
 * for the exact path (its own `/robots.txt`) still wins, and so does the
 * site's catch-all wherever the package has nothing to serve (sitemaps off,
 * llms.txt off, another `.txt` name).
 *
 * Global, pushed through the HTTP kernel; `xerads.middleware.seo_files`
 * turns it off.
 */
final class ServeSeoFiles
{
    /** The package's routes for these paths, by name. */
    public const ROUTES = ['xerads.robots', 'xerads.sitemap.index', 'xerads.sitemap.page', 'xerads.llms', 'xerads.indexnow.key'];

    /** A cheap first look, so other requests pay one regular expression. */
    private const PATHS = '#^/(robots\.txt|sitemap\.xml|sitemaps/[a-z]+-[0-9]+\.xml|llms\.txt|[a-f0-9]{32}\.txt)$#D';

    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true) || preg_match(self::PATHS, $request->getPathInfo()) !== 1) {
            return $next($request);
        }

        $route = self::shadowed($this->router, $request);

        if ($route === null) {
            return $next($request);
        }

        try {
            $route = clone $route;
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);

            return $this->router->prepareResponse($request, $route->run());
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }

            // Nothing of the package's here: the site answers as it would have.
            return $next($request);
        }
    }

    /**
     * The package's route for this request when a route of the site's with
     * parameters would answer it instead; null when the router already
     * reaches the package's route, the site has a route for the exact path,
     * or the package has no route for it.
     */
    public static function shadowed(Router $router, Request $request): ?Route
    {
        $routes = $router->getRoutes();
        $ours = null;

        foreach (self::ROUTES as $name) {
            $route = $routes->getByName($name);

            if ($route !== null && $route->matches($request)) {
                $ours = $route;

                break;
            }
        }

        if ($ours === null) {
            return null;
        }

        try {
            $matched = $routes->match($request);
        } catch (Throwable) {
            return $ours;
        }

        // The router reaches the package already, or the site answers this
        // exact path itself (a route registered later replaces the
        // package's under the same path).
        if ($matched === $ours || $matched->uri() === $ours->uri() || $matched->parameterNames() === []) {
            return null;
        }

        return $ours;
    }
}
