<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Workbench\App\Models\Post;
use XerAds\Laravel\Seo\Models\SeoMeta;

beforeEach(function () {
    config(['xerads.widgets.fetch_documents' => false]);
});

function page(string $body): string
{
    return '<!doctype html><html><head><title>Artikel</title></head><body>'.$body.'</body></html>';
}

/** A route in the web group, as a site's own pages are. */
function webPage(string $uri, Closure $action): void
{
    Route::middleware('web')->get($uri, $action);
}

const LOADER = '<script src="https://widgets.xerads.id/v1/loader.js" async></script>';

it('renders exactly the contract container', function () {
    expect(Blade::render('<x-xerads::widget id="w_k3v9q2m8x1c4b7na" lang="id" :height="2320" />'))->toBe(scriptContainer())
        ->and(Blade::render("@xeradsWidget('w_k3v9q2m8x1c4b7na', 'id', ['w_k3v9q2m8x1c4b7na' => 2320])"))->toBe(scriptContainer());
});

it('renders nothing for an invalid id, and a notice only for editors', function () {
    expect(Blade::render('<x-xerads::widget id="w_nope" />'))->toBe('')
        ->and(Blade::render('<x-xerads::widget id="w_nope" editor />'))->toContain('&quot;w_nope&quot; is not a widget id');

    app()->setLocale('id');

    expect(Blade::render('<x-xerads::widget id="w_nope" editor />'))->toContain('bukan id widget');
});

it('adds the loader to a page that has a container, once, before </body>', function () {
    webPage('/artikel', fn () => page('<p>Teks</p>'.scriptContainer().scriptContainer()));

    $html = (string) $this->get('/artikel')->assertOk()->getContent();

    expect(substr_count($html, '/v1/loader.js'))->toBe(1)
        ->and($html)->toEndWith(LOADER.'</body></html>');
});

it('prints the loader once when the layout has the scripts component', function () {
    webPage('/artikel', fn () => Blade::render(page(
        '<x-xerads::widget id="w_k3v9q2m8x1c4b7na" lang="id" /><x-xerads::widget id="w_bbbbbbbbbbbbbbbb" /><x-xerads::scripts />'
    )));

    expect(substr_count((string) $this->get('/artikel')->getContent(), '/v1/loader.js'))->toBe(1);
});

it('gives the injected loader the page\'s CSP nonce', function () {
    Vite::useCspNonce('nonce-123');
    webPage('/artikel', fn () => page(scriptContainer()));

    expect($this->get('/artikel')->getContent())
        ->toContain('<script src="https://widgets.xerads.id/v1/loader.js" async nonce="nonce-123"></script>');
});

it('leaves streamed, JSON, Inertia and widget-free responses alone', function () {
    webPage('/stream', fn () => response()->stream(fn () => print (page(scriptContainer())), 200, ['Content-Type' => 'text/html']));
    webPage('/json', fn () => response()->json(['html' => page(scriptContainer())]));
    webPage('/inertia', fn () => response(page(scriptContainer()))->header('X-Inertia', 'true'));
    webPage('/plain', fn () => page('<p>Tanpa widget</p>'));

    expect($this->get('/stream')->streamedContent())->not->toContain('loader.js')
        ->and($this->get('/json')->getContent())->not->toContain('loader.js')
        ->and($this->get('/inertia')->getContent())->not->toContain('loader.js')
        ->and($this->get('/inertia', ['X-Inertia' => 'true'])->getContent())->not->toContain('loader.js')
        ->and($this->get('/plain')->getContent())->toBe(page('<p>Tanpa widget</p>'));
});

it('does not add a second loader to a page that pasted one', function () {
    webPage('/artikel', fn () => page(scriptContainer().'<script src="https://widgets.xerads.id/v1/loader.js" async></script>'));

    expect(substr_count((string) $this->get('/artikel')->getContent(), 'loader.js'))->toBe(1);
});

it('can be turned off', function () {
    config(['xerads.widgets.inject_loader' => false]);
    webPage('/artikel', fn () => page(scriptContainer()));

    expect($this->get('/artikel')->getContent())->not->toContain('loader.js');
});

it('always prints the loader and the navigation hook with scripts spa', function () {
    Vite::useCspNonce('nonce-456');

    $html = Blade::render('<x-xerads::scripts spa />');

    expect($html)->toStartWith('<script src="https://widgets.xerads.id/v1/loader.js" async nonce="nonce-456"></script><script nonce="nonce-456">')
        ->and($html)->toContain("document.addEventListener('inertia:navigate', scan);")
        ->and($html)->toContain("document.addEventListener('livewire:navigated', scan);")
        ->and($html)->toContain('window.XeradsWidgets && window.XeradsWidgets.scan()')
        ->and(Blade::render('<x-xerads::scripts />'))->toBe('');
});

