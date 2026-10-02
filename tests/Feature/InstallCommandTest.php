<?php

/**
 * Pointed at scratch config/ and public/ directories, so the command's file
 * checks run without touching the test application's own.
 */
beforeEach(function () {
    $config = scratchDirectory('config');
    file_put_contents($config.'/xerads.php', "<?php\n\nreturn [];\n");
    app()->useConfigPath($config);

    $this->public = scratchDirectory('public');
    app()->usePublicPath($this->public);

    $this->database = scratchDirectory('database');
    app()->useDatabasePath($this->database);
});

it('walks through the setup and prints what to add', function () {
    file_put_contents($this->public.'/robots.txt', "User-agent: *\n");

    $this->artisan('xerads:install', ['--mode' => 'mapped'])
        ->expectsOutputToContain('config/xerads.php already exists; left as it is.')
        ->expectsOutputToContain('public/storage is not linked')
        ->expectsOutputToContain('public/robots.txt exists')
        ->expectsOutputToContain('XERADS_CONTENT_MODE=mapped')
        ->expectsOutputToContain('XERADS_CONTENT_MODEL=App\\Models\\Post')
        ->expectsOutputToContain('<x-xerads::scripts />')
        ->expectsOutputToContain('<x-xerads::content :html="$post->content" :for="$post" />')
        ->assertSuccessful();

    // Reported, never removed.
    expect(file_exists($this->public.'/robots.txt'))->toBeTrue();
});

it('asks for the mode when none is given', function () {
    $this->artisan('xerads:install', ['--no-migrate' => true])
        ->expectsChoice('How should articles be stored?', 'turnkey', [
            'mapped', 'turnkey', 'In a model this site already has (a Post model)', 'In a blog the package provides',
        ])
        ->expectsOutputToContain('Published database/migrations/2026_10_04_000001_create_xerads_blog_tables.php.')
        ->expectsOutputToContain('XERADS_CONTENT_MODE=turnkey')
        ->expectsOutputToContain('php artisan vendor:publish --tag=xerads-views')
        ->assertSuccessful();

    // So `migrate` creates the blog's tables before .env says turnkey.
    expect(file_exists($this->database.'/migrations/2026_10_04_000001_create_xerads_blog_tables.php'))->toBeTrue();
});

it('refuses an unknown mode', function () {
    $this->artisan('xerads:install', ['--mode' => 'headless', '--no-migrate' => true])->assertExitCode(2);
});
