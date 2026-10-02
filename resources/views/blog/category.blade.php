{{-- A category's published articles. --}}
@extends(config('xerads.content.turnkey.layout', 'xerads::layouts.blog'))

@section('title', $articles->currentPage() > 1 ? __('xerads::blog.page_title', ['title' => $category->name, 'page' => $articles->currentPage()]) : $category->name)

@section(config('xerads.content.turnkey.section', 'content'))
    <div class="xerads-category">
        <p class="xerads-meta">{{ __('xerads::blog.category') }}</p>
        <h1>{{ $category->name }}</h1>

        @if ($category->description)
            <p>{{ $category->description }}</p>
        @endif

        @include('xerads::blog.partials.list', ['articles' => $articles])
    </div>
@endsection
