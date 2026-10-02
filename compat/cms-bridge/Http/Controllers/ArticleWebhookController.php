<?php

namespace XerAds\CmsBridge\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;
use XerAds\CmsBridge\Receivers\EloquentArticleReceiver;
use XerAds\Laravel\Content\ConfigurationReport;
use XerAds\Laravel\Content\Contracts\ValidatesConfiguration;
use XerAds\Laravel\Sync\DeliveryLedger;
use XerAds\Laravel\Sync\LedgerClaim;

/**
 * The legacy endpoint XerAds posts articles to.
 *
 * Signature verification happens in middleware, so by the time anything here
 * runs the body is known to be XerAds' and unaltered. Only the body is: the
 * query string and the Content-Type header are not signed, so nothing here
 * reads them.
 */
class ArticleWebhookController
{
    /**
     * A property with its own default, not a promoted one, so a subclass
     * written against the original controller (which had no constructor) and
     * never calling this one still finds null here and falls back to the
     * container.
     */
    private ?DeliveryLedger $xeradsLedger = null;

    public function __construct(?DeliveryLedger $ledger = null)
    {
        $this->xeradsLedger = $ledger;
    }

    public function __invoke(Request $request, ArticleReceiver $receiver): JsonResponse
    {
        $payload = IncomingArticle::payloadFromRequest($request);

        if ($payload === null) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PAYLOAD',
                'message' => 'The request body must be a JSON object.',
            ], 400);
        }

        /*
         * The connection test stores nothing, but it does check.
         *
         * XerAds sends a flagged, obviously fake article when someone presses
         * "Test connection". Answering 200 without looking was a false green:
         * a site pointing at a model that does not exist passed the test and
         * failed on its first real article. Now the receiver checks its own
         * setup, and the test fails with the reason.
         */
        if (IncomingArticle::isConnectionTestPayload($payload)) {
            return $this->answerConnectionTest($receiver);
        }

        $article = IncomingArticle::fromPayload($payload);

        if ($article->title === '' || $article->content === '') {
            return response()->json([
                'ok' => false,
                'error' => 'INCOMPLETE_ARTICLE',
                'message' => 'An article needs at least a title and content.',
            ], 422);
        }

        /*
         * Replay protection, for byte-identical copies only.
         *
         * The legacy request carries no delivery id, but its signature is
         * unique to one timestamp and one body, so it serves as one. A copy
         * of a request that was already stored — inside the timestamp window,
         * the only time a copy verifies at all — gets the original answer and
         * stores nothing.
         *
         * A XerAds retry is not such a copy: it is signed afresh with a new
         * timestamp. What keeps a retry from creating a second post is the
         * receiver matching the first attempt's row by slug.
         */
        $ledger = $this->xeradsLedger ?? app(DeliveryLedger::class);
        $deliveryId = $this->pseudoDeliveryId($request);
        $claim = $deliveryId !== null ? $ledger->claim($deliveryId, 'legacy.article') : null;

        if ($claim?->isReplay()) {
            return response()->json($claim->response, $claim->responseCode ?? 201);
        }

        if ($claim?->isInProgress()) {
            return response()->json([
                'ok' => false,
                'error' => 'DELIVERY_IN_PROGRESS',
                'message' => 'This request is already being processed. Retry shortly.',
            ], 409);
        }

        try {
            $result = $receiver->receive($article);
        } catch (HttpResponseException|HttpExceptionInterface|ValidationException $exception) {
            /*
             * A receiver that answers with an HTTP error on purpose — abort(),
             * a validation failure, a prepared response — keeps that answer.
             * The ledger records the real status, so a retry is processed
             * again rather than answered from it.
             */
            $this->recordFailure($ledger, $claim, $this->statusOf($exception), $exception);

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $body = [
                'ok' => false,
                'error' => 'RECEIVER_FAILED',
                'message' => 'The article could not be stored. The site\'s log has the details.',
            ];

            $this->recordFailure($ledger, $claim, 500, $exception, $body);

            return response()->json($body, 500);
        }

        /*
         * `id` and `url` are the whole response contract.
         *
         * XerAds stores `id` and echoes it back next time, which is what makes
         * a re-sync an edit rather than a second post; `url` becomes the
         * article's public link there. Returning neither is allowed and simply
         * gives both up.
         */
        $response = [
            'id' => $result['id'] ?? null,
            'url' => $result['url'] ?? null,
        ];

        if ($claim !== null) {
            $ledger->complete($claim, 201, $response);
        }

        Log::info('XerAds article received', [
            'slug' => $article->slug,
            'id' => $response['id'],
            'status' => $article->status,
        ]);

        return response()->json($response, 201);
    }

    private function answerConnectionTest(ArticleReceiver $receiver): JsonResponse
    {
        $report = $this->validationReport($receiver);

        if ($report === null) {
            // A custom receiver that cannot check itself; the request reached
            // it and the signature verified, which is all that can be said.
            return response()->json(['ok' => true, 'test' => true]);
        }

        if (! $report->isValid()) {
            return response()->json([
                'ok' => false,
                'error' => 'RECEIVER_MISCONFIGURED',
                'message' => $report->problems[0],
                'problems' => $report->problems,
                'warnings' => $report->warnings,
            ], 422);
        }

        return response()->json(array_filter([
            'ok' => true,
            'test' => true,
            'warnings' => $report->warnings,
        ], fn ($value) => $value !== []));
    }

    /**
     * The receiver's own check, when it has one that describes it.
     *
     * The shipped receiver checks the configured model. A subclass of it
     * inherits that check but may write somewhere else entirely, so it is
     * only asked when it opts in by implementing ValidatesConfiguration.
     */
    private function validationReport(ArticleReceiver $receiver): ?ConfigurationReport
    {
        if ($receiver instanceof ValidatesConfiguration) {
            return $receiver->validateConfiguration();
        }

        if ($receiver instanceof EloquentArticleReceiver && $receiver::class === EloquentArticleReceiver::class) {
            return $receiver->validateConfiguration();
        }

        return null;
    }

    /** @param  array<mixed>|null  $body */
    private function recordFailure(DeliveryLedger $ledger, ?LedgerClaim $claim, int $status, Throwable $exception, ?array $body = null): void
    {
        if ($claim === null) {
            return;
        }

        try {
            $ledger->fail($claim, $status, $body, $exception->getMessage());
        } catch (Throwable $ledgerException) {
            // The receiver's failure is the one worth seeing; the claim's
            // lease runs out and a later copy is processed anyway.
            report($ledgerException);
        }
    }

    private function statusOf(HttpResponseException|HttpExceptionInterface|ValidationException $exception): int
    {
        return match (true) {
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            $exception instanceof ValidationException => $exception->status,
            default => $exception->getStatusCode(),
        };
    }

    /** `v1-` + sha256 of the signature, or null for an unsigned request. */
    private function pseudoDeliveryId(Request $request): ?string
    {
        $signature = (string) $request->header('X-XerAds-Signature', '');

        return $signature !== '' ? 'v1-'.hash('sha256', $signature) : null;
    }
}
