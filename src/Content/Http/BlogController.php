<?php

namespace XerAds\Laravel\Content\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Content\Models\Tag;
use XerAds\Laravel\Seo\Redirects\Redirect;
use XerAds\Laravel\Support\Tables;

/**
 * The turnkey blog's public pages: the article list, category and tag
 * archives, and the article itself.
 *
 * Only published articles are shown. An address that is not an article
 * looks in `xerads_redirects` before answering 404, so a renamed article's
 * old address answers 301 and a deleted one 410 even before the redirects
 * middleware of the SEO module exists.
 */
final class BlogController
{
    public function __construct(
        private readonly Tables $tables,
        private readonly Repository $config,
    ) {}

    public function index(Request $request): View
    {
        $articles = $this->paginate(Article::query(), $request);

        return view('xerads::blog.index', [
            'articles' => $articles,
            'locale' => app()->getLocale(),
        ]);
    }

    public function category(Request $request, string $slug): View
    {
        $category = Category::query()->where('slug', $slug)->first() ?? abort(404);
        $articles = $this->paginate(Article::query()->whereHas('categories', fn (Builder $query) => $query->whereKey($category->getKey())), $request, termPage: true);

        return view('xerads::blog.category', [
            'category' => $category,
            'articles' => $articles,
            'locale' => app()->getLocale(),
        ]);
    }

    public function tag(Request $request, string $slug): View
    {
        $tag = Tag::query()->where('slug', $slug)->first() ?? abort(404);
        $articles = $this->paginate(Article::query()->whereHas('tags', fn (Builder $query) => $query->whereKey($tag->getKey())), $request, termPage: true);

        return view('xerads::blog.tag', [
            'tag' => $tag,
            'articles' => $articles,
            'locale' => app()->getLocale(),
        ]);
    }

    public function show(Request $request, string $slug): View|RedirectResponse
    {
        $article = Article::query()->published()->where('slug', $slug)->first();

        if ($article === null) {
            return $this->notAnArticle($request);
        }

        return view('xerads::blog.show', $this->articleData($article, preview: false));
    }

    /**
     * What a page showing one article needs; shared with the preview.
     *
     * @return array<string, mixed>
     */
    public function articleData(Article $article, bool $preview): array
    {
        $article->loadMissing(['categories', 'tags', 'primaryCategory']);

        return [
            'article' => $article,
            'locale' => $article->language ?? app()->getLocale(),
            'preview' => $preview,
            'noindex' => $preview,
        ];
    }

    /**
     * Published articles, newest first. A page past the last one is a 404,
     * not an empty list search engines would index. So is a category or tag
     * page without a published article: its name may come from a draft, and
     * must not be public before the draft is.
     *
     * @param  Builder<Article>  $query
     * @return LengthAwarePaginator<int, Article>
     */
    private function paginate(Builder $query, Request $request, bool $termPage = false): LengthAwarePaginator
    {
        $perPage = max(1, (int) $this->config->get('xerads.content.turnkey.per_page', 12));

        $articles = $query->published()
            ->with('primaryCategory')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        if (($articles->currentPage() > 1 && $articles->currentPage() > $articles->lastPage()) || ($termPage && $articles->total() === 0)) {
            abort(404);
        }

        return $articles;
    }

    /** A 301 or 410 the redirect table holds for this address, else 404. */
    private function notAnArticle(Request $request): RedirectResponse
    {
        $redirect = $this->tables->exists('redirects') ? Redirect::forPath($request->path()) : null;

        if ($redirect === null) {
            abort(404);
        }

        $redirect->recordHit();

        if ($redirect->isGone()) {
            abort(410);
        }

        $target = (string) $redirect->target;
        $target = preg_match('#^https?://#i', $target) === 1 ? $target : url($target);
        $query = $request->getQueryString();

        if ($redirect->preserve_query && $query !== null && $query !== '') {
            $target .= (str_contains($target, '?') ? '&' : '?').$query;
        }

        return redirect()->to($target, in_array($redirect->status, [301, 302, 303, 307, 308], true) ? $redirect->status : 301);
    }
}
