{{--
    One article. Its only H1 is the headline XerAds moved out of the body (the
    title when there is none); the body's own headings start at H2. The body
    goes through <x-xerads::content>, which expands widget placeholders.
--}}
@extends(config('xerads.content.turnkey.layout', 'xerads::layouts.blog'))

@section('title', $article->title)

@section(config('xerads.content.turnkey.section', 'content'))
    <article class="xerads-article" lang="{{ $locale }}">
        @if ($preview)
            <p class="xerads-notice" role="status">{{ __('xerads::blog.preview_notice', [], $locale) }}</p>
        @endif

        <header>
            <h1>{{ $article->displayTitle() }}</h1>

            @include('xerads::blog.partials.article-meta', ['article' => $article, 'locale' => $locale])
        </header>

        @if ($article->featured_image_url)
            <figure class="xerads-featured">
                <img src="{{ $article->featured_image_url }}"
                     alt="{{ $article->featured_image_alt ?? '' }}"
                     @if ($article->featured_image_width && $article->featured_image_height)
                         width="{{ $article->featured_image_width }}" height="{{ $article->featured_image_height }}"
                     @endif
                     fetchpriority="high">
                @if ($article->featured_image_caption)
                    <figcaption>{{ $article->featured_image_caption }}</figcaption>
                @endif
            </figure>
        @endif

        @include('xerads::blog.partials.toc', ['article' => $article, 'locale' => $locale])

        <div class="xerads-body">
            <x-xerads::content :for="$article" />
        </div>

        @if ($article->tags->isNotEmpty())
            <p class="xerads-tags">
                {{ __('xerads::blog.tags', [], $locale) }}:
                @foreach ($article->tags as $tag)
                    <a href="{{ $tag->url() }}" rel="tag">{{ $tag->name }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif
    </article>
@endsection
