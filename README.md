# XerAds for Laravel

`xerads/laravel` connects a Laravel site to XerAds. It is being built to do
three things:

- **Articles.** Receive articles published from XerAds, either into a blog the
  package provides (tables, routes and views) or into a model the site already
  has, mapped column by column.
- **SEO.** On-page tags (title, description, canonical, robots, social cards,
  structured data) and technical SEO (sitemap, robots.txt, redirects, 404
  monitoring, notifying search engines of changed URLs), with the settings
  edited in the XerAds dashboard.
- **Widgets.** XerAds widgets placed in an article render on the page. The
  widget runtime is loaded from XerAds, so new widget types need no package
  update.

## Status

Built so far:

- the original custom endpoint, on the new internals;
- the paired webhook (contract 2): signed deliveries are verified, ordered,
  de-duplicated and written into your own model, with SEO data kept beside it;
- widget rendering: placeholders become widget containers, and the loader is
  added to any page that needs it;
- pairing and sync: `xerads:pair` connects the site, and its SEO settings and
  redirects are kept current;
- the turnkey blog: `/blog` with categories, tags and signed previews, its
  images copied onto the site's own disk;
- on-page SEO: title, description, canonical, robots, Open Graph, `twitter:*`
  and verification tags and one JSON-LD graph per page, from the settings edited
  in XerAds, with breadcrumbs and a table of contents;
- technical SEO: sitemaps, robots.txt, llms.txt, IndexNow, the redirects
  managed in XerAds and a 404 monitor that reports to the dashboard.

## Requirements

- PHP 8.2 or later, with the `dom`, `json` and `xmlwriter` extensions
- Laravel 11, 12 or 13 (Laravel 14 is declared and tested against its
  development branch until it is released)
- MySQL 8.0+, MariaDB 10.6+, PostgreSQL 13+ or SQLite 3.35+

## Install

```bash
composer require xerads/laravel
php artisan migrate
```

The service provider and the `Xerads` facade register themselves. The
migration creates the package's own tables (prefixed `xerads_`); it does not
touch yours.

To change settings, publish the config file:

```bash
php artisan vendor:publish --tag=xerads-config
```

Or let the package walk you through it, then check the result:

```bash
php artisan xerads:install
php artisan xerads:doctor
```

`xerads:install` publishes the config when there is none, runs the
migrations, and prints the lines to add to `.env` and to your layout. It never
edits `.env` and never deletes a file. `xerads:doctor` checks everything that
can stop a delivery (the site key, the webhook route, the tables, your model
and its columns) and exits non-zero when something is broken; `--json` for
scripts.

## Pairing

Add the site in the XerAds dashboard, then run the command it shows, once,
within 30 minutes:

```bash
php artisan xerads:pair xpc_…
```

The site key it receives is stored encrypted in the database and never
printed; every server of the site reads it from there. To keep it in `.env`
instead, `--print-env` prints an `XERADS_SITE_KEY=` line once. Pairing again
(with `--rotate`) replaces the key; the old one keeps working for a day, so
deliveries already on their way still verify. `--show` shows which key the
site holds, by id.

After pairing, the site keeps its copy of its SEO settings and redirects
current: every 15 minutes through the scheduler (with a heartbeat every hour),
at minutes picked from the site id so that sites do not all call XerAds at
once; after a response when the copy is stale on a host without cron; and at
once when XerAds announces a change. A failed sync keeps the last good copy and
retries later, waiting longer after each failure in a row. The after-response
refresh stays off while the application's own test suite runs.

```bash
php artisan xerads:sync          # refresh, if due
php artisan xerads:sync --full   # everything, now
php artisan xerads:sync --report-404   # only the 404 report
php artisan xerads:status        # the connection at a glance (--json for scripts)
```

## Paired deliveries (contract 2)

XerAds sends every paired event to `POST /xerads/v1/webhook` (the prefix is
`xerads.routes.prefix`). The route sits outside every middleware group: the
`web` group's CSRF check would refuse each delivery, and the request's
signature is its authentication. `GET /xerads/v1/status` answers publicly with
the package version, contract and features, and nothing else.

