<?php

namespace XerAds\Laravel\Support;

use Closure;

/**
 * SSRF guard for every request this package sends to a URL it was given:
 * article images it mirrors, widget documents, the XerAds API address.
 *
 * The same rules XerAds applies to the URLs customers type, so a URL one side
 * refuses is never fetched by the other.
 *
 * Split in two on purpose. The syntactic half needs no network and runs where
 * a URL is accepted. The resolving half runs immediately before the request,
 * because a hostname that pointed at a public address when it was stored can
 * point at 169.254.169.254 by the time a queue picks the work up.
 */
final class UrlGuard
{
    /**
     * `$resolver` looks a host up instead of DNS: for tests, or for a site
     * with a resolver of its own.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * Hostnames that never resolve outside the machine or the cluster, and
     * would otherwise sail past the IP-literal check.
     */
    private const BLOCKED_SUFFIXES = [
        'localhost', '.localhost', '.local', '.internal', '.localdomain', '.home.arpa',
    ];

    /**
     * Ranges FILTER_FLAG_GLOBAL_RANGE still calls global.
     *
     * NAT64 prefixes carry an IPv4 address in their low bits, so
     * 64:ff9b::7f00:1 IS 127.0.0.1 on a host with a NAT64 gateway. Multicast
     * is never a web server. The flag already covers the rest of RFC 6890:
     * carrier-grade NAT (100.64.0.0/10, which includes a cloud metadata
     * address at 100.100.100.200), 198.18.0.0/15, 192.0.0.0/24, 6to4, Teredo
     * and the documentation ranges, none of which the older
     * NO_PRIV_RANGE | NO_RES_RANGE pair rejected.
     */
    private const EXTRA_BLOCKED_CIDRS = [
        '224.0.0.0/4',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        'ff00::/8',
    ];

    /**
     * @param  bool  $requireHttps  https only unless a caller has a reason to
     *                              accept plain http
     * @return string|null the reason it is unsafe, or null when it passes
     */
    public function syntacticReason(string $url, bool $requireHttps = true): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'The URL could not be parsed.';
        }

        $scheme = strtolower($parts['scheme']);

        if ($requireHttps ? $scheme !== 'https' : ! in_array($scheme, ['http', 'https'], true)) {
            return $requireHttps ? 'Only https URLs are allowed.' : 'Only http and https URLs are allowed.';
        }

        $host = strtolower($parts['host']);

        if ($host === '') {
            return 'The URL has no host.';
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return 'Loopback and internal hostnames are not allowed.';
            }
        }

        // An IPv6 literal arrives from parse_url still wrapped in brackets.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false && ! $this->isPublicIp($literal)) {
            return 'Private and reserved IP addresses are not allowed.';
        }

        return null;
    }

    /**
     * The full check, including DNS. Returns the reason it is unsafe, or null.
     *
     * @param  bool  $verifyDns  false skips the lookup, for tests and for
     *                           callers that pin the connection themselves
     */
    public function runtimeReason(string $url, bool $requireHttps = true, bool $verifyDns = true): ?string
    {
        return $this->vet($url, $requireHttps, $verifyDns)['reason'];
    }

    /**
     * The full check, plus the addresses it approved.
     *
     * A caller that wants to close the gap between this lookup and the
     * connection (DNS rebinding) pins the request to one of `addresses`.
     * `addresses` is empty when DNS was not consulted.
     *
     * @return array{reason: string|null, addresses: list<string>}
     */
    public function vet(string $url, bool $requireHttps = true, bool $verifyDns = true): array
    {
        $reason = $this->syntacticReason($url, $requireHttps);

        if ($reason !== null) {
            return ['reason' => $reason, 'addresses' => []];
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Already validated as a public literal above.
            return ['reason' => null, 'addresses' => [$host]];
        }

        if (! $verifyDns) {
            return ['reason' => null, 'addresses' => []];
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            return ['reason' => 'The host could not be resolved.', 'addresses' => []];
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                return ['reason' => 'The host resolves to a private or reserved address.', 'addresses' => []];
            }
        }

        return ['reason' => null, 'addresses' => $addresses];
    }

    /**
     * A CURLOPT_RESOLVE entry that pins a request to the first address `vet()`
     * approved, so the connection goes where the check looked, not wherever
     * the name resolves a moment later. Null when there is nothing to pin: no
     * addresses (DNS not consulted), a host that is already an IP literal, or
     * no cURL to pin with.
     *
     * Only cURL applies it, so a pinned request must never be streamed
     * (`stream => true`): Guzzle sends streamed requests through its stream
     * handler, which ignores the pin (Guzzle 7) or refuses the request
     * (Guzzle 8). Use `pinned()` to get the request options.
     *
     * @param  list<string>  $addresses
     */
    public function pin(string $url, array $addresses): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $addresses === [] || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || ! self::curlAvailable()) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT) ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443);
        $address = $addresses[0];

        return $host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address);
    }

    /**
     * The Guzzle request options that pin a request (see `pin()`), or none.
     *
     * @param  list<string>  $addresses
     * @return array{curl?: array<int, mixed>}
     */
    public function pinned(string $url, array $addresses): array
    {
        $pin = $this->pin($url, $addresses);

        return $pin !== null ? ['curl' => [CURLOPT_RESOLVE => [$pin]]] : [];
    }

    /**
     * Does Guzzle send requests through cURL here? It does whenever the
     * extension is loaded, and only cURL takes `curl` options.
     */
    public static function curlAvailable(): bool
    {
        return \function_exists('curl_exec') && \function_exists('curl_multi_exec') && \defined('CURLOPT_RESOLVE');
    }

    public function isPublicIp(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_CIDRS as $cidr) {
            if ($this->inCidr($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(array_unique(($this->resolver)($host)));
        }

        $addresses = gethostbynamel($host);
        $addresses = is_array($addresses) ? $addresses : [];

        // A host with only an AAAA record would otherwise look unresolvable and
        // be rejected, which is a false negative rather than a safe default.
        $records = @dns_get_record($host, DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function inCidr(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($addressBytes, $networkBytes, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
