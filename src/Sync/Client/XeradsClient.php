<?php

namespace XerAds\Laravel\Sync\Client;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;
use XerAds\Laravel\Support\Credentials;
use XerAds\Laravel\Support\CredentialsResolver;
use XerAds\Laravel\Support\Signature\V2Signer;
use XerAds\Laravel\Support\UrlGuard;
use XerAds\Laravel\Support\Version;
use XerAds\Laravel\Sync\Client\Exceptions\ApiUnavailable;
use XerAds\Laravel\Sync\Client\Exceptions\AuthenticationFailed;
use XerAds\Laravel\Sync\Client\Exceptions\ClockSkew;
use XerAds\Laravel\Sync\Client\Exceptions\NotPaired;
use XerAds\Laravel\Sync\Client\Exceptions\PairingRejected;
use XerAds\Laravel\Sync\Client\Exceptions\RateLimited;
use XerAds\Laravel\Sync\Client\Exceptions\SiteRevoked;
use XerAds\Laravel\Sync\Client\Exceptions\XeradsApiException;

/**
 * XerAds' site API (`{api.url}/api/site/v1`), as this site calls it.
 *
 * Every request but pairing is signed with the site's key, the way XerAds
 * verifies a pull: `v2.{ts}.{nonce}.{METHOD}.{request_uri}.{sha256(body)}`,
 * over the path and query exactly as sent, with a fresh nonce, so a captured
 * request can neither be replayed nor pointed at another endpoint.
 *
 * The API address comes from config and is checked by UrlGuard before every
 * request (https, a public address, the connection pinned to the address
 * checked), so a misconfigured or tampered address cannot send this site's
 * signed requests into its own network. Redirects are never followed: a
 * signature is for one URL only.
 *
 * Errors become typed exceptions from the API's own `{error, message}`.
 */
