<?php

namespace XerAds\Laravel\Sync\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use XerAds\Laravel\Support\Signature\V2Verifier;
use XerAds\Laravel\Sync\Envelope;
use XerAds\Laravel\Sync\WebhookReply;

/**
 * Admit only a signed contract 2 delivery from XerAds, for this site.
 *
 * Checked in order of cost: size, contract, signature (which covers the
 * timestamp, the key, the site and the exact body bytes), then that the
 * signed envelope agrees with the headers. Only the body is signed, so the
 * envelope inside it is what the rest of the request trusts; headers that
 * disagree with it mean the request was altered on the way.
 */
final class VerifyWebhookSignature
{
    public function __construct(private readonly V2Verifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('xerads.webhook.max_body_bytes', 2 * 1024 * 1024);

        if ((int) $request->header('Content-Length', '0') > $limit || strlen($request->getContent()) > $limit) {
            return WebhookReply::error(413, 'PAYLOAD_TOO_LARGE', "Deliveries are limited to {$limit} bytes.")->toResponse();
        }

        if ((string) $request->header('X-XerAds-Contract', '') !== (string) Envelope::CONTRACT) {
            return $this->contractUnsupported();
        }

        $result = $this->verifier->verifyPush(
            signature: (string) $request->header('X-XerAds-Signature', ''),
            timestamp: (string) $request->header('X-XerAds-Timestamp', ''),
            deliveryId: (string) $request->header('X-XerAds-Delivery', ''),
            rawBody: $request->getContent(),
            keyId: $request->headers->has('X-XerAds-Key-Id') ? (string) $request->header('X-XerAds-Key-Id') : null,
            // Always checked: a delivery that names no site is not for this one.
            siteId: (string) $request->header('X-XerAds-Site', ''),
        );

        if (! $result->ok) {
            return (new WebhookReply($result->status(), $result->toResponseBody()))->toResponse();
        }

        $envelope = Envelope::fromBody($request->getContent());

        if ($envelope === null) {
            return WebhookReply::error(400, 'INVALID_PAYLOAD', 'The body is not a contract 2 envelope.')->toResponse();
        }

        if ($envelope->contract !== Envelope::CONTRACT) {
            return $this->contractUnsupported();
        }

        if ($envelope->event !== (string) $request->header('X-XerAds-Event', '')
            || $envelope->deliveryId !== (string) $request->header('X-XerAds-Delivery', '')
            || $envelope->siteId !== (string) $request->header('X-XerAds-Site', '')) {
            return WebhookReply::error(400, 'INVALID_PAYLOAD', 'The envelope\'s event, delivery_id and site_id must equal the X-XerAds-* headers.')->toResponse();
        }

        $request->attributes->set(Envelope::class, $envelope);

        return $next($request);
    }

    private function contractUnsupported(): Response
    {
        return WebhookReply::error(400, 'CONTRACT_UNSUPPORTED', 'This site speaks contract '.Envelope::CONTRACT.'.', [
            'supported' => [Envelope::CONTRACT],
        ])->toResponse();
    }
}
