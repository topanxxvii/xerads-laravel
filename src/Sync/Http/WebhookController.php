<?php

namespace XerAds\Laravel\Sync\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use XerAds\Laravel\Content\Exceptions\InvalidPayload;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;
use XerAds\Laravel\Content\Exceptions\WriteConflict;
use XerAds\Laravel\Sync\DeliveryLedger;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\EventRouter;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * `POST /xerads/v1/webhook`: every paired delivery arrives here.
 *
 * The signature middleware has checked who sent it. This decides whether it
 * has been seen before (the ledger), hands it to its event's handler, and
 * answers in the contract's shape. Every reply is JSON with a stable error
 * code, because XerAds decides from the code whether to retry, give up or
 * alert the owner.
 */
final class WebhookController
{
    public function __invoke(Request $request, EventRouter $router, DeliveryLedger $ledger): JsonResponse
    {
        $envelope = $request->attributes->get(Envelope::class);

        if (! $envelope instanceof Envelope) {
            return WebhookReply::error(400, 'INVALID_PAYLOAD', 'The delivery did not pass signature verification.')->toResponse();
        }

        // Refused before the ledger: nothing was processed, nothing to remember.
        if (! $router->supports($envelope->event)) {
            return $router->dispatch($envelope)->toResponse();
        }

        $claim = $ledger->claim($envelope->deliveryId, $envelope->event);

        if ($claim?->isReplay()) {
            // XerAds retried a delivery this site already applied, most often
            // after a timeout: the original answer, marked as a repeat.
            $body = $claim->response ?? [];
            $body['duplicate'] = true;

            return (new WebhookReply($claim->responseCode ?? 200, $body))->toResponse();
        }

        if ($claim?->isInProgress()) {
            return WebhookReply::error(409, 'DELIVERY_IN_PROGRESS', 'This delivery is being processed by another request. Retry shortly.')->toResponse();
        }

        $reply = $this->handle($router, $envelope);

        if ($claim !== null) {
            $reply->successful()
                ? $ledger->complete($claim, $reply->status, $reply->body)
                : $ledger->fail($claim, $reply->status, $reply->body, (string) ($reply->body['message'] ?? ''));
        }

        return $reply->toResponse();
    }

    private function handle(EventRouter $router, Envelope $envelope): WebhookReply
    {
        try {
            return $router->dispatch($envelope);
        } catch (InvalidPayload $exception) {
            return WebhookReply::error(422, 'INVALID_PAYLOAD', $exception->getMessage());
        } catch (WriteConflict $exception) {
            // XerAds retries a 409 in 30 seconds; the retry finds the race over.
            return WebhookReply::error(409, 'WRITE_CONFLICT', $exception->getMessage());
        } catch (ReceiverMisconfigured $exception) {
            return WebhookReply::error(422, 'RECEIVER_MISCONFIGURED', $exception->getMessage(), array_filter([
                'column' => $exception->column,
            ]));
        } catch (Throwable $exception) {
            report($exception);

            // XerAds retries a 500 with backoff; the site's log has the cause.
            return WebhookReply::error(500, 'RECEIVER_FAILED', 'The delivery could not be applied. The site\'s log has the details.');
        }
    }
}
