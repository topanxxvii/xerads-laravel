{{-- When it was published, how long it takes to read, its category and author. --}}
@php($separate = false)
<p class="xerads-meta">
    @if ($article->published_at)
        <time datetime="{{ $article->published_at->toAtomString() }}">{{ $article->published_at->copy()->locale($locale)->translatedFormat('j F Y') }}</time>
        @php($separate = true)
    @endif
    @if ($article->reading_time > 0)
        <span>{{ $separate ? '· ' : '' }}{{ __('xerads::blog.reading_time', ['minutes' => $article->reading_time], $locale) }}</span>
        @php($separate = true)
    @endif
    @if ($article->primaryCategory && $article->primaryCategory->url())
        <span>{{ $separate ? '· ' : '' }}<a href="{{ $article->primaryCategory->url() }}">{{ $article->primaryCategory->name }}</a></span>
        @php($separate = true)
    @endif
    @if (is_string($article->author['name'] ?? null) && $article->author['name'] !== '')
        <span>{{ $separate ? '· ' : '' }}{{ __('xerads::blog.by', ['name' => $article->author['name']], $locale) }}</span>
    @endif
</p>
