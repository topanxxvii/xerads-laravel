<?php

namespace XerAds\Laravel\Sync\Client;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;

/**
 * Makes the plain Guzzle client that pairing is sent with.
 *
 * XerAds' reply to a pairing carries the site key's secret. Laravel's HTTP
 * client announces every response it receives (`ResponseReceived`), and
 * request inspectors, on by default in many local setups, store what they
 * are told. A plain Guzzle client announces nothing.
 *
 * Bound in the container, so a test can hand out a client that answers from
 * a Guzzle handler of its own instead of the network.
 */
final class PairingClientFactory
{
    /**
     * @param  Closure|null  $handler  a Guzzle handler to send through instead of the network
     */
    public function __construct(private readonly ?Closure $handler = null) {}

    /** @param  array<string, mixed>  $options  Guzzle request options for every request */
    public function make(array $options = []): ClientInterface
    {
        return new Client(['handler' => HandlerStack::create($this->handler)] + $options);
    }
}
