<?php

use XerAds\Laravel\Widgets\Shortcode;
use XerAds\Laravel\Widgets\ShortcodeParser;

function parsedShortcodes(string $text): array
{
    return array_map(fn (Shortcode $shortcode) => [$shortcode->id, $shortcode->lang], (new ShortcodeParser)->parse($text));
}

it('reads the id and language whatever quotes the editor produced', function (string $text) {
    expect(parsedShortcodes($text))->toBe([[TEST_WIDGET_ID, 'id']]);
})->with([
    'double quotes' => ['[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]'],
    'single quotes' => ["[xerads_widget id='w_k3v9q2m8x1c4b7na' lang='id']"],
    'no quotes' => ['[xerads_widget id=w_k3v9q2m8x1c4b7na lang=id]'],
    'encoded quotes' => ['[xerads_widget id=&quot;w_k3v9q2m8x1c4b7na&quot; lang=&quot;id&quot;]'],
    'numeric entities' => ['[xerads_widget id&#61;&#34;w_k3v9q2m8x1c4b7na&#34; lang&#61;&#34;id&#34;]'],
    'curly double quotes' => ['[xerads_widget id=“w_k3v9q2m8x1c4b7na” lang=“id”]'],
    'curly single quotes' => ['[xerads_widget id=‘w_k3v9q2m8x1c4b7na’ lang=‘id’]'],
    'attributes reversed' => ['[xerads_widget lang="id" id="w_k3v9q2m8x1c4b7na"]'],
    'upper case name' => ['[XERADS_WIDGET id="w_k3v9q2m8x1c4b7na" lang="id"]'],
    'extra spaces' => ['[xerads_widget   id = "w_k3v9q2m8x1c4b7na"   lang = "id" ]'],
]);

it('refuses ids and languages that do not match the contract', function (string $text, ?string $id, ?string $lang) {
    expect(parsedShortcodes($text))->toBe([[$id, $lang]]);
})->with([
    'upper case id' => ['[xerads_widget id="W_K3V9Q2M8X1C4B7NA" lang="id"]', null, 'id'],
    'short id' => ['[xerads_widget id="w_k3v9" lang="id"]', null, 'id'],
    'no id' => ['[xerads_widget lang="id"]', null, 'id'],
    'region language' => ['[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="en-US"]', TEST_WIDGET_ID, 'en-US'],
    'bad language' => ['[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="indonesian"]', TEST_WIDGET_ID, null],
    'no language' => ['[xerads_widget id="w_k3v9q2m8x1c4b7na"]', TEST_WIDGET_ID, null],
]);

it('prints an escaped placeholder literally and never renders it', function () {
    $rendered = (new ShortcodeParser)->replace(
        'Tulis [[xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"]] di artikel.',
        fn () => 'RENDERED',
    );

    expect($rendered)->toBe('Tulis [xerads_widget id="w_k3v9q2m8x1c4b7na" lang="id"] di artikel.')
        ->and(parsedShortcodes('[[xerads_widget id="w_k3v9q2m8x1c4b7na"]]'))->toBe([]);
});

it('keeps a lone extra bracket as text', function () {
    $parser = new ShortcodeParser;

    expect($parser->replace('[[xerads_widget id="w_k3v9q2m8x1c4b7na"]', fn () => 'W'))->toBe('[W')
        ->and($parser->replace('[xerads_widget id="w_k3v9q2m8x1c4b7na"]]', fn () => 'W'))->toBe('W]');
});

it('finds every placeholder in order and leaves other text alone', function () {
    $text = 'A [xerads_widget id="w_aaaaaaaaaaaaaaaa"] B [xerads_widget id="w_bbbbbbbbbbbbbbbb" lang="en"] C [other_shortcode]';

    expect(parsedShortcodes($text))->toBe([['w_aaaaaaaaaaaaaaaa', null], ['w_bbbbbbbbbbbbbbbb', 'en']])
        ->and((new ShortcodeParser)->replace('No widgets here.', fn () => 'X'))->toBe('No widgets here.');
});

it('writes the canonical placeholder', function () {
    expect(ShortcodeParser::text(TEST_WIDGET_ID, 'id'))->toBe(widgetEmbeds()['embeds']['shortcode'])
        ->and(ShortcodeParser::text(TEST_WIDGET_ID))->toBe('[xerads_widget id="w_k3v9q2m8x1c4b7na"]');
});
