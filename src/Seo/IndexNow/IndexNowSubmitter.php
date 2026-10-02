<?php

namespace XerAds\Laravel\Seo\IndexNow;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Throwable;
use XerAds\Laravel\Seo\SiteAddress;
use XerAds\Laravel\Support\ErrorScrubber;
use XerAds\Laravel\Support\UrlGuard;
use XerAds\Laravel\Sync\RemoteState;

/**
 * Sends changed addresses to IndexNow (`indexnow.endpoint`), which shares
 * them with every search engine that takes part.
 *
 * Only from production (`indexnow.environments`), only for a site whose
 * address is https, only addresses on the site's own host, with the
 * connection to the endpoint checked and pinned like every outbound request.
 * The outcome is kept (`xerads_state.indexnow_last`) and reported in the
 * heartbeat: `{last_submitted_at, last_status, last_error}`.
 */
final class IndexNowSubmitter
{
    /** IndexNow takes at most 10,000 addresses per submission. */
    private const MAX_URLS = 10_000;

    public function __construct(
        private readonly IndexNowQueue $queue,
        private readonly IndexNowKey $key,
        private readonly SiteAddress $address,
        private readonly UrlGuard $guard,
        private readonly HttpFactory $http,
        private readonly RemoteState $state,
        private readonly ErrorScrubber $scrubber,
        private readonly Repository $config,
    ) {}

    /**
     * @param  list<string>  $urls
     * @return int|null the endpoint's status, or null when nothing was sent
     */
    public function submit(array $urls): ?int
    {
        if (! $this->queue->submits()) {
            return null;
        }

        $key = $this->key->current();

        if ($key === null) {
            return $this->failed(null, 'This site has no IndexNow key yet. Pair it, or wait for the settings to arrive.');
        }

        if (! $this->address->isHttps()) {
            return $this->failed(null, 'The site\'s address is not https; IndexNow is not used.');
        }

        $urls = $this->onOwnHost($urls);

        if ($urls === []) {
            return null;
        }

        $endpoint = (string) $this->config->get('xerads.indexnow.endpoint', 'https://api.indexnow.org/indexnow');
        $vetted = $this->guard->vet($endpoint, requireHttps: true, verifyDns: (bool) $this->config->get('xerads.http.verify_public_dns', true));

        if ($vetted['reason'] !== null) {
            return $this->failed(null, 'The IndexNow endpoint was refused: '.$vetted['reason']);
        }

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->acceptJson()
                ->withOptions($this->guard->pinned($endpoint, $vetted['addresses']))
                ->post($endpoint, [
                    'host' => $this->address->host(),
                    'key' => $key,
                    'keyLocation' => $this->address->url('/'.$key.'.txt'),
                    'urlList' => $urls,
                ]);
        } catch (Throwable $exception) {
            return $this->failed(null, 'IndexNow could not be reached: '.$exception->getMessage());
        }

        $status = $response->status();

        if ($status === 200 || $status === 202) {
            $this->record($status, null);

            return $status;
        }

        return $this->failed($status, "IndexNow answered {$status}.");
    }

    /**
     * The addresses on the site's own host, put on its base address, once
     * each: an address built while handling a request carries that request's
     * host, which may be another name for the site.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function onOwnHost(array $urls): array
    {
        $own = [];

        foreach ($urls as $url) {
            $candidate = $this->address->url($url);

            if (strtolower((string) parse_url($candidate, PHP_URL_HOST)) === $this->address->host()) {
                $own[] = $candidate;
            }
        }

        return array_slice(array_values(array_unique($own)), 0, self::MAX_URLS);
    }

    private function failed(?int $status, string $error): ?int
    {
        $this->record($status, $error);

        return $status;
    }

    private function record(?int $status, ?string $error): void
    {
        if (! $this->state->available()) {
            return;
        }

        $this->state->put('indexnow_last', [
            'last_submitted_at' => Carbon::now()->toIso8601String(),
            'last_status' => $status,
            'last_error' => $error !== null ? $this->scrubber->scrub($error) : null,
        ]);
    }
}
