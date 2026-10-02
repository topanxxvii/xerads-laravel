{{-- The table of contents: every H2 and H3 of the body, linked by id. --}}
@if (! empty($article->toc))
    <nav class="xerads-toc" aria-labelledby="xerads-toc-title">
        <p id="xerads-toc-title"><strong>{{ __('xerads::blog.toc', [], $locale) }}</strong></p>
        <ol>
            @foreach ($article->toc as $entry)
                <li class="xerads-toc-level-{{ (int) ($entry['level'] ?? 2) }}"><a href="#{{ $entry['id'] }}">{{ $entry['text'] }}</a></li>
            @endforeach
        </ol>
    </nav>
@endif