it('takes the loader address from config, never from a copy', function () {
    config(['xerads.widgets.runtime_url' => 'https://widgets.xerads.test/']);

    expect(Blade::render('<x-xerads::scripts spa />'))->toContain('src="https://widgets.xerads.test/v1/loader.js"');

    app()->forgetScopedInstances();
    config(['xerads.widgets.loader_url' => 'https://cdn.example.com/xerads/v2/loader.js']);

    expect(Blade::render('<x-xerads::scripts spa />'))->toContain('src="https://cdn.example.com/xerads/v2/loader.js"');
});

it('expands placeholders as the page renders, with the heights XerAds sent', function () {
    $post = Post::create(['user_id' => 1, 'title' => 'T', 'slug' => 't', 'content' => '<p>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</p>', 'status' => 'published']);
    SeoMeta::create([
        'seoable_type' => $post->getMorphClass(),
        'seoable_id' => $post->id,
        'extras' => ['language' => 'id', 'widgets' => [['id' => TEST_WIDGET_ID, 'min_height' => ['mobile' => 2320, 'desktop' => 1190]]]],
    ]);

    expect(Blade::render('<x-xerads::content :html="$post->content" :for="$post" />', ['post' => $post]))->toBe(scriptContainer())
        ->and(Blade::render('<x-xerads::content :html="$html" />', ['html' => '<pre>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</pre>']))
        ->toBe('<pre>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</pre>');
});

it('adds no loader for the attribute name as text: escaped, in a form, in script data, in a comment', function (string $body) {
    webPage('/edit', fn () => page($body));

    expect($this->get('/edit')->getContent())->not->toContain('loader.js');
})->with([
    'escaped body in a textarea' => ['<textarea>'.e(scriptContainer()).'</textarea>'],
    'raw tag in a textarea' => ['<textarea><div data-xerads-widget="w_k3v9q2m8x1c4b7na"></div></textarea>'],
    'JSON state in a script' => ['<script>window.state = {"body":"<div data-xerads-widget=\"w_k3v9q2m8x1c4b7na\"></div>"};</script>'],
    'escaped JSON in an attribute' => ['<div x-data="'.e(json_encode(['body' => scriptContainer()])).'"></div>'],
    'a comment' => ['<!-- '.scriptContainer().' -->'],
    'plain words' => ['<p>Attribute data-xerads-widget explained.</p>'],
]);

it('adds no loader to back-office paths', function () {
    webPage('/admin/posts/1/edit', fn () => page(scriptContainer()));
    webPage('/administrasi', fn () => page(scriptContainer()));

    expect($this->get('/admin/posts/1/edit')->getContent())->not->toContain('loader.js')
        // Only the listed paths: a public page that merely starts alike still gets it.
        ->and($this->get('/administrasi')->getContent())->toContain('loader.js');

    config(['xerads.widgets.inject_except' => ['blog/*', 'xerads.preview']]);
    Route::middleware('web')->get('/preview', fn () => page(scriptContainer()))->name('xerads.preview');
    Route::getRoutes()->refreshNameLookups();
    webPage('/blog/a', fn () => page(scriptContainer()));

    expect($this->get('/blog/a')->getContent())->not->toContain('loader.js')
        ->and($this->get('/preview')->getContent())->not->toContain('loader.js')
        ->and($this->get('/admin/posts/1/edit')->getContent())->toContain('loader.js');
});

it('renders no widgets and no loader with the widgets module off', function () {
    config(['xerads.modules.widgets' => false]);

    expect(Blade::render('<x-xerads::widget id="w_k3v9q2m8x1c4b7na" lang="id" :height="2320" />'))->toBe('')
        ->and(Blade::render('<x-xerads::scripts spa />'))->toBe('')
        ->and(Blade::render('<x-xerads::content :html="$html" />', ['html' => '<p>Isi</p><p>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</p>']))
        ->toBe('<p>Isi</p>');
});

it('keeps the components available when the widgets module is not registered', function () {
    $this->rebootAsInstall([], ['xerads.modules.widgets' => false]);

    expect(Blade::render('<x-xerads::content :html="$html" />', ['html' => '<p>Isi</p><p>[xerads_widget id="w_k3v9q2m8x1c4b7na"]</p>']))
        ->toBe('<p>Isi</p>');
});
