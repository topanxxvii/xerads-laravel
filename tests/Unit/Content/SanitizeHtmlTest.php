<?php

use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Pipeline\ContentPipeline;
use XerAds\Laravel\Content\Pipeline\SanitizeHtml;

function sanitized(string $html): string
{
    return runSteps(SanitizeHtml::class, $html)->html();
}

it('keeps the widget placeholder, character for character', function () {
    $html = '<p>[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]</p>';

    expect(sanitized($html))->toBe($html);
});

it('removes scripts, event handlers and script URLs', function () {
    $html = '<p onclick="steal()">Teks <script>alert(1)</script><a href="javascript:alert(1)">tautan</a></p>'
        .'<img src="https://cdn.example.com/a.png" onerror="alert(1)" alt="A">'
        .'<a href="  java	script:alert(1)">tab</a><style>p{}</style>';

    $clean = sanitized($html);

    expect($clean)->not->toContain('script')
        ->and($clean)->not->toContain('onclick')
        ->and($clean)->not->toContain('onerror')
        ->and($clean)->not->toContain('alert')
        ->and($clean)->not->toContain('<style')
        ->and($clean)->toContain('<img src="https://cdn.example.com/a.png" alt="A">')
        ->and($clean)->toContain('Teks');
});

it('does not truncate a long article', function () {
    $paragraph = '<p>'.str_repeat('Kalimat panjang tentang KPR dan cicilan rumah. ', 20).'</p>';
    $html = str_repeat($paragraph, (int) ceil(300_000 / strlen($paragraph))).'<p>AKHIR ARTIKEL</p>';

    expect(strlen($html))->toBeGreaterThan(300_000)
        ->and(sanitized($html))->toEndWith('<p>AKHIR ARTIKEL</p>')
        ->and(strlen(sanitized($html)))->toBe(strlen($html));
});

it('keeps the allowed article markup and its allowed attributes', function () {
    $html = '<h2 id="apa-itu-kpr">Apa itu KPR</h2><h3 id="syarat">Syarat</h3>'
        .'<ul><li><strong>a</strong> <em>b</em> <u>c</u> <mark>d</mark> <s>e</s> <sub>f</sub><sup>g</sup></li></ul>'
        .'<blockquote>kutipan</blockquote><pre><code>kode</code></pre>'
        .'<table><caption>Tabel</caption><thead><tr><th scope="col" colspan="2">H</th></tr></thead><tbody><tr><td rowspan="2">1</td></tr></tbody></table>'
        .'<figure><img src="https://cdn.example.com/a.png" alt="A" width="10" height="20" loading="lazy"><figcaption>Gambar</figcaption></figure>'
        .'<p><a href="/blog/dp-rumah" title="DP">DP</a> <a href="mailto:tim@example.com">surat</a> <a href="tel:+62211234">telepon</a><br></p><hr>';

    expect(sanitized($html))->toBe($html);
});

it('strips attributes outside the allowlist', function () {
    expect(sanitized('<p class="lead" style="color:red" id="x">Teks</p><h2 class="judul" id="a">A</h2>'))
        ->toBe('<p>Teks</p><h2 id="a">A</h2>');
});

it('unwraps layout elements instead of losing their text', function () {
    expect(sanitized('<section><article><p>Isi <small>kecil</small> <abbr title="Kredit">KPR</abbr></p></article></section>'))
        ->toBe('<p>Isi kecil KPR</p>');
});

it('drops iframes, forms and data URLs', function () {
    $clean = sanitized('<iframe src="https://video.example.com/1"></iframe><form><input name="q"></form><img src="data:image/png;base64,AAAA" alt="x"><p>Tetap</p>');

    expect($clean)->not->toContain('iframe')
        ->and($clean)->not->toContain('<form')
        ->and($clean)->not->toContain('data:')
        ->and($clean)->toContain('<p>Tetap</p>');
});

it('keeps div and span as structure, with no attribute at all', function () {
    // A container is only ever made after sanitising, from a checked id; one
    // that reaches the sanitiser is not trusted, whatever its id.
    expect(sanitized('<div data-xerads-widget="w_k3v9q2m8x1c4b7na" data-lang="id" class="x" style="min-height:10px"></div><span data-xerads-note="n" onclick="x()">t</span>'))
        ->toBe('<div></div><span>t</span>');
});

it('lets no unchecked container through, even past a comment the two parsers read differently', function () {
    $content = app(ContentPipeline::class)->process(ArticlePayload::fromArray([
        'xerads_id' => '01JA9Z5K7M3X8Q2W4E6R1T0Y9U',
        'content' => ['html' => '<p>x<!---> <div data-xerads-widget="../../evil">t</div> --></p>'],
    ]));

    expect($content->sourceHtml)->not->toContain('data-xerads-widget')
        ->and($content->compiledHtml)->not->toContain('evil"')
        ->and($content->compiledHtml)->not->toContain('data-xerads-widget');
});

it('protects a link opened in any new browsing context, not only _blank', function () {
    expect(sanitized('<a href="https://example.com" target="x">a</a><a href="https://example.com" target="_self">b</a><a href="https://example.com" target="_TOP">c</a>'))
        ->toBe('<a href="https://example.com" target="x" rel="noopener">a</a><a href="https://example.com" target="_self">b</a><a href="https://example.com" target="_TOP">c</a>');
});

it('protects a link that opens a new window', function () {
    expect(sanitized('<a href="https://example.com" target="_blank">a</a><a href="https://example.com" target="_blank" rel="nofollow">b</a>'))
        ->toBe('<a href="https://example.com" target="_blank" rel="noopener">a</a><a href="https://example.com" target="_blank" rel="nofollow noopener">b</a>');
});

it('keeps UTF-8 text as UTF-8', function () {
    expect(sanitized('<p>Café — “kutip” &amp; 東京 😀 = + @</p>'))->toBe('<p>Café — “kutip” &amp; 東京 😀 = + @</p>');
});

it('extends the allowlist from config', function () {
    config(['xerads.sanitizer.allow_elements' => ['abbr' => ['title'], 'kbd']]);

    expect(sanitized('<p><abbr title="Kredit Pemilikan Rumah">KPR</abbr> <kbd>Ctrl</kbd></p>'))
        ->toBe('<p><abbr title="Kredit Pemilikan Rumah">KPR</abbr> <kbd>Ctrl</kbd></p>');
});
