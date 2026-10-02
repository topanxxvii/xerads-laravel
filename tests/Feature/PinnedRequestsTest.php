<?php

/**
 * Every request to an address UrlGuard checked is pinned to the address it
 * approved (CURLOPT_RESOLVE), and only cURL applies a pin. These tests send
 * real requests through the installed Guzzle's own handlers to a local image
 * host, reached only through the pin, and check what each caller hands its
 * HTTP client with DNS verification on.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use XerAds\Laravel\Content\Media\MediaMirror;
use XerAds\Laravel\Content\Media\MediaRefused;
use XerAds\Laravel\Support\UrlGuard;
use XerAds\Laravel\Sync\Client\PairingClientFactory;
use XerAds\Laravel\Sync\Client\XeradsClient;
use XerAds\Laravel\Widgets\DocumentCache;

/** What the fake resolver answers for every name: a public address. */
const RESOLVED_ADDRESS = '93.184.216.34';

/**
 * Start the local image host; stopped when the test ends.
 *
 * @return array{0: Process, 1: int} the server and its port
 */
function startImageHost(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
    fclose($probe);

    $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/../Fixtures/http/image-host.php']);
    $server->start();

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($connection !== false) {
            fclose($connection);

            return [$server, $port];
        }

        usleep(20_000);
    }

    $server->stop();
    throw new RuntimeException('The local image host did not start.');
}

/** Every message in an exception's chain, for asserting why it failed. */
function messages(Throwable $exception): string
{
    $messages = [];

    for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
        $messages[] = $cause::class.': '.$cause->getMessage();
    }

    return implode("\n", $messages);
}

/** DNS verification on, with every name resolving to RESOLVED_ADDRESS. */
function verifyDns(): void
{
    config(['xerads.http.verify_public_dns' => true]);
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => [RESOLVED_ADDRESS]));
    app()->forgetScopedInstances();
}

describe('through the real handlers', function () {
    beforeEach(function () {
        Http::allowStrayRequests();
        [$this->server, $this->port] = startImageHost();
        $this->host = 'images.xerads.test:'.$this->port;
    });

    afterEach(function () {
        $this->server->stop();
    });

    it('sends a pinned image download through cURL, to the pinned address', function () {
        $url = "http://{$this->host}/image.png";
        $options = app(MediaMirror::class)->requestOptions($url, ['127.0.0.1'], 1_048_576);

        expect($options)->not->toHaveKey('stream')
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe([$this->host.':127.0.0.1']);

        $response = Http::withOptions($options)->get($url);

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe((string) base64_decode(PNG_3X2_FOR_HOST));

        // Without the pin the name does not resolve: the pin is what connected.
        expect(fn () => Http::get($url))->toThrow(ConnectionException::class, 'Could not resolve host');
    });

    it('sends the other pinned requests through cURL too', function () {
        $url = "http://{$this->host}/image.png";
        $pinned = app(UrlGuard::class)->pinned($url, ['127.0.0.1']);

        // As the widget documents and the XerAds API are fetched.
        expect(Http::withOptions($pinned)->withoutRedirecting()->get($url)->status())->toBe(200);

        // As pairing is sent, on its own Guzzle client.
        expect((new PairingClientFactory)->make()->request('GET', $url, $pinned + ['http_errors' => false])->getStatusCode())->toBe(200);
    });

    it('stops a download whose declared size is over the cap at the headers', function () {
        $url = "http://{$this->host}/declared-large.png";

        try {
            Http::withOptions(app(MediaMirror::class)->requestOptions($url, ['127.0.0.1'], 1024))->get($url);
            $this->fail('The download was not stopped.');
        } catch (Throwable $exception) {
            expect(messages($exception))->toContain(MediaRefused::class.': The image is larger than 1 KB');
        }
    });

    it('stops a download without a declared size at the cap', function () {
        $url = "http://{$this->host}/undeclared-large.png";

        try {
            Http::withOptions(app(MediaMirror::class)->requestOptions($url, ['127.0.0.1'], 1_048_576))->get($url);
            $this->fail('The download was not stopped.');
        } catch (Throwable $exception) {
            // Stopped by counting, long before the 4 MB arrived.
            expect(messages($exception))->toContain(MediaRefused::class.': The image is larger than 1 MB');
        }
    });
});

describe('with DNS verification on', function () {
    beforeEach(function () {
        verifyDns();
    });

    it('pins an image copy and never streams it', function () {
        config(['xerads.media.mirror' => true]);
        Storage::fake('public', ['url' => 'http://localhost/storage']);
        $seen = new ArrayObject;

        Http::fake(['*' => function (Request $request, array $options) use ($seen) {
            $seen['options'] = $options;

            return Http::response((string) base64_decode(PNG_3X2_FOR_HOST), 200, ['Content-Type' => 'image/png']);
        }]);

        app(MediaMirror::class)->mirror('https://cdn.xerads.test/a.png');

        expect($seen['options'])->not->toHaveKey('stream')
            ->and($seen['options']['curl'])->toBe([CURLOPT_RESOLVE => ['cdn.xerads.test:443:'.RESOLVED_ADDRESS]])
            ->and($seen['options'])->toHaveKeys(['on_headers', 'progress']);
    });

    it('pins the XerAds API, pairing included, and widget documents', function () {
        config(['xerads.credentials.key' => testSiteKey()]);
        $seen = new ArrayObject;

        Http::fake(['*' => function (Request $request, array $options) use ($seen) {
            $seen[(string) parse_url($request->url(), PHP_URL_PATH)] = $options;

            return Http::response(str_contains($request->url(), '/w/') ? ['layout' => ['min_height' => ['mobile' => 640]]] : heartbeatReply());
        }]);

        app()->instance(PairingClientFactory::class, new PairingClientFactory(function ($request, array $options) use ($seen) {
            $seen['pair'] = $options;

            return Http::response(pairReply(), 201);
        }));

        app(XeradsClient::class)->heartbeat([]);
        app(XeradsClient::class)->pair(['code' => 'xpc_9fK2mQ7xL4vN8pR1sT6wY3zA']);
        expect(app(DocumentCache::class)->minHeight('w_k3v9q2m8x1c4b7na'))->toBe(640);

        foreach (['/api/site/v1/heartbeat' => 'api.xerads.id:443', 'pair' => 'api.xerads.id:443', '/w/w_k3v9q2m8x1c4b7na.json' => 'widgets.xerads.id:443'] as $request => $host) {
            expect($seen[$request])->not->toHaveKey('stream')
                ->and($seen[$request]['curl'][CURLOPT_RESOLVE])->toBe([$host.':'.RESOLVED_ADDRESS]);
        }
    });

    it('refuses a name that resolves to a private address, before any request', function () {
        app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['10.0.0.8']));
        Http::fake();

        expect(fn () => app(MediaMirror::class)->mirror('https://cdn.xerads.test/a.png'))->toThrow(MediaRefused::class, 'private or reserved');

        Http::assertNothingSent();
    });
});

const PNG_3X2_FOR_HOST = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgwAQAABQAAWX1h1kAAAAASUVORK5CYII=';