final class XeradsClient
{
    public const API_PATH = '/api/site/v1';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
        private readonly CredentialsResolver $credentials,
        private readonly V2Signer $signer,
        private readonly UrlGuard $guard,
        private readonly Application $app,
        private readonly PairingClientFactory $pairingClients,
    ) {}

    /** The site API's base URL, from `xerads.api.url`. */
    public function baseUrl(): string
    {
        return rtrim((string) $this->config->get('xerads.api.url', 'https://api.xerads.id'), '/').self::API_PATH;
    }

    /**
     * Exchange a one-time code for this site's key. Unsigned: the code is
     * the credential.
     *
     * The reply carries the secret, so this one request is not sent with
     * Laravel's HTTP client, which announces every response it receives to
     * its listeners (see PairingClientFactory). It gets the same address
     * check, pinning, timeouts and refusal of redirects as every other.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PairingRejected|XeradsApiException
     */
    public function pair(array $body): array
    {
        $url = $this->baseUrl().'/pair';

        $options = $this->vet($url) + [
            'timeout' => $this->timeout(),
            'connect_timeout' => $this->connectTimeout(),
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => $this->userAgent(),
            ],
            'body' => $this->encode($body),
        ];

        try {
            $response = new Response($this->pairingClients->make()->request('POST', $url, $options));
        } catch (Throwable $exception) {
            throw new ApiUnavailable('XerAds could not be reached: '.$this->describe($exception), previous: $exception);
        }

        if ($response->status() === 201 || $response->status() === 200) {
            return $this->json($response);
        }

        $json = $this->json($response);

        if (is_string($json['error'] ?? null) && $response->status() !== 429 && $response->status() < 500) {
            throw new PairingRejected($this->message($json, 'XerAds refused the pairing code.'), $response->status(), $json['error'], $json);
        }

        throw $this->failure($response);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function heartbeat(array $report): array
    {
        return $this->json($this->signed('POST', '/heartbeat', $report));
    }

    public function settings(?string $etag = null): PullResult
    {
        return $this->pull('/settings', $etag);
    }

    public function redirects(?string $etag = null): PullResult
    {
        return $this->pull('/redirects', $etag);
    }

    /**
     * Report paths that answered 404 (paths only, no queries, no visitor data).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function notFound(array $items): array
    {
        return $this->json($this->signed('POST', '/not-found', ['items' => $items]));
    }

    /**
     * An article this site holds, as the envelope a push carries.
     *
     * @return array<string, mixed>
     */
    public function article(string $xeradsId): array
    {
        return $this->json($this->signed('GET', '/articles/'.rawurlencode($xeradsId)));
    }

    /**
     * The headers of a signed request. Public so the exact bytes signed can
     * be tested against the shared signature vectors.
     *
     * @return array<string, string>
     */
    public function signatureHeaders(Credentials $credentials, string $method, string $requestUri, string $body, int $timestamp, string $nonce): array
    {
        return [
            'X-XerAds-Site' => $credentials->siteId,
            'X-XerAds-Key-Id' => $credentials->keyId,
            'X-XerAds-Timestamp' => (string) $timestamp,
            'X-XerAds-Nonce' => $nonce,
            'X-XerAds-Plugin-Version' => Version::VERSION,
            'X-XerAds-Signature' => $this->signer->pull($credentials->secret(), $timestamp, $nonce, $method, $requestUri, $body),
        ];
    }

    private function pull(string $path, ?string $etag): PullResult
    {
        $response = $this->signed('GET', $path, null, $etag !== null && $etag !== '' ? ['If-None-Match' => $etag] : []);
        $returned = $response->header('ETag') !== '' ? $response->header('ETag') : $etag;

        return $response->status() === 304
            ? PullResult::notModified($returned)
            : PullResult::modified($this->json($response), $returned);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function signed(string $method, string $path, ?array $body = null, array $headers = []): Response
    {
        $credentials = $this->credentials->current();

        if ($credentials === null) {
            throw new NotPaired;
        }

        $url = $this->baseUrl().$path;
        $payload = $body !== null ? $this->encode($body) : '';
        $requestUri = (string) parse_url($url, PHP_URL_PATH).(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : '');

        $signature = $this->signatureHeaders(
            $credentials,
            $method,
            $requestUri,
            $payload,
            Carbon::now()->getTimestamp(),
            // 32 letters and digits: inside the 16–64 `[A-Za-z0-9_-]` XerAds accepts.
            Str::random(32),
        );

        $response = $this->send(function (PendingRequest $request) use ($method, $url, $payload, $signature, $headers) {
            $request = $request->withHeaders($signature + $headers);

            return $method === 'GET'
                ? $request->get($url)
                : $request->withBody($payload, 'application/json')->send($method, $url);
        }, $url);

        if ($response->successful() || $response->status() === 304) {
            return $response;
        }

        throw $this->failure($response);
    }

    /** @param  callable(PendingRequest): Response  $send */
    private function send(callable $send, string $url): Response
    {
        $request = $this->http
            ->timeout($this->timeout())
            ->connectTimeout($this->connectTimeout())
            ->withoutRedirecting()
            ->acceptJson()
            ->withUserAgent($this->userAgent())
            ->withOptions($this->vet($url));

        try {
            return $send($request);
        } catch (Throwable $exception) {
            throw new ApiUnavailable('XerAds could not be reached: '.$this->describe($exception), previous: $exception);
        }
    }

    private function timeout(): float
    {
        return (float) $this->config->get('xerads.api.timeout', 10);
    }

    private function connectTimeout(): float
    {
        return (float) $this->config->get('xerads.api.connect_timeout', 5);
    }

    private function userAgent(): string
    {
        return 'XerAds-Laravel/'.Version::VERSION;
    }

    /**
     * Refuse an API address on a private network, and pin the connection to
     * the address that was checked. A local XerAds (development only) is
     * allowed with `xerads.api.allow_private_hosts`, never in production.
     *
     * Never streamed: only cURL applies the pin (see UrlGuard::pin()).
     *
     * @return array{curl?: array<int, mixed>} the request options that pin it
     */
    private function vet(string $url): array
    {
        if ($this->config->get('xerads.api.allow_private_hosts', false) && ! $this->app->isProduction()) {
            return [];
        }

        $vetted = $this->guard->vet($url, requireHttps: true, verifyDns: (bool) $this->config->get('xerads.http.verify_public_dns', true));

        if ($vetted['reason'] !== null) {
            throw new ApiUnavailable('The XerAds API address (xerads.api.url) was refused: '.$vetted['reason']);
        }

        return $this->guard->pinned($url, $vetted['addresses']);
    }

    /** The typed exception for a refusal, from XerAds' `{error, message}`. */
    private function failure(Response $response): XeradsApiException
    {
        $json = $this->decoded($response);
        $code = is_string($json['error'] ?? null) ? $json['error'] : null;
        $status = $response->status();
        $message = $this->message($json, "XerAds answered {$status}.");

        return match (true) {
            // Only in XerAds' own words: a 410 from a proxy or a wrong
            // api.url must not unpair the site.
            $code === 'SITE_REVOKED' => new SiteRevoked($message, $status, $code, $json),
            $code === 'SITE_TIMESTAMP_SKEW' => new ClockSkew($message, $status, $code, $json),
            $status === 401 && $code !== null => new AuthenticationFailed($message, $status, $code, $json),
            $status === 429 => new RateLimited($message, $status, $code, $json),
            // Anything else without XerAds' own error shape (an error page, a
            // redirect, a 5xx) is the API being unavailable, not a refusal.
            $code === null || $status >= 500 || ($status >= 300 && $status < 400) => new ApiUnavailable($message, $status, $code, $json),
            default => new XeradsApiException($message, $status, $code, $json),
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiUnavailable for a body that is not a JSON object
     */
    private function json(Response $response): array
    {
        $json = $this->decoded($response);

        if ($json === [] && trim($response->body()) !== '' && trim($response->body()) !== '{}') {
            throw new ApiUnavailable('XerAds answered with something other than JSON.', $response->status());
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private function decoded(Response $response): array
    {
        $json = json_decode($response->body(), true);

        return is_array($json) && ! array_is_list($json) ? $json : [];
    }

    /** @param  array<string, mixed>  $json */
    private function message(array $json, string $fallback): string
    {
        $message = is_string($json['message'] ?? null) ? trim(mb_substr($json['message'], 0, 300)) : '';

        return $message !== '' ? $message : $fallback;
    }

    /** @param  array<string, mixed>  $body */
    private function encode(#[SensitiveParameter] array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** What went wrong on the wire, without the request (its headers are signed). */
    private function describe(Throwable $exception): string
    {
        $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '';

        return mb_substr($message !== '' ? $message : $exception::class, 0, 200);
    }
}
