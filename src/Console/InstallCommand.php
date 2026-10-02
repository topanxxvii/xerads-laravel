<?php

namespace XerAds\Laravel\Console;

use Illuminate\Console\Command;
use XerAds\Laravel\Support\Diagnostics;

/**
 * `php artisan xerads:install`: the first steps, in order, and what to put
 * in the layout.
 *
 * It changes nothing it cannot undo: it publishes the config only when there
 * is none, runs the package's migrations, and only reports what it finds
 * (a missing storage link, static files that would shadow package routes).
 * It never edits .env and never deletes a file.
 */
final class InstallCommand extends Command
{
    protected $signature = 'xerads:install
        {--mode= : How articles are stored: mapped (your own model) or turnkey}
        {--force : Overwrite an already published config/xerads.php}
        {--no-migrate : Do not run the migrations}';

    protected $description = 'Set up the XerAds package on this site';

    public function handle(Diagnostics $diagnostics): int
    {
        $mode = $this->option('mode') ?? $this->choice('How should articles be stored?', [
            'mapped' => 'In a model this site already has (a Post model)',
            'turnkey' => 'In a blog the package provides',
        ], 'mapped');

        if (! in_array($mode, ['mapped', 'turnkey'], true)) {
            $this->error('The mode is mapped or turnkey.');

            return self::INVALID;
        }

        $this->publishConfig();

        if ($mode === 'turnkey') {
            $this->publishTurnkeyMigrations();
        }

        if (! $this->option('no-migrate')) {
            $this->call('migrate', $this->option('force') ? ['--force' => true] : []);
        }

        $this->reportEnvironment($diagnostics);

        $this->newLine();
        $this->info('Add to .env:');
        $this->line('  XERADS_CONTENT_MODE='.$mode);

        if ($mode === 'mapped') {
            $this->line('  XERADS_CONTENT_MODEL=App\\Models\\Post');
            $this->line('  XERADS_CONTENT_ROUTE=posts.show');
            $this->printMappedSnippets();
        } else {
            $this->line('  XERADS_BLOG_PREFIX=blog   (optional; the blog is served at /blog)');
            $this->newLine();
            $this->info('The blog has its own layout. To show it inside yours, set xerads.content.turnkey.layout,');
            $this->line('or publish the views to edit them: php artisan vendor:publish --tag=xerads-views');
            $this->newLine();
        }
        $this->line('Then run `php artisan xerads:doctor`.');

        return self::SUCCESS;
    }

    private function printMappedSnippets(): void
    {
        $this->newLine();
        $this->info('In your layout, just before </body>:');
        $this->line('  <x-xerads::scripts />');
        $this->line('  (With Inertia or Livewire navigation: <x-xerads::scripts spa />)');
        $this->newLine();
        $this->info('Where an article body is printed:');
        $this->line('  {!! $post->content !!}                                   (content_format = html, the default)');
        $this->line('  <x-xerads::content :html="$post->content" :for="$post" /> (content_format = shortcode)');
        $this->newLine();
    }

    /**
     * Copied into database/migrations, so the migration below creates the
     * blog's tables even before XERADS_CONTENT_MODE=turnkey is in .env.
     * Under the same file names, which the migrator recognises as the
     * package's own once the mode loads them too.
     */
    private function publishTurnkeyMigrations(): void
    {
        $target = $this->laravel->databasePath('migrations');

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        foreach (glob(__DIR__.'/../../database/migrations/turnkey/*.php') ?: [] as $migration) {
            $destination = $target.'/'.basename($migration);

            if (! file_exists($destination)) {
                copy($migration, $destination);
                $this->line('Published database/migrations/'.basename($migration).'.');
            }
        }
    }

    private function publishConfig(): void
    {
        if (file_exists(config_path('xerads.php')) && ! $this->option('force')) {
            $this->line('config/xerads.php already exists; left as it is.');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'xerads-config', '--force' => (bool) $this->option('force')]);
    }

    private function reportEnvironment(Diagnostics $diagnostics): void
    {
        if (! $diagnostics->storageLinked()) {
            $this->warn('public/storage is not linked. Run `php artisan storage:link` before article images are copied to this site.');
        }

        foreach (['robots.txt', 'sitemap.xml'] as $file) {
            if ($diagnostics->staticFile($file)) {
                $this->warn("public/{$file} exists and the web server serves it before Laravel. It was left in place; rename it once you rely on the package's {$file}.");
            }
        }
    }
}
