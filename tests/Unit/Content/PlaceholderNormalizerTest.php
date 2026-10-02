<?php

use XerAds\Laravel\Content\Pipeline\NormalizeWidgetPlaceholders;

/**
 * Every embed snippet the dashboard hands out becomes the one placeholder
 * this package stores. The snippets come from widget-embeds.json, the same
 * file XerAds generates them from.
 */
it('turns every dashboard embed form into the placeholder', function (string $form) {
    $html = '<p>Sebelum</p>'.widgetEmbeds()['embeds'][$form].'<p>Sesudah</p>';

    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())
        ->toBe('<p>Sebelum</p>'.widgetEmbeds()['embeds']['shortcode'].($form === 'script' ? "\n" : '').'<p>Sesudah</p>');
})->with(['script', 'iframe', 'shortcode', 'block', 'blade']);

it('matches any runtime version, not only v1', function () {
    $html = '<iframe src="https://widgets.xerads.id/v12/frame.html?w=w_k3v9q2m8x1c4b7na&amp;lang=en"></iframe>'
        .'<script src="https://cdn.example.com/v7/loader.js" async></script>';

    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())
        ->toBe('[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="en"]');
});

it('keeps a placeholder wrapped in its paragraph and nested containers in place', function () {
    $html = '<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>'
        .'<section><div data-xerads-widget="w_bbbbbbbbbbbbbbbb"></div></section>';

    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())
        ->toBe('<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p><section>[xerads_widget id="w_bbbbbbbbbbbbbbbb"]</section>');
});

it('drops placeholders whose id the runtime could not load', function () {
    $html = '<div data-xerads-widget="w_bad"></div><!-- wp:xerads/widget {"id":"nope"} /--><iframe src="https://widgets.xerads.id/v1/frame.html?lang=id"></iframe><p>Teks</p>';

    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())->toBe('<p>Teks</p>');
});

it('leaves other iframes and scripts for the sanitiser', function () {
    $html = '<iframe src="https://video.example.com/embed/1"></iframe><script src="/js/app.js"></script>';

    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())->toBe($html);
});

it('removes the closing comment of a paired block', function () {
    $html = '<!-- wp:xerads/widget {"id":"w_k3v9q2m8x1c4b7na","lang":"id"} --><!-- /wp:xerads/widget -->';

    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())->toBe('[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]');
});

it('never empties a body the pattern engine cannot finish', function () {
    $tail = str_repeat('<p>'.str_repeat('isi artikel ', 40).'</p>', 2500).'<p>AKHIR</p>';
    $html = '<p>Awal</p><!-- wp:xerads/widget {'.$tail;

    expect(strlen($html))->toBeGreaterThan(1_000_000);

    $normalised = runSteps(NormalizeWidgetPlaceholders::class, $html)->html();

    expect($normalised)->toStartWith('<p>Awal</p>')
        ->and($normalised)->toContain('<p>AKHIR</p>');
});

it('does not reach from a broken block comment to a later valid one', function () {
    $html = '<!-- wp:xerads/widget {"id": <p>Penting</p> --><p>Tengah</p><!-- wp:xerads/widget {"id":"w_k3v9q2m8x1c4b7na"} /-->';

    // The broken comment stays a comment (the sanitiser drops it later);
    // the paragraph after it is not swallowed on the way to the valid one.
    expect(runSteps(NormalizeWidgetPlaceholders::class, $html)->html())
        ->toBe('<!-- wp:xerads/widget {"id": <p>Penting</p> --><p>Tengah</p>[xerads_widget id="w_k3v9q2m8x1c4b7na"]');
});