Each delivery is checked for its size (2 MB), its contract, its signature,
its timestamp, its key and its site, then recorded in `xerads_deliveries`: a
retry of a delivery already applied gets the original reply with
`"duplicate": true`, and an event older than one already applied is dropped
with `"stale": true`. An article's HTML is cleaned (an allowlist of article
markup, no scripts, styles or iframes), its h2 and h3 get ids by the same
rule XerAds uses, and its widget placeholders become containers. The article
is then written into your model with the same `xerads.content.mapped`
mapping as the custom endpoint, and its SEO data into `xerads_seo_meta`.

To read the SEO data back, add the trait to your model:

```php
use XerAds\Laravel\Seo\Concerns\HasXeradsSeo;
use XerAds\Laravel\Seo\Contracts\ProvidesSeo;

class Post extends Model implements ProvidesSeo
{
    use HasXeradsSeo;
}

$post->xeradsSeo()?->description;
```

To try it without the dashboard, set `XERADS_SITE_KEY` to any well-formed key
and send a signed sample article through your own HTTP kernel:

```bash
php artisan xerads:simulate                      # article.upsert, published
php artisan xerads:simulate --status=draft
php artisan xerads:simulate --event=ping
php artisan xerads:simulate --fixture=article.json
```

It refuses to run in production unless given `--force`.

To store articles somewhere else, bind your own
`XerAds\Laravel\Content\Contracts\ContentReceiver`. A receiver written for
the custom endpoint (`ArticleReceiver`) keeps receiving paired articles too.

## Turnkey blog

With `XERADS_CONTENT_MODE=turnkey`, the package keeps the blog itself:
articles, categories and tags in its own tables, served at

| Address | Page |
| --- | --- |
| `/blog` | published articles, newest first, 12 a page |
| `/blog/{slug}` | an article |
| `/blog/category/{slug}`, `/blog/tag/{slug}` | a category's or a tag's articles |
| `/blog/preview/{id}?signature=…` | any article, whatever its status, for XerAds to open a draft |

```bash
php artisan xerads:install --mode=turnkey   # publishes the blog's migrations, then migrates
php artisan storage:link                    # article images are copied to the public disk
```

The prefix is `XERADS_BLOG_PREFIX`. Pages print the article's headline as
their only H1, the body with its widgets, a table of contents, and plain
semantic markup with a few overridable styles. They use the package's own
layout, a complete page; set `xerads.content.turnkey.layout` (and `section`)
to show the blog inside yours, or publish the views to change the markup:

```bash
php artisan vendor:publish --tag=xerads-views
```

The layout prints the whole head with `@xeradsHead` (see On-page SEO), and
offers `@stack('xerads-head')` for tags of your own.

How articles behave:

- **Slugs** are unique, and follow XerAds until the article is first
  published. After that the address stays, so a retitled article keeps its
  links (`content.slug.freeze_after_publish`). With freezing off, the article
  moves and its old address answers 301.
- **A draft** answers 404. Its signed preview link, which XerAds stores from
  the reply, shows it with `noindex`. The link does not expire and stops
  working once the article is deleted.
- **Unpublished** articles answer 404; **deleted** ones are soft-deleted and
  their address answers 410. Publishing the article again brings it back.
- **Images** are copied onto `XERADS_MEDIA_DISK` by a queued job
  (`xerads.queue.*`; with the `sync` queue driver, after the webhook has
  answered): https addresses only, JPEG, PNG, WebP, GIF or AVIF up to 10 MB,
  each address once. Until then pages show XerAds' address; after, the copy,
  so regenerating an image in XerAds never breaks a live page. Mapped sites
  get the same, in the mapped image and content columns.

The blog's routes are registered after your own, so a route of yours under
the prefix (`/blog/feed`, `/blog/search`) wins over `/blog/{slug}`, with or
without `route:cache`, and no article is given a slug such a route answers.

