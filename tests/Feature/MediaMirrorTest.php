<?php

/**
 * Copying article images onto the site's disk: what is fetched, what is
 * refused, and how stored articles come to point at the copies.
 */

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Workbench\App\Models\Post;
use XerAds\Laravel\Content\Media\Jobs\MirrorArticleMedia;
use XerAds\Laravel\Content\Media\MediaMirror;
use XerAds\Laravel\Content\Media\MediaRefused;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Media;

/** A 3×2 PNG and a 1×1 GIF, small enough to read. */
const PNG_3X2 = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgwAQAABQAAWX1h1kAAAAASUVORK5CYII=';
const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const FEATURED_URL = 'https://cdn.xerads.test/articles/panduan-kpr-2026-1.png';
const BODY_IMAGE_URL = 'https://api.xerads.test/storage/articles/kpr-body.png';

function png(): string
{
    return (string) base64_decode(PNG_3X2);
}

/** The disk the copies go to, with a URL, as `public` has on a real site. */
function fakeMediaDisk(): void
{
    Storage::fake('public', ['url' => 'http://localhost/storage']);
}

/**
 * Answer every image address with a PNG, unless told otherwise. Each answer
 * is made fresh: a response's body is a stream, read once.
 *
 * @param  array<string, Closure>  $responses
 */
function fakeImages(array $responses = []): void
{
    Http::fake($responses + ['*' => fn () => Http::response(png(), 200, ['Content-Type' => 'image/png'])]);
}

function mirror(): MediaMirror
{
    app()->forgetScopedInstances();

    return app(MediaMirror::class);
}

describe('the copy', function () {
    beforeEach(function () {
        config(['xerads.media.mirror' => true]);
        fakeMediaDisk();
    });

    it('stores an image under a dated, hashed name and remembers where it came from', function () {
        fakeImages();

        $media = mirror()->mirror(FEATURED_URL, 'Rumah');
        $expected = 'xerads/'.now()->format('Y/m').'/'.substr(sha1(FEATURED_URL), 0, 12).'-panduan-kpr-2026-1.png';

        expect($media->path)->toBe($expected)
            ->and($media->mime)->toBe('image/png')
            ->and([$media->width, $media->height])->toBe([3, 2])
            ->and($media->bytes)->toBe(strlen(png()))
            ->and($media->alt)->toBe('Rumah')
            ->and($media->url())->toBe('http://localhost/storage/'.$expected);

        Storage::disk('public')->assertExists($expected);
    });

    it('names the file by its content, never by its address', function () {
        fakeImages(['*' => fn () => Http::response((string) base64_decode(GIF_1X1), 200, ['Content-Type' => 'image/png'])]);

        expect(mirror()->mirror('https://cdn.xerads.test/a/shell.php')->path)->toEndWith('-shell.gif');
    });

    it('downloads each address once', function () {
        fakeImages();

        $first = mirror()->mirror(FEATURED_URL);
        $second = mirror()->mirror(FEATURED_URL);

        expect($second->id)->toBe($first->id)
            ->and(Media::query()->count())->toBe(1);

        Http::assertSentCount(1);

        // Gone from the disk (a wiped volume): copied again.
        Storage::disk('public')->delete($first->path);
        mirror()->mirror(FEATURED_URL);

        Http::assertSentCount(2);
        expect(Media::query()->count())->toBe(1);
    });

    it('refuses a file larger than the cap', function (array $headers) {
        config(['xerads.media.max_bytes' => 64]);
        fakeImages(['*' => fn () => Http::response(png().str_repeat("\0", 100), 200, $headers)]);

        expect(fn () => mirror()->mirror(FEATURED_URL))->toThrow(MediaRefused::class, 'larger than');
        expect(Media::query()->count())->toBe(0);
    })->with([
        'said so in Content-Length' => [['Content-Length' => '5000000']],
        'found out while reading' => [[]],
    ]);

    it('refuses what is not an allowed image, whatever it is called', function (string $bytes) {
        fakeImages(['*' => fn () => Http::response($bytes, 200, ['Content-Type' => 'image/png'])]);

        expect(fn () => mirror()->mirror(FEATURED_URL))->toThrow(MediaRefused::class);
        expect(Media::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'an SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
        'an HTML error page' => ['<!doctype html><html><body>Not found</body></html>'],
        'a PNG that does not decode' => ["\x89PNG\r\n\x1a\n".str_repeat('x', 40)],
    ]);

    it('refuses private, internal and plain-http addresses without asking them', function (string $url) {
        fakeImages();

        expect(fn () => mirror()->mirror($url))->toThrow(MediaRefused::class, 'was refused');

        Http::assertNothingSent();
    })->with([
        'a private address' => ['https://10.0.0.5/image.png'],
        'the metadata address' => ['https://169.254.169.254/latest/meta-data'],
        'an internal name' => ['https://images.internal/a.png'],
        'plain http' => ['http://cdn.xerads.test/a.png'],
    ]);

    it('refuses what does not answer 200, including a redirect it will not follow', function (int $status) {
        fakeImages(['*' => fn () => Http::response('', $status, ['Location' => 'https://10.0.0.5/a.png'])]);

        expect(fn () => mirror()->mirror(FEATURED_URL))->toThrow(MediaRefused::class, "answered {$status}");
        Http::assertSentCount(1);
    })->with([302, 404, 503]);
});

describe('turnkey articles', function () {
    beforeEach(function () {
        $this->bootTurnkey(['xerads.credentials.key' => testSiteKey(), 'xerads.media.mirror' => true]);
        fakeMediaDisk();
    });

    it('point the featured image and the body at the copies once they are made', function () {
        fakeImages();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        $article = Article::sole();
        $featured = Media::query()->where('source_url', FEATURED_URL)->sole();
        $body = Media::query()->where('source_url', BODY_IMAGE_URL)->sole();

        expect($article->featured_image_url)->toBe($featured->url())
            ->and($article->featured_image_path)->toBe($featured->path)
            ->and($article->body_source)->toContain('<img src="'.$body->url().'"')
            ->and($article->body_html)->toContain('<img src="'.$body->url().'"')
            ->and($article->body_source)->not->toContain(BODY_IMAGE_URL)
            // So will share previews.
            ->and($article->xeradsSeo()?->extra('image.url'))->toBe($featured->url());

        $page = (string) $this->get('/blog/panduan-kpr-2026')->assertOk()->getContent();

        expect($page)->toContain($featured->url())
            ->and($page)->toContain($body->url())
            ->and($page)->not->toContain('xerads.test');
    });

    it('show XerAds\' address until the copy is made', function () {
        fakeImages();
        Bus::fake();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        Bus::assertDispatchedAfterResponse(MirrorArticleMedia::class, fn (MirrorArticleMedia $job) => $job->xeradsId === upsertFixture()['data']['article']['xerads_id']);

        $before = (string) $this->get('/blog/panduan-kpr-2026')->getContent();

        expect($before)->toContain(FEATURED_URL)->and($before)->toContain(BODY_IMAGE_URL);

        app()->call([new MirrorArticleMedia(Article::sole()->xerads_id), 'handle']);

        $after = (string) $this->get('/blog/panduan-kpr-2026')->getContent();

        expect($after)->not->toContain(FEATURED_URL)
            ->and($after)->not->toContain(BODY_IMAGE_URL)
            ->and($after)->toContain('http://localhost/storage/xerads/');
    });

    it('keep XerAds\' address when the copy fails', function () {
        fakeImages(['*' => fn () => Http::response('', 503)]);
        $warnings = $this->captureWarnings();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        expect(Article::sole()->featured_image_url)->toBe(FEATURED_URL)
            ->and(Article::sole()->body_source)->toContain(BODY_IMAGE_URL)
            ->and((array) $warnings)->toContain('XerAds could not copy an image to this site; the page keeps showing the original address.');
    });

    it('show copies made before at once, when an edit keeps its images', function () {
        fakeImages();
        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]));

        // The next revision arrives with XerAds' addresses again; no copy runs.
        Bus::fake();
        deliver($this, upsertEnvelope(['revision' => 'sha256:b', 'title' => 'Diperbarui'], ['delivery_id' => (string) Str::ulid(), 'sequence' => 1843]))->assertOk();

        $article = Article::sole();

        expect($article->title)->toBe('Diperbarui')
            ->and($article->featured_image_url)->toStartWith('http://localhost/storage/xerads/')
            ->and($article->body_source)->not->toContain(BODY_IMAGE_URL);

        Http::assertSentCount(2);
    });

    it('queue the copy where there is a worker, on the configured queue', function () {
        config([
            'queue.connections.images' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default'],
            'xerads.queue.connection' => 'images',
            'xerads.queue.queue' => 'media',
        ]);
        fakeImages();
        Queue::fake();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        Queue::assertPushedOn('media', MirrorArticleMedia::class, fn (MirrorArticleMedia $job) => $job->connection === 'images' && $job->afterCommit === true);

        // Nothing was downloaded by the web request itself.
        Http::assertNothingSent();
        expect(Article::sole()->featured_image_url)->toBe(FEATURED_URL);
    });

    it('copy nothing with mirroring turned off', function () {
        config(['xerads.media.mirror' => false]);
        fakeImages();
        Bus::fake();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        Bus::assertNotDispatchedAfterResponse(MirrorArticleMedia::class);
        expect(Article::sole()->featured_image_url)->toBe(FEATURED_URL);
    });
});

