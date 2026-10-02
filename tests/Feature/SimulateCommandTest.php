<?php

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Models\Post;
use XerAds\Laravel\Content\Models\ContentMapEntry;

beforeEach(function () {
    config(['xerads.credentials.key' => testSiteKey()]);
});

/** @return array{0: int, 1: string} */
function simulate(array $options = []): array
{
    $code = Artisan::call('xerads:simulate', $options);

    return [$code, Artisan::output()];
}

it('signs the sample article and stores it through the real webhook', function () {
    [$code, $output] = simulate();

    expect($code)->toBe(0)
        ->and($output)->toContain('article.upsert → HTTP 201')
        ->and($output)->toContain('"state": "published"')
        ->and(Post::sole()->title)->toBe('Panduan KPR 2026');
});

it('sends the next sequence each time, so a second run updates', function () {
    simulate();
    [$code, $output] = simulate(['--status' => 'draft']);

    expect($code)->toBe(0)
        ->and($output)->toContain('HTTP 200')
        ->and($output)->toContain('"state": "draft"')
        ->and(Post::count())->toBe(1)
        ->and(ContentMapEntry::sole()->sequence)->toBe(2);
});

it('sends the other events', function (string $event, string $expected) {
    simulate();

    [$code, $output] = simulate(['--event' => $event]);

    expect($code)->toBe(0)->and($output)->toContain($expected);
})->with([
    'ping' => ['ping', '"plugin": "xerads"'],
    'unpublish' => ['article.unpublish', '"state": "unpublished"'],
    'delete' => ['article.delete', '"state": "unpublished"'],
    'settings' => ['settings.updated', '"version"'],
]);

it('reads an article from a fixture file', function () {
    $fixture = scratchDirectory('simulate').'/article.json';
    file_put_contents($fixture, json_encode(array_merge(upsertFixture()['data']['article'], [
        'xerads_id' => '01JBZZZZZZZZZZZZZZZZZZZZZZ',
        'title' => 'Dari berkas',
        'slug' => 'dari-berkas',
        'remote_id' => null,
    ])));

    [$code] = simulate(['--fixture' => $fixture]);

    expect($code)->toBe(0)->and(Post::sole()->title)->toBe('Dari berkas');
});

it('refuses production unless forced', function () {
    app()->detectEnvironment(fn () => 'production');

    [$code, $output] = simulate();

    expect($code)->toBe(1)
        ->and($output)->toContain('Refusing to run in production')
        ->and(Post::count())->toBe(0);

    [$forced] = simulate(['--force' => true]);

    expect($forced)->toBe(0)->and(Post::count())->toBe(1);
});

it('needs a site key to sign with', function () {
    config(['xerads.credentials.key' => null]);

    [$code, $output] = simulate();

    expect($code)->toBe(1)->and($output)->toContain('no site key');
});

it('refuses an unknown event', function () {
    [$code] = simulate(['--event' => 'article.archive']);

    expect($code)->toBe(2);
});

it('leaves an article XerAds delivered for real alone, unless forced, and never holds up the next real delivery', function () {
    // A real delivery: sequence 1842.
    deliver($this, upsertEnvelope())->assertCreated();

    [$refused, $output] = simulate(['--status' => 'draft']);

    expect($refused)->toBe(1)
        ->and($output)->toContain('XerAds manages this article')
        ->and(Post::sole()->status)->toBe('published');

    [$forced] = simulate(['--status' => 'draft', '--force' => true]);

    expect($forced)->toBe(0)
        ->and(Post::sole()->status)->toBe('draft')
        ->and(ContentMapEntry::sole()->wasSimulated())->toBeTrue();

    // XerAds' next event for it carries the very sequence the simulation
    // used; it is applied, not dropped as stale.
    deliver($this, upsertEnvelope(['revision' => 'sha256:real'], ['delivery_id' => '01JB2M8Q4Z7X3K9V5T1R6W1843', 'sequence' => 1843]))
        ->assertOk()
        ->assertJson(['state' => 'published'])
        ->assertJsonMissingPath('stale');

    expect(Post::sole()->status)->toBe('published');
});
