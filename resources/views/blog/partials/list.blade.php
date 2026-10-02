{{-- A page of article cards and the links to the other pages. --}}
@if ($articles->isEmpty())
    <p class="xerads-empty">{{ __('xerads::blog.empty') }}</p>
@else
    <ol class="xerads-cards">
        @foreach ($articles as $article)
            @include('xerads::blog.partials.card', ['article' => $article, 'locale' => $article->language ?? app()->getLocale()])
        @endforeach
    </ol>

    {{ $articles->links('xerads::blog.partials.pagination') }}
@endif