describe('mapped articles', function () {
    beforeEach(function () {
        config(['xerads.credentials.key' => testSiteKey(), 'xerads.media.mirror' => true]);
        fakeMediaDisk();
    });

    it('point the mapped image and content columns at the copies', function () {
        fakeImages();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        $post = Post::sole();
        $featured = Media::query()->where('source_url', FEATURED_URL)->sole();
        $body = Media::query()->where('source_url', BODY_IMAGE_URL)->sole();

        expect($post->image_url)->toBe($featured->url())
            ->and($post->content)->toContain('<img src="'.$body->url().'"')
            ->and($post->content)->not->toContain(BODY_IMAGE_URL);

        Http::assertSent(fn (Request $request) => $request->url() === FEATURED_URL);
    });

    it('show copies made before at once, when an edit keeps its images', function () {
        fakeImages();
        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]));

        Bus::fake();
        deliver($this, upsertEnvelope(['revision' => 'sha256:b'], ['delivery_id' => (string) Str::ulid(), 'sequence' => 1843]))->assertOk();

        expect(Post::sole()->image_url)->toStartWith('http://localhost/storage/xerads/')
            ->and(Post::sole()->content)->not->toContain(BODY_IMAGE_URL);
    });

    it('leave a column the site does not map alone', function () {
        config(['xerads.content.mapped.fields.image_url' => null]);
        fakeImages();

        deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]))->assertCreated();

        expect(Post::sole()->image_url)->toBeNull()
            ->and(Post::sole()->content)->not->toContain(BODY_IMAGE_URL);

        Http::assertNotSent(fn (Request $request) => $request->url() === FEATURED_URL);
    });
});
