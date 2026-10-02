# XerAds for Laravel

`xerads/laravel` connects a Laravel site to XerAds.

- **Articles.** Articles published from XerAds arrive by a signed webhook and
  are stored either in a blog the package provides (tables, routes and views)
  or in a model your site already has, mapped column by column.
- **SEO.** One place prints every page's head: title, description, canonical,
  robots, Open Graph and `twitter:*` tags, verification tags and one JSON-LD
  graph, from the settings edited in the XerAds dashboard. Sitemaps,
  robots.txt, llms.txt, IndexNow, the redirects managed in XerAds and a 404
  monitor come with it.
- **Widgets.** XerAds widgets placed in an article render on the page. The
  widget runtime is loaded from XerAds, so new widget types need no package
  update.

Contents: [Requirements](#requirements) ·
[Install](#install) ·
[Pairing](#pairing) ·
[Where articles go](#where-articles-go-turnkey-or-mapped) ·
[Blade](#blade-components-and-directives) ·
[Inertia, Livewire and Octane](#inertia-livewire-and-octane) ·
[Widgets and CSP](#widgets-and-content-security-policy) ·
[On-page SEO](#on-page-seo) ·
[Technical SEO](#technical-seo) ·
[Queue and scheduler](#queue-and-scheduler) ·
[Configuration](#configuration) ·
[Troubleshooting](#troubleshooting) ·
[Upgrading from xerads/cms-bridge](#upgrading-from-xeradscms-bridge) ·
[Security](#security)

## Requirements

- PHP 8.2 or later, with the `dom`, `json`, `mbstring` and `xmlwriter`
  extensions. The `curl` extension is recommended: outbound requests are
  pinned to the address the package checked only when PHP sends them through
  it.
- Laravel 11 (PHP 8.2 to 8.4), 12 (PHP 8.2 to 8.5) or 13 (PHP 8.3 to 8.5).
  Laravel 14 is declared and tested against its development branch until it
  is released.
- MySQL 8.0+, MariaDB 10.6+, PostgreSQL 13+ or SQLite 3.35+.
- A site served over https: XerAds pairs only with https sites.
- For the full feature set, the Laravel scheduler running every minute and a
  queue worker (see [Queue and scheduler](#queue-and-scheduler)). Both are
  optional: without them the package does the same work after responses.

## Install

```bash
composer require xerads/laravel
php artisan xerads:install
php artisan xerads:doctor
```

The service provider and the `Xerads` facade register themselves.

`xerads:install` asks where articles should go (`--mode=mapped` or
`--mode=turnkey` answers it in advance), publishes `config/xerads.php` when
there is none (`--force` overwrites it), publishes the blog's migrations in
turnkey mode, runs the migrations (`--no-migrate` skips them) and prints the
lines to add to `.env` and to your layouts. It never edits `.env` and never
deletes a file. The migrations create the package's own tables, prefixed
`xerads_`; they do not touch yours.

If article images are to be copied onto your site, link the public disk once:

```bash
php artisan storage:link
```

`xerads:doctor` checks everything that can stop a delivery or hide a page
(see [Troubleshooting](#troubleshooting)) and exits non-zero when something is
broken; `--json` prints the checks for scripts.

To publish the config file by hand instead:

```bash
php artisan vendor:publish --tag=xerads-config
```

## Pairing

Add the site in the XerAds dashboard, then run the command it shows, once,
within 30 minutes:

```bash
php artisan xerads:pair xpc_…
```

The site key it receives is stored encrypted in the database (with your
`APP_KEY`, so keep the old one in `APP_PREVIOUS_KEYS` when you rotate it) and
never printed; every server of the site reads it from there,
and it survives `config:cache`. To keep it in the environment instead,
`--print-env` prints an `XERADS_SITE_KEY=` line once and stores nothing.
`--show` shows which key the site holds, by id, never the secret.

Pairing again with `--rotate` replaces the key. The old one keeps working for
a day, so deliveries already on their way still verify. When the key lives in
the environment, put the old one in `XERADS_SITE_KEY_PREVIOUS` for that day.

Once paired:

- XerAds sends every event to `POST /xerads/v1/webhook`. The route sits
  outside every middleware group: the `web` group's CSRF check would refuse
  each delivery, and the request's signature is its authentication.
- `GET /xerads/v1/status` answers publicly with the package version, contract
  and features, and nothing else (`xerads.routes.status` turns it off).
- The site keeps a copy of its SEO settings and redirects and refreshes it
  (see [Queue and scheduler](#queue-and-scheduler)). A failed refresh keeps
  the last good copy and retries later, waiting longer after each failure in
  a row.

```bash
php artisan xerads:status              # the connection at a glance (--json for scripts)
php artisan xerads:sync                # refresh the copy, if it is due
php artisan xerads:sync --full         # heartbeat, settings, redirects and the 404 report, now
php artisan xerads:sync --settings     # or --heartbeat, --redirects, --report-404 on their own
```

Each delivery is checked for its size (2 MB), its contract, its signature,
its timestamp, its key and its site, then recorded in `xerads_deliveries`: a
retry of a delivery already applied gets the original reply, and an event
older than one already applied is dropped. An article's HTML is cleaned (an
allowlist of article markup: no scripts, styles or iframes), its h2 and h3
get ids by the same rule XerAds uses, and its widget placeholders become
containers before it is stored.

## Where articles go: turnkey or mapped

`XERADS_CONTENT_MODE` decides: `turnkey`, `mapped` (the default) or `off` (no
articles; SEO and widgets only).

### Turnkey: the package's blog

The package keeps articles, categories and tags in its own tables and serves
them:

| Address | Page |
| --- | --- |
| `/blog` | published articles, newest first, 12 a page |
| `/blog/{slug}` | an article |
| `/blog/category/{slug}`, `/blog/tag/{slug}` | a category's or a tag's articles |
| `/blog/preview/{id}?signature=…` | any article, whatever its status, for XerAds to open a draft |

```bash
php artisan xerads:install --mode=turnkey
```

```dotenv
XERADS_CONTENT_MODE=turnkey
XERADS_BLOG_PREFIX=blog
```

Pages print the article's headline as their only H1, the body with its
widgets, a table of contents and plain semantic markup with a few overridable
styles. They use the package's own layout, a complete page. To show the blog
inside your layout, set `xerads.content.turnkey.layout` (a view the pages
extend) and `section` (the section they fill). To change the markup, publish
the views to `resources/views/vendor/xerads`:

```bash
php artisan vendor:publish --tag=xerads-views
```

The layout prints the whole head with `@xeradsHead` and offers
`@stack('xerads-head')` for tags of your own.

How articles behave:

- **Slugs** are unique, and follow XerAds until the article is first
  published. After that the address stays, so a retitled article keeps its
  links (`xerads.content.slug.freeze_after_publish`). With freezing off, the
  article moves and its old address answers 301.
- **A draft** answers 404. Its signed preview link, which XerAds stores from
  the reply, shows it with `noindex`. The link does not expire and stops
  working once the article is deleted.
- **Unpublished** articles answer 404; **deleted** ones are soft-deleted and
  their address answers 410. Publishing the article again brings it back.
- **Images** are copied onto `XERADS_MEDIA_DISK` (the `public` disk) by a
  queued job: https addresses only, JPEG, PNG, WebP, GIF or AVIF up to 10 MB,
  each address once. Until then pages show XerAds' address; after, the copy,
  so regenerating an image in XerAds never breaks a live page.

The blog's routes are registered after your own, so a route of yours under
the prefix (`/blog/feed`, `/blog/search`) wins over `/blog/{slug}`, with or
without `route:cache`, and no article is given a slug such a route answers.
A catch-all route of yours registered with `Route::get('{any}', …)` would
hide the whole blog: register it with `Route::fallback()` instead, or keep
the prefix out of its pattern, for example
`->where('any', '^(?!blog(/|$)).*')`. `xerads:doctor` warns when that
happens (`blog_route`).

### Mapped: your own model

Articles are written into a model your site already has:

```dotenv
XERADS_CONTENT_MODE=mapped
XERADS_CONTENT_MODEL=App\Models\Post
XERADS_CONTENT_ROUTE=posts.show
```

Leave the model class unquoted. Inside double quotes `\A` is an escape
sequence the `.env` parser does not know, and the whole file fails to load.

No two sites name their columns the same way, so publish the config and edit
`xerads.content.mapped`:

```php
'fields' => [
    'title'            => 'title',
    'seo_title'        => null,       // not written: no column needed
    'content'          => 'body',
    'slug'             => 'slug',
    'meta_description' => 'excerpt',
    'image_url'        => 'cover_url',
    'status'           => 'state',
    'keywords'         => 'tags',     // an array or JSON cast gets a list; any other column "a, b"
    'excerpt'          => null,
    'headline'         => null,
],

// Written only when a post is created, for NOT NULL columns XerAds knows nothing about.
'defaults' => ['user_id' => 1],

// XerAds sends two statuses; store whatever your scopes expect.
'status_map' => ['draft' => 'draft', 'publish' => 'published'],
```

- `public_route` (`XERADS_CONTENT_ROUTE`) is the named route that shows a
  post. Its parameter (`public_route_parameter`, `slug` by default) is filled
  from the saved slug, from `public_route_column` when set, or from the
  primary key when the parameter is not `slug`. Without a route, XerAds
  records no public link rather than guessing one.
- `content_format`: `html` (the default) stores finished HTML with the widget
  containers, for a template that prints `{!! $post->body !!}`; `shortcode`
  keeps the `[xerads_widget …]` placeholders, for a template that prints
  `<x-xerads::content :html="$post->body" :for="$post" />`.
- `on_delete`: `unpublish` (the default) keeps the row as a draft; `delete`
  deletes it.
- `match_existing_by_slug`: adopt an existing post with the same slug on an
  article's first delivery. Off by default; turn it on once while importing
  articles that were copied over by hand.
- Images are copied as in turnkey mode, into the mapped image and content
  columns.

The SEO data XerAds sends with each article is kept in `xerads_seo_meta`. To
read it back, and to let the head use it, add the trait and interface to
your model:

```php
use XerAds\Laravel\Seo\Concerns\HasXeradsSeo;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;

class Post extends Model implements ProvidesSeo
{
    use HasXeradsSeo;
}

$post->xeradsSeo()?->description;
```

To store articles somewhere else entirely, bind your own
`XerAds\Laravel\Content\Contracts\ContentReceiver`. A receiver written for the
old custom endpoint (`XerAds\CmsBridge\Contracts\ArticleReceiver`) keeps
receiving paired articles too.

### Trying it without the dashboard

`xerads:simulate` sends a signed sample delivery through your own HTTP kernel,
exactly as XerAds would:

```bash
php artisan xerads:simulate                       # article.upsert, published
php artisan xerads:simulate --status=draft
php artisan xerads:simulate --event=ping
php artisan xerads:simulate --fixture=article.json
php artisan xerads:simulate --mode=mapped         # store as the mapped mode would, whatever the .env says
```

It needs a site key (pair first, or set `XERADS_SITE_KEY` to any well-formed
key) and refuses to run in production unless given `--force`.

## Blade components and directives

```blade
{{-- In the <head> of every layout --}}
@xeradsHead
@xeradsHead($post)                                 {{-- a page about a model --}}
<x-xerads::head :for="$post" except="title" />     {{-- only / except take groups --}}

{{-- In the page --}}
<x-xerads::breadcrumbs />                          {{-- the trail the JSON-LD uses; :for="$post" --}}
<x-xerads::toc :for="$post" />                     {{-- the article's h2 and h3 --}}
<x-xerads::content :html="$post->body" :for="$post" />   {{-- content_format = shortcode --}}
<x-xerads::widget id="w_k3v9q2m8x1c4b7na" lang="id" />
@xeradsWidget('w_k3v9q2m8x1c4b7na', 'id')

{{-- Just before </body> --}}
<x-xerads::scripts />                              {{-- spa: on Inertia or Livewire sites --}}
```

The head groups are `title`, `description`, `canonical`, `robots`,
`verification`, `og`, `twitter` and `jsonld`, comma-separated. In PHP, the
`Xerads` facade gives the same objects: `Xerads::head()`,
`Xerads::breadcrumbs()`, `Xerads::settings()`, `Xerads::version()`. A route
can carry its own robots choice: `Route::get(…)->xeradsRobots('noindex')`.

## Inertia, Livewire and Octane

- **Inertia.** With Inertia installed, the head is shared as the `xerads`
  prop (`{title, meta, link, jsonld}`) on every response of the `web` group,
  for the page head of your front end to render after a navigation
  (`xerads.seo.inertia.share` turns it off). Keep `@xeradsHead` in the root
  view too, for the first, crawlable load.
- **Inertia and Livewire navigation.** Pages that change without a reload
  need `<x-xerads::scripts spa />`: it always includes the widget loader and
  asks it to look for widgets again after each navigation.
- **Octane.** Everything that belongs to a request (the head, the
  breadcrumbs, the settings read for it) is bound per request, so nothing
  carries over from one request to the next on a long-running worker.
  Nothing needs configuring.

## Widgets and Content Security Policy

A XerAds widget is placed in an article as
`[xerads_widget id="w_…" lang="id"]`, or as any embed code the dashboard
hands out; the package stores the placeholder and renders the container the
widget runtime mounts. The runtime is always loaded from
`https://widgets.xerads.id`, never copied into your site.

With the default `content_format` (`html`), `{!! $post->body !!}` is all a
template needs: a middleware adds the loader to any page with a widget on it,
never to paths in `xerads.widgets.inject_except` (the `admin` area by
default). With `xerads.widgets.inject_loader` off, put
`<x-xerads::scripts />` (or `spa`) in the layout yourself. It prints the
loader once, only on pages that rendered a widget, with the page's CSP nonce
when the application set one for its scripts.

A Content Security Policy must allow the widget host:

```
script-src  https://widgets.xerads.id
frame-src   https://widgets.xerads.id
connect-src https://widgets.xerads.id
```

`XERADS_WIDGETS_URL` points the runtime at another host; allow that one
instead.

## On-page SEO

Every page can print its head from one place: the settings edited in the
XerAds dashboard (titles, robots, organisation, verification, per-page
overrides), the page's article or model, and what the page says while it is
handled. In your layout's `<head>`:

```blade
@xeradsHead                              {{-- any page --}}
<x-xerads::head :for="$post" />          {{-- a page about a model (a mapped post) --}}
<x-xerads::head :for="$post" except="title" />   {{-- your layout prints its own <title> --}}
```

It prints, once each: `<title>`, the description, the canonical link, the
robots tag, the Open Graph and `twitter:*` tags (`article:*` on articles), the
verification tags (home page only) and one JSON-LD `@graph` (organisation,
website, web page, breadcrumbs, primary image, article). `only` and `except`
take groups: title, description, canonical, robots, verification, og,
twitter, jsonld.

The turnkey blog's layout uses `@xeradsHead` already. On a mapped site, the
post page's stored SEO (title, description, canonical, robots and share
overrides from XerAds) is used once the page is told which post it shows.
Tell it in the controller, so everything printed before the layout's head
(breadcrumbs, the table of contents) knows too:

```php
public function show(Post $post)
{
    Xerads::head()->for($post);

    return view('posts.show', compact('post'));
}
```

and print `@xeradsHead` in the layout. (`<x-xerads::head :for="$post" />` in
the layout works as well, but the page's body renders before its layout: give
`<x-xerads::breadcrumbs :for="$post" />` the post too.)

During a request, in a controller:

```php
use XerAds\Laravel\Facades\Xerads;

Xerads::head()->title('Products')->description('Everything we sell.');
Xerads::head()->for($product);                     // a model with ProvidesSeo
Xerads::head()->page('search', query: $q);         // kinds: article, home, category, tag, archive, search, not_found
Xerads::head()->robots('noindex')->canonical('/products');
Xerads::breadcrumbs()->push('Products', '/products')->push($product->name);
Xerads::head()->toArray();                         // {title, meta, link, jsonld} for a headless front end
```

```blade
<x-xerads::breadcrumbs />                {{-- the same trail the JSON-LD uses (:for="$post" if the head is told in the layout) --}}
<x-xerads::toc :for="$post" />           {{-- the article's headings --}}
```

Who wins, lowest first: the package's defaults, the settings from XerAds,
`xerads.seo.overrides` in your config, `pages[]` in the settings for the exact
path, a route's own robots choice (`Route::get(…)->xeradsRobots('noindex')`),
the page's model, then calls made during the request. A page without a
description of its own gets the settings' default description. For robots,
the model may only restrict: an article's `index: true` never lifts a noindex
from the settings or the route. A path rule in the settings (`robots.paths`)
covers its path and everything under it (`/account` covers
`/account/settings`); `*` is a wildcard. Some noindex decisions are forced:
outside production (`xerads.seo.noindex_non_production`), previews,
`index_site: false` and the noindex groups in the settings (categories, tags,
paginated pages, search). The same decision goes into an `X-Robots-Tag`
header, for responses without a head too, whenever it restricts indexing.

Addresses (canonical, `og:url`, the JSON-LD) are built from the settings'
`site.url`, else `APP_URL`, never from the request's Host header. Only lists
(`page('archive')`, category, tag, search, or `Xerads::head()->paginated()`)
keep `?page=`, from page 2 on; elsewhere it is ignored. Without a pairing,
SEO runs on your config alone (`xerads.seo.overrides` and the application's
name and address).

## Technical SEO

Everything here follows the settings edited in XerAds; the config file can
turn each part off. The routes are registered after the application's own,
and a path the application already serves (its own `/robots.txt`,
`/sitemap.xml` or `/llms.txt`) keeps answering. A route of the application's
with parameters (a `/{slug}` page route, an SPA's `/{any}` catch-all) does not
hide them: the `ServeSeoFiles` middleware answers these paths with the
package's routes first, and leaves them to the application where the package
has nothing to serve. `xerads:doctor` warns about a static `public/robots.txt`
or `public/sitemap.xml`, which the web server serves before Laravel sees the
request, and about a catch-all that answers these paths while that middleware
is off; the heartbeat reports the same.

**Sitemaps.** `/sitemap.xml` is an index of `/sitemaps/{source}-{page}.xml`,
with images (`image:image`) for featured images. The sources are the turnkey
articles, the categories and tags that have a published article, the mapped
model's published articles, the models in `xerads.sitemap.models` and the
classes in `xerads.sitemap.sources` (implementing
`XerAds\Laravel\Seo\Sitemap\Contracts\SitemapSource`). A sitemap's name
(the key in `xerads.sitemap.models`, or the source's `name()`) is lowercase
letters only and unique, because it becomes part of the address; any other
name stops the sitemaps with an error saying which. A model lists the rows its
`scopeXeradsSitemap()` keeps, at `xeradsSitemapUrl()`, which may use `url()`
and `route()`: addresses are built on the site's own address while the
sitemap is read, whatever Host the request came with:

```php
public function scopeXeradsSitemap(Builder $query): void
{
    $query->where('status', 'published');
}

public function xeradsSitemapUrl(): string
{
    return route('products.show', $this);
}
```

A page whose head says noindex is left out, by the same decision: the
settings' default, path rules, `pages[]` entries and noindex groups
(categories, tags), and the page's own robots, which can only keep it out.
So are pages whose canonical points elsewhere, that `sitemap.exclude_paths`
covers, that are on another host, or whose address XML cannot carry (not
UTF-8, or with control characters; logged). Image addresses are put on the
site's own address and left out unless absolute. `include`, `max_urls` (per
file) and `enabled` come from the settings; `lastmod` is when the content last
changed. The files are cached until the content or the settings change (the
site's own models: for `cache_ttl` seconds), never while answering another
host, and built for every request while the cache is down. With nothing to
list (`index_site: false`, or no content yet) `/sitemap.xml` answers 404: the
protocol has no empty index. A request that fails while building answers
`503` with `Retry-After: 60` rather than a partial file, which search engines
would read as the missing pages being gone.

**robots.txt** is written from the settings' rules and extra lines, with a
`Sitemap:` line on the site's own address (none while `index_site` is false).
Outside production it is
`Disallow: /` for everyone, whatever the settings say (unless
`xerads.seo.noindex_non_production` is off).

**llms.txt** describes the site and lists its latest articles and its
categories in Markdown, for language models. It is off until it is turned on
in the settings (`llms_txt.enabled`); `xerads.llms_txt.enabled` overrides the
settings either way.

**IndexNow.** When an article is published, updated, unpublished or deleted,
its address (and the old one, when it moved) is submitted to IndexNow, which
shares it with the search engines that take part. With a queue worker the
submission is a job delayed by `xerads.indexnow.debounce_seconds` (60), and
every change in that window goes in the same submission; without one (the
`sync` driver) it is sent after the response that made the change.
Submissions happen only from the environments in
`xerads.indexnow.environments` (production) and only for a site whose address
is https. The key comes from the settings, else from pairing, and is served at
`/{key}.txt`. The outcome of the last submission is reported in the
heartbeat.

**Redirects** from the XerAds dashboard are applied when the site itself
answers 404 to a GET or HEAD request, so a redirect never hides a page that
exists. The exact rule wins, then the longest prefix; a prefix rule sends
everything under it to its target as it is. The query string is carried over
unless the rule says not to, as the visitor sent it and before the target's
`#fragment`. `410` and `451` answer through the application's exception
handler, so visitors see the site's own error page (`errors/410.blade.php`,
`errors/4xx.blade.php`, else the framework's) with that status. The turnkey
blog's addresses follow the same rules. The site's own rules (origin `local`)
win over XerAds' rules, which win over the package's automatic ones (written
when a turnkey article changes its slug or is deleted). Rules are cached;
saving or deleting a `Redirect` model is seen at once, and a write through the
query builder must call `Redirect::changed()`. Each pull replaces XerAds'
rules as a whole and leaves the others alone; a rule that would loop, with
itself or with another rule, is left out and logged. Nothing is written to
the database while redirecting.

**404 monitor.** Paths that answer 404 to a GET or HEAD request are counted
after the response has been sent: the path without its query string, the
number of hits, when first and last seen and the host of the referring page.
Never the visitor's IP address or user agent. What scanners probe for
(`/.env`, `/.git/config`, `*.php`…), missing assets (images, scripts, fonts)
and the paths in `xerads.monitor_404.ignore` are not counted; neither are more
than `per_ip_per_minute` (30) 404s a minute from one visitor. New paths stop
being added at `max_rows` (10,000). New hits are reported to XerAds in batches
of up to 500 paths with each sync, where they can be redirected from the
dashboard; a path that becomes the source of an exact redirect is closed.
`monitor_404` in the settings turns counting or reporting off.

**Pruning.** `xerads:prune` deletes 404 paths not seen for
`monitor_404.retention_days` (30), delivery records past
`webhook.delivery_retention_days` (7) and the package's expired entries in a
database cache store. It runs daily at about 03:00 through the scheduler;
`xerads.prune.scheduled` turns that off.

**Middleware.** The redirects, the package's SEO files, the 404 monitor and
the `X-Robots-Tag` header are global middleware, added through the HTTP
kernel. To place them in your own stack instead, turn them off with
`xerads.middleware.global` (all four) or `xerads.middleware.redirects`,
`.seo_files`, `.not_found` and `.robots_header` (one each), and add the
classes yourself:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append([
        \XerAds\Laravel\Seo\Http\ApplyRobotsHeader::class,
        \XerAds\Laravel\Seo\Redirects\Http\HandleRedirects::class,
        \XerAds\Laravel\Seo\Http\ServeSeoFiles::class,
        \XerAds\Laravel\Seo\NotFound\Http\RecordNotFound::class,
    ]);
})
```

## Queue and scheduler

**Scheduler.** With Laravel's scheduler running (`* * * * * php artisan
schedule:run` in cron), the package schedules:

| Command | When |
| --- | --- |
| `xerads:sync` | every 15 minutes: refreshes the settings and redirects once the copy is stale, and reports new 404s |
| `xerads:sync --heartbeat` | hourly: tells XerAds the site's version, health checks and counts |
| `xerads:prune` | daily at about 03:00: old 404 paths, delivery records and expired cache entries |

The minutes are picked from the site id, so that sites do not all call XerAds
at once. `xerads.sync.schedule` and `heartbeat_schedule` take cron
expressions instead; `xerads.sync.scheduler` turns the sync entries off and
`xerads.prune.scheduled` the prune. A site without cron still refreshes after
a response once its copy is older than `stale_after_minutes` (15)
(`xerads.sync.after_response`; off while your own test suite runs).

**Queue.** Copying article images and submitting to IndexNow are queued jobs,
on `xerads.queue.connection` and `xerads.queue.queue` (your defaults when
null), dispatched after the database commit. With the `sync` driver there is no
worker: they run after the response instead, in the same PHP process, which
`xerads:doctor` warns about because a slow image host then keeps that process
(and XerAds' delivery) waiting. Run a worker:

```bash
php artisan queue:work
```

## Configuration

Publish `config/xerads.php` to change anything (`php artisan
vendor:publish --tag=xerads-config`). The keys that matter most:

| Key | Environment | Default | What it does |
| --- | --- | --- | --- |
| `credentials.key` | `XERADS_SITE_KEY` | null | The site key, when not stored by `xerads:pair` |
| `credentials.previous` | `XERADS_SITE_KEY_PREVIOUS` | null | The retiring key during a rotation |
| `api.url` | `XERADS_API_URL` | `https://api.xerads.id` | The XerAds API |
| `modules.content`, `.seo`, `.widgets`, `.sync` | | true | Turn whole parts of the package off |
| `routes.prefix` | | `xerads/v1` | Where the webhook and status routes live |
| `routes.status` | | true | The public status route |
| `content.mode` | `XERADS_CONTENT_MODE` | `mapped` | `turnkey`, `mapped` or `off` |
| `content.turnkey.prefix` | `XERADS_BLOG_PREFIX` | `blog` | The blog's address |
| `content.turnkey.layout`, `.section` | | the package's | The layout the blog's pages extend |
| `content.turnkey.middleware` | | `['web']` | The blog's middleware |
| `content.mapped.model` | `XERADS_CONTENT_MODEL` | `App\Models\Post` | Your post model |
| `content.mapped.public_route` | `XERADS_CONTENT_ROUTE` | empty | The named route that shows a post |
| `content.mapped.fields`, `.defaults`, `.status_map` | | | Your columns and values |
| `content.mapped.content_format` | | `html` | `html` or `shortcode` |
| `content.slug.freeze_after_publish` | | true | Keep a published article's address |
| `media.mirror` | | true | Copy article images onto your disk |
| `media.disk` | `XERADS_MEDIA_DISK` | `public` | Where the copies go |
| `seo.remote` | | true | Use the settings edited in XerAds |
| `seo.overrides` | | `[]` | Settings pinned in code, in the same shape |
| `seo.noindex_non_production` | | true | Noindex, and `Disallow: /`, outside production |
| `seo.search_url` | | null | Your search page, for the structured data |
| `sitemap.models`, `.sources` | | `[]` | More sitemaps |
| `sitemap.cache_ttl` | | 3600 | How long your own models' sitemaps are cached |
| `llms_txt.enabled` | | null | Force llms.txt on or off (null follows the settings) |
| `indexnow.environments` | | `['production']` | Where IndexNow submissions are sent from |
| `redirects.enabled` | | true | Apply the redirects |
| `monitor_404.enabled`, `.ignore` | | true, `[]` | The 404 monitor and paths it leaves out |
| `middleware.global` and the switches beside it | | true | The package's global middleware |
| `widgets.runtime_url` | `XERADS_WIDGETS_URL` | `https://widgets.xerads.id` | The widget runtime |
| `widgets.inject_loader`, `.inject_except` | | true, `admin` | Where the loader is added |
| `sync.scheduler`, `.schedule`, `.heartbeat_schedule` | | true, null, null | The scheduled sync |
| `queue.connection`, `.queue` | | null | Where the package's jobs go |
| `cache.store`, `.prefix` | | null, `xerads` | Where the package caches |
| `database.connection`, `.table_prefix` | | null, `xerads_` | Where the package's tables live |
| `http.verify_public_dns` | `XERADS_VERIFY_PUBLIC_DNS` | true | Check that outbound hosts resolve to public addresses |

Every key is described in the published file.

## Troubleshooting

Start with the two commands:

```bash
php artisan xerads:doctor          # what is wrong, and how to fix it (--json)
php artisan xerads:status          # the connection, the last delivery, heartbeat and sync (--json)
```

`xerads:doctor` checks `APP_URL`, the storage link, the queue driver, the key,
the webhook route and its middleware, routes sharing the package's prefix,
the tables, the receiver and your model's columns, the widget loader, static
`public/robots.txt` and `public/sitemap.xml` files, and routes of yours that
answer the blog's or the package's paths first. The same health checks go to
XerAds with the hourly heartbeat, so the dashboard shows them too.

| Symptom | Cause and fix |
| --- | --- |
| Deliveries answer 419 | Browser middleware (the CSRF check) runs on the webhook route. `xerads:doctor` names it; keep it off that route. |
| Deliveries answer 401 `TIMESTAMP_SKEW` (or a sync fails with `SITE_TIMESTAMP_SKEW`) | The server's clock is more than five minutes off. Sync it. |
| Deliveries answer 401 `KEY_UNKNOWN` or `SITE_MISMATCH` | The site holds another key than XerAds uses. `xerads:pair --show`, then pair again. |
| Deliveries answer 503 `NOT_CONFIGURED` | The site holds no key yet. Run `xerads:pair`. |
| The site looks unpaired after `APP_KEY` changed | The stored key is encrypted with `APP_KEY`. List the old key in `APP_PREVIOUS_KEYS`, or pair again. |
| `xerads:status` says the connection was removed | XerAds revoked the site. Pair it again from the dashboard. |
| Article images point at XerAds | The copy job has not run: start a queue worker, run `php artisan storage:link`, check `XERADS_MEDIA_DISK`. |
| robots.txt or sitemap.xml is not the package's | A static file in `public/` wins; rename it. Or a route of yours answers first (doctor: `robots_route`, `sitemap_route`). |
| The blog answers your SPA's page | Your catch-all route takes `/blog`; see [Turnkey](#turnkey-the-packages-blog) (doctor: `blog_route`). |
| Widgets stay empty | The loader is not on the page, or your CSP blocks `widgets.xerads.id`. |
| Settings changed in XerAds do not show | `php artisan xerads:sync --full`, then check the scheduler. |

## Upgrading from xerads/cms-bridge

`xerads/laravel` replaces `xerads/cms-bridge`. The class names, the route, the
environment variables (`XERADS_CMS_SECRET`, `XERADS_CMS_MODEL`,
`XERADS_CMS_PUBLIC_ROUTE`, `XERADS_CMS_ROUTE`) and a published
`config/xerads-cms.php` all keep working, so a site that only upgrades keeps
receiving articles exactly where it did.

```bash
composer remove xerads/cms-bridge
composer require xerads/laravel
php artisan migrate
```

Nothing else is required. If you registered
`XerAds\CmsBridge\CmsBridgeServiceProvider` by hand, it still works and can be
removed; the new provider is discovered automatically. Pairing the site
afterwards (`xerads:pair`) adds everything else, and the old endpoint keeps
working beside it until you remove its secret.

### What behaves differently

- **The route exists only while a secret is set.** Without
  `XERADS_CMS_SECRET`, `POST /api/xerads/articles` is not registered at all
  (404). If the secret is removed after `php artisan route:cache`, the route
  answers 503 `NOT_CONFIGURED` instead of the old 500.
- **Only the signed body is read.** The query string and the `Content-Type`
  header are not covered by the signature, so they are ignored: the article,
  and the connection-test flag, come from the JSON body alone. A body that is
  not a JSON object answers 400 `INVALID_PAYLOAD`.
- **"Test connection" now checks your setup.** It used to answer 200 without
  looking. It now confirms that the model class exists and that every mapped
  column exists on its table, and answers 422 `RECEIVER_MISCONFIGURED` with a
  `problems` list when they do not. It still stores nothing. A receiver of
  your own is checked only if it implements `ValidatesConfiguration` (see
  below).
- **An identical copy of a request is answered from a record.** A request
  received a second time, byte for byte, within the five-minute timestamp
  window gets the original response and stores nothing. This needs the
  migration; without it the endpoint keeps working without the check and logs
  a warning. A XerAds retry is not such a copy — it is signed again with a new
  timestamp — and is matched to the first attempt's post by slug, below.
- **Only `publish` publishes.** `pending`, `private`, a missing status or
  anything else is stored as a draft, and `url` is returned only for a
  published post.
- **A post is still matched by slug on its first sync**, as before. That is
  how a retry after a timeout, which reaches you without the post id, updates
  the post the first attempt created instead of adding a second one. XerAds
  gives every slug it sends here a random suffix, so an unrelated post
  practically never shares one. To turn matching off, set
  `xerads.legacy.match_existing_by_slug` to `false`; a new post then gets
  `-2` (`-3`, …) appended to its slug when the slug is taken.
- **A soft-deleted post comes back.** If XerAds sends an article whose post is
  in the trash (with `SoftDeletes`), the post is restored and updated: sending
  it again is a request to publish it.
- **The returned `url` uses the saved slug**, so a slug your model or the
  suffix above changed is the one XerAds links to. Without a mapped slug
  column, the model's own `slug` attribute is used, then the slug XerAds sent.
- **A delivery without an image keeps the post's image** instead of erasing it.
- **Keywords can be stored**: map `fields.keywords` to a column. An array or
  JSON cast receives a list; any other column receives `kpr, bunga kpr`.
- **Required columns can be filled**: `content.mapped.defaults` sets values on
  newly created posts, for a NOT NULL column XerAds knows nothing about, such
  as `user_id`.
- **Refusals are JSON** — `{"ok": false, "error": "SIGNATURE_INVALID", "message": "…"}` —
  so XerAds can tell a clock problem from a wrong secret. A receiver that
  throws answers 500 `RECEIVER_FAILED` and the exception goes to your log; an
  HTTP error it raises on purpose (`abort()`, a validation failure) keeps its
  own status.

The settings formerly under `config('xerads-cms.*')` now live under
`config('xerads.legacy.*')` and `config('xerads.content.mapped.*')`; the old
keys still read back the values in effect. A published `config/xerads-cms.php`
is still read and wins over the new file for the keys it contains; its
`fields` map is taken as complete, exactly as before, so a field it leaves out
is not written. Delete it once its values have moved to `config/xerads.php`.
Any `XERADS_CMS_*` variable, or that file, selects the mapped content mode.

### The old custom endpoint

Sites that never pair keep using the endpoint as before.

#### Setup

```dotenv
XERADS_CMS_SECRET=the-secret-token-from-the-connection
XERADS_CMS_MODEL=App\Models\Post
XERADS_CMS_PUBLIC_ROUTE=posts.show
```

Leave the model class unquoted. Inside double quotes `\A` is an escape sequence
the `.env` parser does not know, and the whole file fails to load.

Then in XerAds → **CMS Connections** → **Custom**:

| Field | Value |
| --- | --- |
| Endpoint URL | `https://your-site.test/api/xerads/articles` |
| Secret Token | the same string as `XERADS_CMS_SECRET` |

Press **Test connection**. It posts a flagged, obviously fake article; the
package checks its configuration, answers, and stores nothing.

#### Mapping it to your schema

The endpoint writes into the same model with the same
`xerads.content.mapped` mapping as paired deliveries: see
[Mapped: your own model](#mapped-your-own-model).

#### When the default is not enough

Bind your own receiver. You keep the signature verification, the replay
protection, the connection-test handling and the response contract; you decide
what "store an article" means.

```php
// AppServiceProvider::register()
$this->app->bind(\XerAds\CmsBridge\Contracts\ArticleReceiver::class, MyReceiver::class);
```

```php
use XerAds\CmsBridge\Contracts\ArticleReceiver;
use XerAds\CmsBridge\Data\IncomingArticle;

class MyReceiver implements ArticleReceiver
{
    public function receive(IncomingArticle $article): array
    {
        // remoteId is the id YOU returned last time, echoed back. Look the
        // post up by your own key; there is no XerAds id to store.
        $post = ($article->remoteId !== null ? Post::find($article->remoteId) : null) ?? new Post;
        $post->fill([...])->save();

        return [
            'id' => $post->id,
            'url' => $article->isPublished() ? route('posts.show', $post) : null,
        ];
    }
}
```

Implement `XerAds\Laravel\Content\Contracts\ValidatesConfiguration` as well
and "Test connection" runs your own checks too. A subclass of
`EloquentArticleReceiver` is checked only when it implements that interface,
since it may store articles somewhere other than the configured model.

#### The response contract

Return `id` and `url`. Both are optional; both are worth returning.

**`id`** comes back on the next sync as `cms_post_id`. Returning a stable one is
what makes a re-sync edit the post instead of publishing a second copy. This is
the single most common way an integration like this goes wrong.

**`url`** becomes the article's public link in XerAds: what "View live" opens,
and the address later articles use when they link to this one. Return it only
once the post is public. Returning `null` for a draft is honest; XerAds keeps
the link it already had rather than recording one that 404s.

#### How requests are verified

Every request carries `X-XerAds-Timestamp` and `X-XerAds-Signature`, where the
signature is:

```
hash_hmac('sha256', timestamp . '.' . raw_request_body, secret)
```

Over the **raw body**, not a re-encoded version of the parsed payload. Those two
strings differ for any article containing a URL or a non-ASCII character — which
is all of them — so a verifier that re-encodes will reject everything. If you
write your own check, read `$request->getContent()`.

A timestamp more than five minutes from your server's clock is rejected, which
bounds how long a captured request stays useful. Adjust
`legacy.timestamp_tolerance` if your clocks drift.

#### What XerAds sends

```json
{
  "title": "Panduan SEO 2026",
  "seo_title": "Panduan SEO 2026",
  "content": "<p>…</p>",
  "slug": "panduan-seo-2026",
  "meta_description": "…",
  "keywords": ["seo"],
  "image_url": "https://…",
  "status": "draft",
  "cms_post_id": "42"
}
```

`status` is `draft` or `publish`. `cms_post_id` is the id you returned last
time, or `null` on the first sync. A connection test carries `"test": true` and
is never stored.

## Security

Please report a vulnerability privately, to support@xerads.id with
"Security" in the subject, not in a public issue. Include the package version
(`php artisan xerads:status`) and the steps to reproduce it. You will get an
answer within a few working days, and a fix is released before the issue is
made public.

What the package does to keep a site safe, in short: every delivery is
signed, timestamped and checked against the site's own key; the key is stored
encrypted and never printed; article HTML is cleaned before it is stored;
outbound requests go only to public https addresses checked before each
request; full addresses are built from `site.url` or `APP_URL`, never from the
request's Host header; and the 404 monitor never stores a visitor's IP address
or user agent.

## Development

```bash
composer test      # the test suite
composer analyse   # static analysis, level 6
composer format    # code style
```

The shared contract fixtures in `tests/Fixtures/contract/` are the same files
XerAds tests its sending side against; change them on both sides together.

## License

MIT. See [LICENSE](LICENSE).
