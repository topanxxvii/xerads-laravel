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

        if ($mode === 'turnkey') {
            $this->warn('Turnkey mode arrives in a later release. Until then a turnkey site refuses articles with a message in XerAds; choose mapped to store them in your own model now.');
        }

        $this->publishConfig();

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
        }

        $this->newLine();
        $this->info('In your layout, just before </body>:');
        $this->line('  <x-xerads::scripts />');
        $this->line('  (With Inertia or Livewire navigation: <x-xerads::scripts spa />)');
        $this->newLine();
        $this->info('Where an article body is printed:');
        $this->line('  {!! $post->content !!}                                   (content_format = html, the default)');
        $this->line('  <x-xerads::content :html="$post->content" :for="$post" /> (content_format = shortcode)');
        $this->newLine();
        $this->line('Then run `php artisan xerads:doctor`.');

        return self::SUCCESS;
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
