<?php

use Illuminate\Support\Facades\Http;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Pipeline\CompileWidgets;
use XerAds\Laravel\Content\Pipeline\ComputeStats;
use XerAds\Laravel\Content\Pipeline\ContentPipeline;
use XerAds\Laravel\Content\Pipeline\DemoteHeadings;
use XerAds\Laravel\Content\Receivers\MappedModel;

beforeEach(function () {
    config(['xerads.widgets.fetch_documents' => false]);
});

function compiled(string $html, array $heights = [TEST_WIDGET_ID => 2320], ?string $language = 'id'): string
{
    return (string) runSteps(CompileWidgets::class, $html, $language, $heights)->compiledHtml;
}

it('replaces a paragraph holding only a placeholder with the contract container', function () {
    expect(compiled('<p>Sebelum</p><p> [xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"] </p><p>Sesudah</p>'))
        ->toBe('<p>Sebelum</p>'.scriptContainer().'<p>Sesudah</p>');
});

it('places a container inline when the paragraph has other text', function () {
    expect(compiled('<p>Coba: [xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"] sekarang.</p>'))
        ->toBe('<p>Coba: '.scriptContainer().' sekarang.</p>');
});

it('leaves placeholders in pre and code as text', function () {
    $html = '<pre>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</pre><p><code>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</code></p>';

    expect(compiled($html))->toBe($html);
});

it('prints an escaped placeholder literally', function () {
    expect(compiled('<p>Tulis [[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]] di artikel.</p>'))
        ->toBe('<p>Tulis [xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"] di artikel.</p>');
});

it('renders nothing for an invalid id', function () {
    expect(compiled('<p>[xerads_widget id="w_nope"]</p><p>Teks [xerads_widget id="x"] tetap.</p>'))
        ->toBe('<p>Teks  tetap.</p>');
});

it('takes the language from the article when the placeholder has none, and the fallback height when nothing is known', function () {
    expect(compiled('<p>[xerads_widget id="w_aaaaaaaaaaaaaaaa"]</p>', [], 'en'))
        ->toBe('<div data-xerads-widget="w_aaaaaaaaaaaaaaaa" data-lang="en" style="min-height:1000px"></div>');
});

it('reads the height from the widget document when XerAds did not send it', function () {
    config(['xerads.widgets.fetch_documents' => true]);

    Http::fake(['https://widgets.xerads.id/w/w_aaaaaaaaaaaaaaaa.json' => Http::response([
        'id' => 'w_aaaaaaaaaaaaaaaa',
        // Inactive: the height is still read; whether it shows is the runtime's call.
        'status' => 'inactive',
        'layout' => ['min_height' => ['mobile' => 640, 'desktop' => 480]],
    ])]);

    expect(compiled('<p>[xerads_widget id="w_aaaaaaaaaaaaaaaa" lang="id"]</p>', []))
        ->toBe('<div data-xerads-widget="w_aaaaaaaaaaaaaaaa" data-lang="id" style="min-height:640px"></div>')
        // Cached: a second page asks nobody.
        ->and(compiled('<p>[xerads_widget id="w_aaaaaaaaaaaaaaaa" lang="id"]</p>', []))->toContain('min-height:640px');

    Http::assertSentCount(1);
});

it('keeps the placeholder body for sites that expand at render time', function () {
    $document = runSteps(CompileWidgets::class, '<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>', 'id', [TEST_WIDGET_ID => 2320]);

    expect($document->html())->toBe('<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>')
        ->and($document->compiledHtml)->toBe(scriptContainer());
});

it('demotes every h1', function () {
    expect(runSteps(DemoteHeadings::class, '<h1 id="a">Satu</h1><p>x</p><h1>Dua</h1>')->html())
        ->toBe('<h2 id="a">Satu</h2><p>x</p><h2>Dua</h2>');
});

it('counts words in any script, reads at 225 a minute, and finds an excerpt', function () {
    $words = str_repeat('kata ', 450).'東京 café';
    $document = runSteps(ComputeStats::class, '<p>'.$words.'</p><p>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</p>', excerpt: null);

    expect($document->wordCount)->toBe(452)
        ->and($document->readingTimeMinutes)->toBe(3)
        ->and($document->excerpt)->toEndWith('…')
        ->and(mb_strlen((string) $document->excerpt))->toBeLessThanOrEqual(161);

    expect(runSteps(ComputeStats::class, '<p>Pendek.</p>', excerpt: 'Dari XerAds.')->excerpt)->toBe('Dari XerAds.');
});

it('runs the whole pipeline over the contract fixture', function () {
    $content = app(ContentPipeline::class)->process(ArticlePayload::fromArray(upsertFixture()['data']['article']));

    expect($content->sourceHtml)->toContain('<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>')
        ->and($content->sourceHtml)->toContain('<h2 id="apa-itu-kpr">Apa itu KPR</h2>')
        ->and($content->sourceHtml)->toContain('<a href="/blog/dp-rumah">panduan DP</a>')
        ->and($content->sourceHtml)->toContain('<figure><img src="https://api.xerads.test/storage/articles/kpr-body.png" alt="Ilustrasi KPR"></figure>')
        ->and($content->compiledHtml)->toContain(scriptContainer())
        ->and($content->compiledHtml)->not->toContain('xerads_widget')
        ->and($content->toc)->toBe(upsertFixture()['data']['article']['toc'])
        ->and($content->wordCount)->toBe(17)
        ->and($content->excerpt)->toBe('Syarat, bunga dan simulasi KPR 2026.');
});

it('names the column from each database\'s error message', function (string $message, string $column) {
    expect(MappedModel::columnFrom($message))->toBe($column);
})->with([
    'SQLite' => ['SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: posts.user_id', 'user_id'],
    'MySQL default' => ["SQLSTATE[HY000]: General error: 1364 Field 'user_id' doesn't have a default value", 'user_id'],
    'MySQL null' => ["SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'user_id' cannot be null", 'user_id'],
    'MySQL unknown' => ["SQLSTATE[42S22]: Column not found: 1054 Unknown column 'summary' in 'field list'", 'summary'],
    'PostgreSQL null' => ['SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "user_id" of relation "posts" violates not-null constraint', 'user_id'],
    'PostgreSQL unknown' => ['SQLSTATE[42703]: Undefined column: 7 ERROR:  column "summary" of relation "posts" does not exist', 'summary'],
    'SQLite unknown' => ['SQLSTATE[HY000]: General error: 1 table posts has no column named summary', 'summary'],
]);

it('keeps private-use characters as text next to a placeholder', function () {
    $glyphs = 'icon 3 lagi 0';

    expect(compiled('<p>'.$glyphs.' [xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>'))
        ->toBe('<p>'.$glyphs.' '.scriptContainer().'</p>');
});

it('removes placeholders instead of placing empty containers with widgets off', function () {
    config(['xerads.modules.widgets' => false, 'xerads.widgets.fetch_documents' => true]);
    Http::fake();

    expect(compiled('<p>Sebelum</p><p>[xerads_widget id="w_aaaaaaaaaaaaaaaa"]</p><p>Teks [xerads_widget id="w_bbbbbbbbbbbbbbbb"] tetap.</p>', []))
        ->toBe('<p>Sebelum</p><p>Teks  tetap.</p>');

    Http::assertNothingSent();
});
