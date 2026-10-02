<?php

namespace XerAds\Laravel\Seo\IndexNow\Http;

use Illuminate\Http\Response;
use XerAds\Laravel\Seo\IndexNow\IndexNowKey;

/**
 * `/{key}.txt`: proves to search engines that an IndexNow submission came
 * from this site. Only the site's own key answers; any other 32-character
 * name is a 404, like a missing file.
 */
final class KeyController
{
    public function __invoke(IndexNowKey $keys, string $key): Response
    {
        $current = $keys->current();

        if (! $keys->enabled() || $current === null || ! hash_equals($current, $key)) {
            abort(404);
        }

        return new Response($current, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
