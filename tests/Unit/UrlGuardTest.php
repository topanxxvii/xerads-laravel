<?php

use XerAds\Laravel\Support\UrlGuard;

it('refuses addresses that never belong to a public web server', function (string $url) {
    expect((new UrlGuard)->runtimeReason($url, verifyDns: false))->not->toBeNull();
})->with([
    'plain http' => ['http://8.8.8.8/'],
    'other scheme' => ['ftp://8.8.8.8/'],
    'unparseable' => ['not a url'],
    'loopback' => ['https://127.0.0.1/'],
    'unspecified' => ['https://0.0.0.0/'],
    'private 10/8' => ['https://10.0.0.1/'],
    'private 172.16/12' => ['https://172.16.5.4/'],
    'private 192.168/16' => ['https://192.168.1.1/'],
    'link-local metadata' => ['https://169.254.169.254/latest/meta-data/'],
    'carrier-grade NAT' => ['https://100.64.0.1/'],
    'cloud metadata in CGNAT' => ['https://100.100.100.200/'],
    'benchmarking 198.18/15' => ['https://198.18.0.1/'],
    'IETF 192.0.0/24' => ['https://192.0.0.1/'],
    'documentation' => ['https://192.0.2.10/'],
    'multicast' => ['https://224.0.0.1/'],
    'multicast, SSDP' => ['https://239.255.255.250/'],
    'IPv6 loopback' => ['https://[::1]/'],
    'IPv6 unique local' => ['https://[fc00::1]/'],
    'IPv6 link-local' => ['https://[fe80::1]/'],
    'IPv6 multicast' => ['https://[ff02::1]/'],
    'NAT64 well-known prefix' => ['https://[64:ff9b::7f00:1]/'],
    'NAT64 local-use prefix' => ['https://[64:ff9b:1::a00:1]/'],
    'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/'],
    'localhost' => ['https://localhost/'],
    'localhost subdomain' => ['https://api.localhost/'],
    'mDNS' => ['https://printer.local/'],
    'internal' => ['https://db.internal/'],
    'localdomain' => ['https://box.localdomain/'],
    'home network' => ['https://router.home.arpa/'],
]);

it('accepts public addresses', function (string $url) {
    expect((new UrlGuard)->runtimeReason($url, verifyDns: false))->toBeNull();
})->with([
    'public IPv4' => ['https://8.8.8.8/'],
    'public IPv6' => ['https://[2606:4700:4700::1111]/'],
    'hostname, DNS not consulted' => ['https://example.com/widgets/v1/loader.js'],
    'hostname with port and query' => ['https://cdn.example.com:8443/a.png?x=1'],
]);

it('leaves numeric host spellings to the resolving half, which refuses them', function () {
    $guard = new UrlGuard;

    // 2130706433 and 0x7f.1 are 127.0.0.1 to the system resolver but not IP
    // literals to filter_var, so only the lookup sees through them. The
    // resolver parses numeric hosts itself; no DNS query leaves the machine.
    expect($guard->syntacticReason('https://2130706433/'))->toBeNull()
        ->and($guard->runtimeReason('https://2130706433/'))->toBe('The host resolves to a private or reserved address.');
});

it('accepts plain http only when asked to', function () {
    $guard = new UrlGuard;

    expect($guard->syntacticReason('http://8.8.8.8/'))->toBe('Only https URLs are allowed.')
        ->and($guard->syntacticReason('http://8.8.8.8/', requireHttps: false))->toBeNull()
        ->and($guard->syntacticReason('ftp://8.8.8.8/', requireHttps: false))->toBe('Only http and https URLs are allowed.');
});

it('reports the approved address for a literal and none when DNS was skipped', function () {
    $guard = new UrlGuard;

    expect($guard->vet('https://8.8.8.8/'))->toBe(['reason' => null, 'addresses' => ['8.8.8.8']])
        ->and($guard->vet('https://example.com/', verifyDns: false))->toBe(['reason' => null, 'addresses' => []])
        ->and($guard->vet('https://10.0.0.1/')['addresses'])->toBe([]);
});

it('classifies single addresses', function (string $address, bool $public) {
    expect((new UrlGuard)->isPublicIp($address))->toBe($public);
})->with([
    ['1.1.1.1', true],
    ['2001:4860:4860::8888', true],
    ['100.63.255.255', true],
    ['100.64.0.0', false],
    ['100.127.255.255', false],
    ['223.255.255.255', true],
    ['224.0.0.0', false],
    ['239.255.255.255', false],
    ['64:ff9b::808:808', false],
    ['not-an-ip', false],
]);
