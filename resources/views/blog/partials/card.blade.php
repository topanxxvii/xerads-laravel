{{-- One article in a list. Its title is an H2: the list page has its own H1. --}}
<li class="xerads-card">
    <article lang="{{ $locale }}">
        @if ($article->featured_image_url)
            <a href="{{ $article->url() }}" tabindex="-1" aria-hidden="true">
                <img src="{{ $article->featured_image_url }}" alt=""
                     @if ($article->featured_image_width && $article->featured_image_height)
                         width="{{ $article->featured_image_width }}" height="{{ $article->featured_image_height }}"
                     @endif
                     loading="lazy">
            </a>
        @endif

        <h2><a href="{{ $article->url() }}">{{ $article->title }}</a></h2>

        @include('xerads::blog.partials.article-meta', ['article' => $article, 'locale' => $locale])

        @if ($article->excerpt)
            <p>{{ $article->excerpt }}</p>
        @endif
    </article>
</li>
