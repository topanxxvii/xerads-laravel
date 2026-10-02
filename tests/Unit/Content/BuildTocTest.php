<?php

use XerAds\Laravel\Content\Pipeline\AssignHeadingIds;
use XerAds\Laravel\Content\Pipeline\BuildToc;

it('lists every h2 and h3 with its id, text and level', function () {
    $document = runSteps([AssignHeadingIds::class, BuildToc::class], '<h2>Apa itu KPR</h2><p>x</p><h3 id="syarat">Syarat <em>utama</em></h3><h4>Bukan</h4><h2>Simulasi</h2>');

    expect($document->toc)->toBe([
        ['id' => 'apa-itu-kpr', 'text' => 'Apa itu KPR', 'level' => 2],
        ['id' => 'syarat', 'text' => 'Syarat utama', 'level' => 3],
        ['id' => 'simulasi', 'text' => 'Simulasi', 'level' => 2],
    ]);
});

it('is empty for an article without sections', function () {
    expect(runSteps(BuildToc::class, '<p>Pendek.</p>')->toc)->toBe([]);
});
