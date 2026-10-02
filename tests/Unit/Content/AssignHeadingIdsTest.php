<?php

/**
 * The heading-id rule, with the very cases XerAds' own suite asserts
 * (tests/Unit/Sites/ContractPrimitivesTest.php in the backend), so both sides
 * provably name sections the same way.
 */

use XerAds\Laravel\Content\HeadingIds;
use XerAds\Laravel\Content\Pipeline\AssignHeadingIds;

it('keeps a valid unique heading id and slugs the rest', function () {
    $ids = new HeadingIds(['intro']);

    expect($ids->assign('apa-itu-kpr', 'Apa itu KPR'))->toBe('apa-itu-kpr')
        ->and($ids->assign('Bad Id!', 'Syarat KPR 2026'))->toBe('syarat-kpr-2026')
        ->and($ids->assign(null, 'Apa itu KPR'))->toBe('apa-itu-kpr-2')
        ->and($ids->assign(null, 'Apa itu KPR'))->toBe('apa-itu-kpr-3')
        ->and($ids->assign(null, 'Intro'))->toBe('intro-2')
        ->and($ids->assign(null, '！？'))->toBe('section');
});

it('never makes a heading id longer than 80 characters', function () {
    $ids = new HeadingIds;
    $long = str_repeat('panjang ', 20);

    $first = $ids->assign(null, $long);
    $second = $ids->assign(null, $long);

    expect(strlen($first))->toBeLessThanOrEqual(80)
        ->and(strlen($second))->toBeLessThanOrEqual(80)
        ->and($second)->toEndWith('-2')
        ->and($first)->toMatch(HeadingIds::PATTERN);
});

it('assigns ids to h2 and h3 in document order, reserving other ids', function () {
    $html = '<p id="syarat">Anchor</p>'
        .'<h2 id="apa-itu-kpr">Apa itu KPR</h2>'
        .'<h3>Syarat</h3>'
        .'<h2 id="Bad Id">Apa itu KPR</h2>'
        .'<h3 id="apa-itu-kpr">Lagi</h3>'
        .'<h4>Tidak disentuh</h4>';

    expect(runSteps(AssignHeadingIds::class, $html)->html())->toBe(
        '<p id="syarat">Anchor</p>'
        .'<h2 id="apa-itu-kpr">Apa itu KPR</h2>'
        .'<h3 id="syarat-2">Syarat</h3>'
        .'<h2 id="apa-itu-kpr-2">Apa itu KPR</h2>'
        .'<h3 id="lagi">Lagi</h3>'
        .'<h4>Tidak disentuh</h4>'
    );
});

it('names a heading from its text as a reader sees it', function () {
    expect(runSteps(AssignHeadingIds::class, '<h2>  Bunga <strong>KPR</strong>&nbsp;2026 </h2>')->html())
        ->toBe('<h2 id="bunga-kpr-2026">  Bunga <strong>KPR</strong>&nbsp;2026 </h2>');
});
