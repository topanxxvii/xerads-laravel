<?php

use XerAds\Laravel\Seo\TitleTemplate;

it('fills the tokens XerAds allows', function (string $template, array $values, string $expected) {
    expect(TitleTemplate::render($template, '-', $values))->toBe($expected);
})->with([
    'article' => ['{title} {sep} {site}', ['title' => 'Panduan KPR', 'site' => 'Toko'], 'Panduan KPR - Toko'],
    'home' => ['{site} {sep} {tagline}', ['site' => 'Toko', 'tagline' => 'Rumah impian'], 'Toko - Rumah impian'],
    'term' => ['{term} {sep} {site}', ['term' => 'Keuangan', 'site' => 'Toko'], 'Keuangan - Toko'],
    'search' => ['{query} {sep} {site}', ['query' => 'kpr', 'site' => 'Toko'], 'kpr - Toko'],
    'page' => ['{title} {sep} {page}', ['title' => 'Blog', 'page' => 'Halaman 2'], 'Blog - Halaman 2'],
    'literal text' => ['Blog {sep} {site}', ['site' => 'Toko'], 'Blog - Toko'],
]);

it('leaves an unknown token as literal text', function () {
    expect(TitleTemplate::render('{title} {author} {sep} {site}', '-', ['title' => 'A', 'site' => 'B']))->toBe('A {author} - B');
});

it('drops a separator an empty token leaves dangling', function (string $template, array $values, string $expected) {
    expect(TitleTemplate::render($template, '|', $values))->toBe($expected);
})->with([
    'at the end' => ['{site} {sep} {tagline}', ['site' => 'Toko', 'tagline' => ''], 'Toko'],
    'at the start' => ['{tagline} {sep} {site}', ['site' => 'Toko'], 'Toko'],
    'twice in a row' => ['{title} {sep} {term} {sep} {site}', ['title' => 'A', 'site' => 'B'], 'A | B'],
    'all empty' => ['{sep} {title} {sep}', [], ''],
]);

it('uses the separator as text, and keeps one typed into the template', function () {
    expect(TitleTemplate::render('{title} {sep} {site}', '·', ['title' => 'A', 'site' => 'B']))->toBe('A · B')
        ->and(TitleTemplate::render('{title} - {site}', '|', ['title' => 'A', 'site' => 'B']))->toBe('A - B');
});

it('treats values as plain text', function () {
    expect(TitleTemplate::render('{title} {sep} {site}', '-', ['title' => "  Banyak\n spasi  ", 'site' => '<b>Toko</b>']))
        ->toBe('Banyak spasi - <b>Toko</b>');
});
