# XerAds CMS Bridge

Receive articles published from XerAds into a Laravel site.

XerAds can already POST an article to any URL you give it — this package is that
URL, written once, so you are not re-deriving the contract from a payload dump.
It verifies the request really came from XerAds, writes the article into the
model you already have, and answers with the two fields XerAds needs back.

## Install

```bash
composer require xerads/cms-bridge
```

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

Press **Test connection**. It posts a flagged, obviously fake article; this
package answers it and stores nothing.

That is the whole install. The route registers itself.

## Mapping it to your schema

No two sites name these columns the same way, so none of them are assumed.
Publish the config and edit the map:

```bash
php artisan vendor:publish --tag=xerads-cms-config
```

```php
'fields' => [
    'title'            => 'judul',
    'content'          => 'isi',
    'slug'             => 'slug',
    'meta_description' => null,   // not written — no column needed
    'status'           => 'state',
],

'status_map' => [
    'draft'   => 0,
    'publish' => 1,
],
```

A field mapped to `null` is skipped, so a site without that column needs no
migration.

## When the default is not enough

Bind your own receiver. You keep the signature verification, the connection-test
handling and the response contract; you decide what "store an article" means.

```php
// AppServiceProvider::register()
$this->app->bind(\XerAds\CmsBridge\Contracts\ArticleReceiver::class, MyReceiver::class);
```

```php
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
            'url' => $post->is_published ? route('posts.show', $post) : null,
        ];
    }
}
```

## The response contract

Return `id` and `url`. Both are optional; both are worth returning.

**`id`** comes back on the next sync as `cms_post_id`. Returning a stable one is
what makes a re-sync edit the post instead of publishing a second copy. This is
the single most common way an integration like this goes wrong.

**`url`** becomes the article's public link in XerAds: what "View live" opens,
and the address later articles use when they link to this one. Return it only
once the post is public. Returning `null` for a draft is honest; XerAds keeps
the link it already had rather than recording one that 404s.

## How requests are verified

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
bounds how long a captured request stays useful. Adjust with
`timestamp_tolerance` if your clocks drift.

An unset `XERADS_CMS_SECRET` makes the route answer 500 rather than accepting
unsigned requests: a half-finished install should be loud, not open.

## What XerAds sends

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
must not be stored.