`php artisan xerads:simulate` sends a sample article to the blog on a
turnkey site; `--mode=mapped` sends it to your mapped model instead.

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
keep `?page=`, from page 2 on; elsewhere it is ignored. With Inertia
installed, the head is shared as the `xerads` prop on every Inertia response;
keep `@xeradsHead` in the root view for the first, crawlable load. Without a
pairing, SEO runs on your config alone.

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
(`/wp-login.php`, `/.env`, `*.php`…), missing assets (images, scripts, fonts)
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

## Widgets

A XerAds widget is placed in an article as
`[xerads_widget id="w_…" lang="id"]`, or as any embed code the dashboard
hands out; the package stores the placeholder and renders the container the
widget runtime mounts. The runtime is always loaded from XerAds, never
copied into your site, so new widget types need no package update.

With the default `content_format` (`html`), the stored body already holds the
containers, and `{!! $post->content !!}` is all a template needs: a
middleware adds the loader to any page with a widget on it (never to paths in
`xerads.widgets.inject_except`, the `admin` area by default).

Otherwise, in Blade:

```blade
<x-xerads::widget id="w_k3v9q2m8x1c4b7na" lang="id" />
<x-xerads::content :html="$post->content" :for="$post" />  {{-- content_format = shortcode --}}

{{-- In the layout, just before </body> --}}
<x-xerads::scripts />
```

`<x-xerads::scripts />` prints the loader once, only on pages that rendered a
widget, with the page's CSP nonce when it has one. On an Inertia or Livewire
site whose pages change without a reload, use `<x-xerads::scripts spa />`: it
always includes the loader and asks it to look for widgets again after each
navigation. A strict Content Security Policy must allow
`https://widgets.xerads.id` in `script-src`, `frame-src` and `connect-src`.

## Upgrading from xerads/cms-bridge / legacy custom endpoint

`xerads/laravel` replaces `xerads/cms-bridge`. The class names, the route, the
environment variables and a published `config/xerads-cms.php` all keep working.

```bash
composer remove xerads/cms-bridge
composer require xerads/laravel
php artisan migrate
```

Nothing else is required. If you registered
`XerAds\CmsBridge\CmsBridgeServiceProvider` by hand, it still works and can be
removed; the new provider is discovered automatically.

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

## The custom endpoint

### Setup

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

### Mapping it to your schema

No two sites name these columns the same way, so none of them are assumed.
Publish the config and edit `content.mapped`:

```php
'fields' => [
    'title'            => 'judul',
    'content'          => 'isi',
    'slug'             => 'slug',
    'meta_description' => null,   // not written — no column needed
    'status'           => 'state',
    'keywords'         => 'tags',
],

// Written only when a post is created.
'defaults' => [
    'user_id' => 1,
],

'status_map' => [
    'draft'   => 0,
    'publish' => 1,
],
```

A field mapped to `null` is skipped, so a site without that column needs no
migration.

The public link is built from the named route in `public_route`. Its
parameter (`public_route_parameter`, default `slug`) is filled from the saved
slug column, from `public_route_column` when set, or from the primary key when
the parameter is not `slug`.

### When the default is not enough

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

### The response contract

Return `id` and `url`. Both are optional; both are worth returning.

**`id`** comes back on the next sync as `cms_post_id`. Returning a stable one is
what makes a re-sync edit the post instead of publishing a second copy. This is
the single most common way an integration like this goes wrong.

**`url`** becomes the article's public link in XerAds: what "View live" opens,
and the address later articles use when they link to this one. Return it only
once the post is public. Returning `null` for a draft is honest; XerAds keeps
the link it already had rather than recording one that 404s.

### How requests are verified

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

### What XerAds sends

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

## Development

```bash
composer test      # Pest
composer analyse   # PHPStan (level 6, with Larastan)
composer format    # Pint
```

The shared contract fixtures in `tests/Fixtures/contract/` are the same files
XerAds tests its sending side against; change them on both sides together.

## License

MIT
