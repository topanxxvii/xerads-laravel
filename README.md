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
  added to any page that needs it.

Pairing a site from the XerAds dashboard, the turnkey blog and the SEO
output arrive in later releases. Until pairing is available, connect a site
through the custom endpoint, or try the paired path locally with
`php artisan xerads:simulate` (see below).

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
