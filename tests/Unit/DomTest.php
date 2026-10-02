<?php

use XerAds\Laravel\Support\Html\Dom;

it('round-trips UTF-8 text and escaped entities unchanged', function () {
    $html = '<h2 id="apa-itu-kpr">Café — “kutip” &amp; &lt;tag&gt; 東京 😀</h2>'
        .'<p>Baca <a href="https://contoh.co.id/kpr?ref=x&amp;y=1">simulasi</a>&nbsp;sekarang.</p>';

    expect(Dom::fragment($html)->html())->toBe($html);
});

it('writes named entities back as the characters they stand for', function () {
    // Same text, different bytes: the parser decodes, the serialiser keeps
    // UTF-8 and escapes only what HTML requires.
    expect(Dom::fragment('<p>&copy; 2026 &mdash; &eacute;t&eacute;</p>')->html())
        ->toBe('<p>© 2026 — été</p>');
});

it('escapes a bare ampersand in an attribute', function () {
    expect(Dom::fragment('<a href="/kpr?ref=x&y=1">x</a>')->html())
        ->toBe('<a href="/kpr?ref=x&amp;y=1">x</a>');
});

it('keeps HTML5 elements and widget shortcodes intact', function () {
    $html = '<figure><img src="https://cdn.example.com/a.png" alt="Ä"><figcaption>Grafik</figcaption></figure>'
        .'<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>'
        .'<section><h3>Satu</h3></section>';

    expect(Dom::fragment($html)->html())->toBe($html);
});

it('exposes the top-level nodes for querying and editing', function () {
    $dom = Dom::fragment('<h2>Satu</h2><p>Teks</p><h2>Dua</h2>');

    expect($dom->query('//h2')->length)->toBe(2)
        ->and($dom->root()->childNodes->length)->toBe(3);

    $heading = $dom->query('//h2')->item(0);
    expect($heading)->toBeInstanceOf(DOMElement::class);

    /** @var DOMElement $heading */
    $heading->setAttribute('id', 'satu');

    expect($dom->html())->toBe('<h2 id="satu">Satu</h2><p>Teks</p><h2>Dua</h2>');
});

it('handles empty input and plain text', function () {
    expect(Dom::fragment('')->html())->toBe('')
        ->and(Dom::fragment('teks biasa')->html())->toBe('teks biasa');
});
