{{--
    The turnkey blog's page: a complete HTML document with a few styles a site
    can override (every class starts with "xerads-"), or replace with its own
    layout (xerads.content.turnkey.layout). Blog pages fill `title` and the
    section named by xerads.content.turnkey.section.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale ?? app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('xerads::blog.title'))</title>
    @if (! empty($noindex))
        <meta name="robots" content="noindex, nofollow">
    @endif
    {{-- The description, canonical, Open Graph and structured data tags arrive here with the SEO release. --}}
    @stack('xerads-head')
    <style>
        .xerads-blog { margin: 0; font: 1.0625rem/1.65 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1f2328; background: #fff; }
        .xerads-site, .xerads-main { max-width: 46rem; margin: 0 auto; padding: 1rem 1.25rem; }
        .xerads-site { display: flex; gap: 1.25rem; font-weight: 600; }
        .xerads-blog a { color: inherit; text-underline-offset: .15em; }
        .xerads-blog img { max-width: 100%; height: auto; }
        .xerads-blog h1 { font-size: 2rem; line-height: 1.25; margin: .5rem 0; }
        .xerads-meta, .xerads-tags, .xerads-pagination { font-size: .9rem; opacity: .8; }
        .xerads-cards { list-style: none; padding: 0; }
        .xerads-card { margin: 0 0 2rem; }
        .xerads-card h2 { margin: .5rem 0 .25rem; font-size: 1.35rem; }
        .xerads-featured { margin: 1.5rem 0; }
        .xerads-featured figcaption { font-size: .85rem; opacity: .75; }
        .xerads-toc { border-left: 3px solid #d0d7de; padding: .25rem 1rem; margin: 1.5rem 0; }
        .xerads-toc ol { margin: .25rem 0; padding-left: 1.25rem; }
        .xerads-toc-level-3 { margin-left: 1rem; }
        .xerads-notice { padding: .75rem 1rem; background: #fff8c5; border: 1px solid #d4a72c; }
        .xerads-pagination { display: flex; gap: 1.25rem; justify-content: space-between; }
    </style>
    @stack('xerads-styles')
</head>
<body class="xerads-blog">
    <header class="xerads-site">
        <a href="{{ url('/') }}">{{ config('app.name') }}</a>
        <a href="{{ route('xerads.blog.index') }}">{{ __('xerads::blog.title') }}</a>
    </header>
    <main class="xerads-main">
        @yield(config('xerads.content.turnkey.section', 'content'))
    </main>
    <x-xerads::scripts />
</body>
</html>
