<?php

namespace XerAds\Laravel\Content\Media;

use DOMElement;
use finfo;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use XerAds\Laravel\Content\Models\Media;
use XerAds\Laravel\Support\Html\Dom;
use XerAds\Laravel\Support\Tables;
use XerAds\Laravel\Support\UrlGuard;

/**
 * Copies article images onto this site's disk (`media.disk`).
 *
 * XerAds replaces an image file when it is regenerated, so a page that
 * hot-links the original breaks the day someone presses "regenerate". The
 * copy is what pages show from then on.
 *
 * ── What is fetched ─────────────────────────────────────────────────────────
 * Only https addresses that UrlGuard accepts, with the connection pinned to
 * the address it checked, no redirects, a 15-second budget and a size cap
 * (`media.max_bytes`, 10 MB) that ends the transfer once it is passed. The bytes must be a JPEG, PNG, WebP, GIF or AVIF
 * by their content, not their name, and must decode as an image: an SVG (which
 * can carry script) or an HTML error page saved as `.png` is refused.
 *
 * ── Where it goes ───────────────────────────────────────────────────────────
 * `{media.path}/{Y}/{m}/{hash}-{name}.{ext}`, the extension taken from the
 * content type, never from the address. Each source address is copied once
 * (`xerads_media`, keyed by its hash), however many articles or edits use it.
 */
final class MediaMirror
{
    /** What may be copied, by content type, with the extension it is saved under. */
    public const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    public const TIMEOUT_SECONDS = 15;

