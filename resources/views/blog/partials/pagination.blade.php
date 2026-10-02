{{--
    Newer and older pages. The first page is linked without `?page=1`, so it
    has one address only.
--}}
@if ($paginator->hasPages())
    <nav class="xerads-pagination" aria-label="{{ __('xerads::blog.pagination') }}">
        @if ($paginator->onFirstPage())
            <span></span>
        @else
            <a href="{{ $paginator->currentPage() === 2 ? $paginator->path() : $paginator->previousPageUrl() }}" rel="prev">{{ __('xerads::blog.newer') }}</a>
        @endif

        <span>{{ __('xerads::blog.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('xerads::blog.older') }}</a>
        @else
            <span></span>
        @endif
    </nav>
@endif
