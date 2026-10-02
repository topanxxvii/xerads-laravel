{{-- The article list: published articles, newest first. --}}
@extends(config('xerads.content.turnkey.layout', 'xerads::layouts.blog'))

@section('title', $articles->currentPage() > 1 ? __('xerads::blog.page_title', ['title' => __('xerads::blog.title'), 'page' => $articles->currentPage()]) : __('xerads::blog.title'))

@section(config('xerads.content.turnkey.section', 'content'))
    <div class="xerads-index">
        <h1>{{ __('xerads::blog.title') }}</h1>

        @include('xerads::blog.partials.list', ['articles' => $articles])
    </div>
@endsection
