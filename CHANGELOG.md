# Changelog

All notable changes to `xerads/laravel` are listed here. The package follows
semantic versioning: a minor release adds features, a patch release fixes
them, and only a major release changes what a site has to do.

## Unreleased

Nothing yet.

## 1.0.0 - 2026-10-02

The first release of `xerads/laravel`, which replaces `xerads/cms-bridge`.
It is supported and tested on Laravel 11, 12 and 13 with PHP 8.2 or later,
and on MySQL, MariaDB, PostgreSQL and SQLite. Laravel 14 is declared ahead of
its release: until it ships, CI tests it against its development branch in a
row that is allowed to fail. Laravel 11 no longer gets security fixes from
Laravel, and recent Composer refuses to install its releases unless the
application allows it; upgrading to Laravel 12 or 13 is recommended.

### Connecting a site

- `php artisan xerads:install` publishes the config, runs the migrations and
  prints what to add to `.env` and to your layouts; `xerads:doctor` checks the
  setup and says how to fix what is wrong.
- `php artisan xerads:pair` connects the site to XerAds with the code from the
  dashboard. The site key is stored encrypted and never printed, and can be
  rotated without missing a delivery.
- The site keeps a copy of its SEO settings and redirects, refreshed through
  the scheduler (or after a response on hosts without cron), and reports its
  health to XerAds every hour. `xerads:status` shows the connection at a
  glance.

### Articles

- Articles published in XerAds arrive by a signed webhook: verified, kept in
  order, never applied twice, with their HTML cleaned before it is stored.
- **Turnkey mode:** a complete blog at `/blog`, with categories, tags, signed
  draft previews, a table of contents, stable addresses after publishing, and
  301 and 410 answers when an article moves or is deleted.
- **Mapped mode:** articles go into your own model, column by column, with
  their SEO data kept beside it.
- Article images are copied onto your own disk, so a page never breaks when an
  image is regenerated in XerAds.
- `xerads:simulate` sends a signed sample article through your site, for
  trying it out without the dashboard.

### SEO

- One head for every page (`@xeradsHead` or `<x-xerads::head>`): title,
  description, canonical, robots, Open Graph and `twitter:*` tags,
  verification tags and one JSON-LD graph with breadcrumbs, from the settings
  edited in XerAds. Shared with Inertia pages as a prop.
- Sitemaps with images, robots.txt, llms.txt and IndexNow submissions when an
  article changes.
- The redirects managed in XerAds are applied wherever your site answers 404,
  and a 404 monitor reports the missing paths to the dashboard, without ever
  storing a visitor's IP address.
- `xerads:prune` keeps the package's tables small, daily through the
  scheduler.

### Widgets

- XerAds widgets in an article render on the page, with the loader added only
  where a widget is. Inertia and Livewire navigation are supported, and every
  script carries the page's CSP nonce.

### Upgrading from xerads/cms-bridge

- The old classes, the `POST /api/xerads/articles` route, the `XERADS_CMS_*`
  variables and a published `config/xerads-cms.php` keep working. Remove
  `xerads/cms-bridge`, require `xerads/laravel` and run the migrations.
- The old endpoint behaves differently in a few places: it exists only while
  `XERADS_CMS_SECRET` is set, reads only the signed body, checks your setup on
  "Test connection", answers a repeated request from its record, stores
  anything but `publish` as a draft, keeps a post's image when a delivery has
  none, and answers refusals as JSON. The README lists each change.
