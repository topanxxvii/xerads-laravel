<?php

namespace XerAds\Laravel\Content\Http;

use Illuminate\Http\Response;
use XerAds\Laravel\Content\Models\Article;

/**
 * `/{prefix}/preview/{xerads_id}?signature=…`: an article whatever its
 * status, for XerAds to open a draft before it is published.
 *
 * The signature (checked by the route's middleware) is made with this site's
 * application key and has no expiry; it is tied to the article's XerAds id
 * and stops working once the article is deleted. The page is never indexed
 * and never cached by a proxy.
 */
final class PreviewController
{
    public function __invoke(string $article): Response
    {
        $record = Article::query()->where('xerads_id', $article)->first() ?? abort(404);

        return response()
            ->view('xerads::blog.show', app(BlogController::class)->articleData($record, preview: true))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }
}
