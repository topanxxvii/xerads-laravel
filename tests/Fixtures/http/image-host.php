<?php

/*
 * A tiny image host for the tests that send real requests through Guzzle's
 * own handlers, run with `php -S 127.0.0.1:{port} image-host.php`. Only ever
 * reached through a CURLOPT_RESOLVE pin to 127.0.0.1: the names the tests use
 * (*.xerads.test) do not resolve.
 */

$png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgwAQAABQAAWX1h1kAAAAASUVORK5CYII=');

switch (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/image.png':
        header('Content-Type: image/png');
        header('Content-Length: '.strlen($png));
        echo $png;
        break;

    case '/declared-large.png':
        // Says 50 MB; sends a few bytes. The client must stop at the headers.
        header('Content-Type: image/png');
        header('Content-Length: 50000000');
        echo $png;
        break;

    case '/undeclared-large.png':
        // 4 MB with no Content-Length: only counting can stop it.
        header('Content-Type: image/png');

        for ($chunk = 0; $chunk < 64; $chunk++) {
            echo str_repeat("\0", 65536);
            flush();
        }
        break;

    default:
        http_response_code(404);
}
