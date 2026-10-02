<?php

use Illuminate\Config\Repository;
use XerAds\Laravel\Compat\LegacyConfig;

/** A config repository holding the package defaults, plus whatever the app adds. */
function configWithDefaults(array $app = []): Repository
{
    return new Repository(array_merge(['xerads' => require __DIR__.'/../../config/xerads.php'], $app));
}

afterEach(function () {
    putenv('XERADS_CMS_SECRET');
    putenv('XERADS_CONTENT_MODE');
});

it('copies a published legacy config file onto the new keys and selects mapped mode', function () {
    $config = configWithDefaults(['xerads-cms' => [
        'secret' => 'from-legacy-file',
        'route' => '/hooks/xerads',
        'middleware' => ['api', 'throttle:10,1'],
        'timestamp_tolerance' => 600,
        'model' => 'App\Models\Article',
        'fields' => ['title' => 'judul', 'content' => 'isi', 'meta_description' => null],
        'status_map' => ['draft' => 0, 'publish' => 1],
        'public_route' => 'articles.show',
        'public_route_parameter' => 'article',
    ]]);
    $config->set('xerads.content.mode', 'turnkey');

    LegacyConfig::apply($config);

    expect($config->get('xerads.legacy.secret'))->toBe('from-legacy-file')
        ->and($config->get('xerads.legacy.route'))->toBe('/hooks/xerads')
        ->and($config->get('xerads.legacy.middleware'))->toBe(['api', 'throttle:10,1'])
        ->and($config->get('xerads.legacy.timestamp_tolerance'))->toBe(600)
        ->and($config->get('xerads.content.mapped.model'))->toBe('App\Models\Article')
        ->and($config->get('xerads.content.mapped.status_map'))->toBe(['draft' => 0, 'publish' => 1])
        ->and($config->get('xerads.content.mapped.public_route'))->toBe('articles.show')
        ->and($config->get('xerads.content.mapped.public_route_parameter'))->toBe('article')
        ->and($config->get('xerads.content.mode'))->toBe('mapped');

    // The legacy map is the complete map: every field it leaves out stays
    // unwritten, as it was under the original receiver.
    expect($config->get('xerads.content.mapped.fields'))->toBe([
        'title' => 'judul',
        'seo_title' => null,
        'content' => 'isi',
        'slug' => null,
        'meta_description' => null,
        'image_url' => null,
        'status' => null,
        'keywords' => null,
        'excerpt' => null,
    ]);
});

it('mirrors the effective values under xerads-cms when no legacy file exists', function () {
    $config = configWithDefaults();
    $config->set('xerads.legacy.secret', 'from-the-environment');
    $config->set('xerads.content.mapped.model', 'App\Models\Article');
    $config->set('xerads.content.mode', 'turnkey');

    LegacyConfig::mirror($config);

    expect($config->get('xerads-cms.secret'))->toBe('from-the-environment')
        ->and($config->get('xerads-cms.route'))->toBe('/api/xerads/articles')
        ->and($config->get('xerads-cms.model'))->toBe('App\Models\Article')
        ->and($config->get('xerads-cms.fields.title'))->toBe('title')
        ->and($config->get('xerads-cms.status_map'))->toBe(['draft' => 'draft', 'publish' => 'published']);

    // Cached and booted again, the mirror is not mistaken for a published
    // file: the site keeps the mode it chose.
    LegacyConfig::apply($config, readEnvironment: false);

    expect($config->get('xerads.content.mode'))->toBe('turnkey');
});

it('leaves a published legacy file as it is', function () {
    $config = configWithDefaults(['xerads-cms' => ['secret' => 'from-legacy-file']]);

    LegacyConfig::apply($config);
    LegacyConfig::mirror($config);

    expect($config->get('xerads-cms'))->toBe(['secret' => 'from-legacy-file']);
});

it('selects mapped mode when a legacy variable is set', function () {
    putenv('XERADS_CMS_SECRET=from-the-environment');

    $config = configWithDefaults();
    $config->set('xerads.content.mode', 'off');

    LegacyConfig::apply($config);

    expect($config->get('xerads.content.mode'))->toBe('mapped');
});

it('ignores the environment once config is cached', function () {
    putenv('XERADS_CMS_SECRET=from-the-environment');

    $config = configWithDefaults();
    $config->set('xerads.content.mode', 'turnkey');

    LegacyConfig::apply($config, readEnvironment: false);

    expect($config->get('xerads.content.mode'))->toBe('turnkey');
});

it('leaves a site without legacy settings alone', function () {
    $config = configWithDefaults();
    $config->set('xerads.content.mode', 'turnkey');

    LegacyConfig::apply($config);

    expect($config->get('xerads.content.mode'))->toBe('turnkey')
        ->and($config->get('xerads.legacy.secret'))->toBe('');
});

it('reads the legacy variables through the new config file', function () {
    putenv('XERADS_CMS_SECRET=from-the-environment');
    putenv('XERADS_CMS_MODEL=App\Models\Article');
    putenv('XERADS_CMS_PUBLIC_ROUTE=articles.show');

    $config = require __DIR__.'/../../config/xerads.php';

    putenv('XERADS_CMS_MODEL');
    putenv('XERADS_CMS_PUBLIC_ROUTE');

    expect($config['legacy']['secret'])->toBe('from-the-environment')
        ->and($config['content']['mapped']['model'])->toBe('App\Models\Article')
        ->and($config['content']['mapped']['public_route'])->toBe('articles.show');
});
