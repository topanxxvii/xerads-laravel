<?php

/**
 * /llms.txt: off unless the settings turn it on, then the site in a few
 * lines of Markdown.
 */

use Illuminate\Support\Str;

it('answers 404 by default', function () {
    $this->get('/llms.txt')->assertNotFound();
});

it('describes the site and its latest articles when the settings turn it on', function () {
    $this->bootTurnkey(['xerads.credentials.key' => testSiteKey()]);
    holdSettings(['site' => ['name' => 'Toko [Rumah]', 'url' => 'https://toko.test'], 'llms_txt' => ['enabled' => true, 'summary' => 'Panduan membeli rumah.']]);

    deliver($this, upsertEnvelope(['taxonomy' => ['categories' => [['name' => 'Keuangan', 'slug' => 'keuangan']], 'tags' => []]], ['delivery_id' => (string) Str::ulid()]));

    $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent(implode("\n", [
            '# Toko [Rumah]',
            '',
            '> Panduan membeli rumah.',
            '',
            '## Articles',
            '',
            '- [Panduan KPR 2026](https://toko.test/blog/panduan-kpr-2026): Syarat, bunga dan simulasi KPR 2026.',
            '',
            '## Categories',
            '',
            '- [Keuangan](https://toko.test/blog/category/keuangan)',
        ])."\n");
});

it('lets the site\'s config win over the settings', function () {
    holdSettings(['llms_txt' => ['enabled' => true]]);
    config(['xerads.llms_txt.enabled' => false]);

    $this->get('/llms.txt')->assertNotFound();

    holdSettings(['llms_txt' => ['enabled' => false]]);
    config(['xerads.llms_txt.enabled' => true]);

    $this->get('/llms.txt')->assertOk()->assertSee('# Toko Rumah', false);
});

it('lists a mapped site\'s published articles too', function () {
    config(['xerads.credentials.key' => testSiteKey()]);
    holdSettings(['llms_txt' => ['enabled' => true]]);

    deliver($this, upsertEnvelope([], ['delivery_id' => (string) Str::ulid()]));

    expect((string) $this->get('/llms.txt')->getContent())
        ->toContain('- [Panduan KPR 2026: Syarat dan Bunga](http://localhost/posts/panduan-kpr-2026): Syarat, bunga dan simulasi KPR 2026.');
});