    /** An article with more images than this is copied this many at a time. */
    public const MAX_IMAGES_PER_ARTICLE = 50;

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly HttpFactory $http,
        private readonly FilesystemFactory $storage,
        private readonly Tables $tables,
        private readonly Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('xerads.media.mirror', true) && $this->tables->exists('media');
    }

    /**
     * The copy of an image, downloading it the first time.
     *
     * @throws MediaRefused when it cannot or may not be copied
     */
    public function mirror(string $url, ?string $alt = null): Media
    {
        $url = trim($url);
        $known = Media::query()->where('source_url_hash', Media::hashOf($url))->first();

        if ($known !== null && $this->storage->disk($known->disk)->exists($known->path)) {
            return $known;
        }

        [$bytes, $type, $width, $height] = $this->download($url);

        $disk = $this->disk();
        $path = $this->pathFor($url, $type);

        if (! $this->storage->disk($disk)->put($path, $bytes, ['visibility' => 'public'])) {
            throw new MediaRefused("The image from {$url} could not be written to the {$disk} disk.");
        }

        /** @var Media $media */
        $media = Media::query()->updateOrCreate(['source_url_hash' => Media::hashOf($url)], [
            'source_url' => $url,
            'disk' => $disk,
            'path' => $path,
            'mime' => $type,
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($bytes),
            'alt' => $alt,
        ]);

        return $media;
    }

    /**
     * The copies already made of these addresses, by address: no download,
     * one query. Lets an edit that keeps its images show the copies at once
     * instead of the originals until the next copy run.
     *
     * @param  list<string>  $urls
     * @return array<string, Media>
     */
    public function known(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls, fn (string $url) => $url !== '')));

        if ($urls === [] || ! $this->tables->exists('media')) {
            return [];
        }

        $byHash = [];

        foreach ($urls as $url) {
            $byHash[Media::hashOf($url)] = $url;
        }

        $known = [];

        foreach (Media::query()->whereIn('source_url_hash', array_keys($byHash))->get() as $media) {
            $known[$byHash[$media->source_url_hash]] = $media;
        }

        return $known;
    }

    /**
     * Every `<img src>` an article body shows that could be copied: https,
     * not already on this site, each once, in order.
     *
     * @return list<string>
     */
    public function sources(string $html): array
    {
        if (stripos($html, '<img') === false) {
            return [];
        }

        $sources = [];

        foreach (Dom::fragment($html)->query('.//img[@src]') as $image) {
            $src = $image instanceof DOMElement ? trim($image->getAttribute('src')) : '';

            if ($this->copyable($src) && ! in_array($src, $sources, true)) {
                $sources[] = $src;
            }
        }

        return $sources;
    }

    /**
     * The body with each `<img src>` found in `$replacements` (original
     * address => copy's address) pointed at the copy. Bodies without any of
     * those addresses come back byte for byte.
     *
     * @param  array<string, string>  $replacements
     */
    public function rewrite(string $html, array $replacements): string
    {
        if ($replacements === [] || stripos($html, '<img') === false) {
            return $html;
        }

        $dom = Dom::fragment($html);
        $changed = false;

        foreach ($dom->query('.//img[@src]') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $src = trim($image->getAttribute('src'));

            if (isset($replacements[$src])) {
                $image->setAttribute('src', $replacements[$src]);
                $changed = true;
            }
        }

        return $changed ? $dom->html() : $html;
    }

    /**
     * The body with images that were copied before pointed at their copies.
     */
    public function rewriteKnown(string $html): string
    {
        if (! $this->enabled()) {
            return $html;
        }

        $replacements = array_map(fn (Media $media) => $media->url(), $this->known($this->sources($html)));

        return $this->rewrite($html, $replacements);
    }

    /** An address worth copying: https, and not a copy on this site already. */
    public function copyable(string $url): bool
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ownHost = strtolower((string) parse_url((string) $this->config->get('app.url'), PHP_URL_HOST));

        if ($host === '' || ($ownHost !== '' && $host === $ownHost)) {
            return false;
        }

        try {
            $diskUrl = $this->storage->disk($this->disk())->url('');
        } catch (Throwable) {
            return true;
        }

        return $diskUrl === '' || ! str_starts_with($url, $diskUrl);
    }

    /**
     * @return array{0: string, 1: string, 2: int|null, 3: int|null} bytes, content type, width, height
     *
     * @throws MediaRefused
     */
    private function download(string $url): array
    {
        $vetted = $this->guard->vet($url, requireHttps: true, verifyDns: (bool) $this->config->get('xerads.http.verify_public_dns', true));

        if ($vetted['reason'] !== null) {
            throw new MediaRefused("The image address {$url} was refused: {$vetted['reason']}");
        }

        $max = $this->maxBytes();

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(min(5, self::TIMEOUT_SECONDS))
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif'])
                ->withOptions($this->requestOptions($url, $vetted['addresses'], $max))
                ->get($url);
        } catch (Throwable $exception) {
            if ($this->tooLarge($exception)) {
                throw new MediaRefused("The image at {$url} is larger than {$this->megabytes($max)}.", 0, $exception);
            }

            throw new MediaRefused("The image at {$url} could not be downloaded: ".mb_substr($exception->getMessage(), 0, 200), 0, $exception);
        }

        if ($response->status() !== 200) {
            throw new MediaRefused("The image at {$url} answered {$response->status()}.");
        }

        $declared = $response->header('Content-Length');
        $bytes = $response->body();

        if (($declared !== '' && ctype_digit($declared) && (int) $declared > $max) || strlen($bytes) > $max) {
            throw new MediaRefused("The image at {$url} is larger than {$this->megabytes($max)}.");
        }

        $type = $this->typeOf($bytes);

        if ($type === null) {
            throw new MediaRefused("The file at {$url} is not a JPEG, PNG, WebP, GIF or AVIF image.");
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            throw new MediaRefused("The file at {$url} does not decode as an image.");
        }

        return [$bytes, $type, $size[0] > 0 ? $size[0] : null, $size[1] > 0 ? $size[1] : null];
    }

    /**
     * The request options of an image download: pinned to the address the
     * guard approved, and ended as soon as it is known to be too large.
     *
     * Never streamed. Guzzle sends a streamed request through its stream
     * handler, which ignores the cURL pin (Guzzle 7) or refuses it outright
     * (Guzzle 8). Guzzle buffers the body instead (in memory, then in a
     * temporary file), and the transfer is ended at the cap: at the headers
     * when Content-Length declares more, else by the progress callback once
     * more has arrived. Both work the same on Guzzle 7 and 8.
     *
     * @param  list<string>  $addresses  what UrlGuard::vet() approved
     * @return array<string, mixed>
     */
    public function requestOptions(string $url, array $addresses, int $maxBytes): array
    {
        $tooLarge = fn () => new MediaRefused('The image is larger than '.$this->megabytes($maxBytes).'.');

        return $this->guard->pinned($url, $addresses) + [
            'on_headers' => function (ResponseInterface $response) use ($maxBytes, $tooLarge): void {
                $declared = $response->getHeaderLine('Content-Length');

                if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
                    throw $tooLarge();
                }
            },
            'progress' => function (int|float $expected, int|float $received) use ($maxBytes, $tooLarge): void {
                if ($expected > $maxBytes || $received > $maxBytes) {
                    throw $tooLarge();
                }
            },
        ];
    }

    /** Was the download ended for its size, by the headers check or by cURL? */
    private function tooLarge(Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof MediaRefused) {
                return true;
            }
        }

        return false;
    }

    /**
     * The content type by the bytes themselves. AVIF is recognised by the
     * image decoder too, because older file-type databases do not know it.
     */
    private function typeOf(string $bytes): ?string
    {
        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $sniffed = is_string($sniffed) ? strtolower($sniffed) : '';

        if (isset(self::ALLOWED_TYPES[$sniffed])) {
            return $sniffed;
        }

        if (in_array($sniffed, ['application/octet-stream', ''], true)) {
            $size = @getimagesizefromstring($bytes);

            if ($size !== false && $size['mime'] === 'image/avif') {
                return 'image/avif';
            }
        }

        return null;
    }

    private function pathFor(string $url, string $type): string
    {
        $name = Str::slug(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
        $name = mb_substr($name !== '' ? $name : 'image', 0, 80);
        $directory = trim((string) $this->config->get('xerads.media.path', 'xerads'), '/');
        $now = Carbon::now();

        return ($directory !== '' ? $directory.'/' : '')
            .$now->format('Y').'/'.$now->format('m').'/'
            .substr(Media::hashOf($url), 0, 12).'-'.$name.'.'.self::ALLOWED_TYPES[$type];
    }

    private function disk(): string
    {
        $disk = $this->config->get('xerads.media.disk', 'public');

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    private function maxBytes(): int
    {
        return max(1, (int) $this->config->get('xerads.media.max_bytes', 10 * 1024 * 1024));
    }

    private function megabytes(int $bytes): string
    {
        if ($bytes < 1_048_576) {
            return (int) ceil($bytes / 1024).' KB';
        }

        return rtrim(rtrim(number_format($bytes / 1_048_576, 1), '0'), '.').' MB';
    }
}
