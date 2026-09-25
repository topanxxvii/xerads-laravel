<?php

namespace XerAds\CmsBridge\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;

/**
 * The endpoint XerAds posts articles to.
 *
 * Signature verification happens in middleware, so by the time anything here
 * runs the request is known to be XerAds' and unaltered.
 */
class ArticleWebhookController
{
    public function __invoke(Request $request, ArticleReceiver $receiver): JsonResponse
    {
        /*
         * The connection test stores nothing.
         *
         * XerAds sends a flagged, obviously-fake article when someone presses
         * "Test connection". Persisting it would put "XerAds connection test"
         * in a live blog — which is exactly why the sender makes it findable,
         * and exactly why a correct receiver never needs that safety net.
         */
        if (IncomingArticle::isConnectionTest($request)) {
            return response()->json(['ok' => true, 'test' => true]);
        }

        $article = IncomingArticle::fromRequest($request);

        if ($article->title === '' || $article->content === '') {
            return response()->json([
                'error' => 'INCOMPLETE_ARTICLE',
                'message' => 'An article needs at least a title and content.',
            ], 422);
        }

        $result = $receiver->receive($article);

        Log::info('XerAds article received', [
            'slug' => $article->slug,
            'id' => $result['id'] ?? null,
            'status' => $article->status,
        ]);

        /*
         * `id` and `url` are the whole response contract.
         *
         * XerAds stores `id` and echoes it back next time, which is what makes
         * a re-sync an edit rather than a second post; `url` becomes the
         * article's public link there. Returning neither is allowed and simply
         * gives both up.
         */
        return response()->json([
            'id' => $result['id'] ?? null,
            'url' => $result['url'] ?? null,
        ], 201);
    }
}
