<?php

namespace XerAds\Laravel\Content\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use XerAds\Laravel\Content\Models\Article;
use XerAds\Laravel\Content\Models\Category;
use XerAds\Laravel\Content\Models\Tag;
use XerAds\Laravel\Seo\HeadManager;
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
    /*
     * No constructor dependencies: the head, the trail and the table checks
     * are one per request, and a controller can outlive one under a
     * long-running worker. Each is resolved where it is used.
     */

    public function index(Request $request): View
    {
        $articles = $this->paginate(Article::query(), $request);

        $this->head()->page('archive');
        $this->head()->breadcrumbs()->push((string) __('xerads::blog.title'));

        return view('xerads::blog.index', [
            'articles' => $articles,
            'locale' => app()->getLocale(),
        ]);
    }

    public function category(Request $request, string $slug): View
    {
        $category = Category::query()->where('slug', $slug)->first() ?? abort(404);
        $articles = $this->paginate(Article::query()->whereHas('categories', fn (Builder $query) => $query->whereKey($category->getKey())), $request, termPage: true);

        $this->head()->for($category)->page('category', term: $category->name);
        $this->head()->breadcrumbs()
            ->push((string) __('xerads::blog.title'), route('xerads.blog.index'))
            ->push($category->name);

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

        $this->head()->for($tag)->page('tag', term: $tag->name);
        $this->head()->breadcrumbs()
            ->push((string) __('xerads::blog.title'), route('xerads.blog.index'))
            ->push($tag->name);

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
        $article->loadMissing(['categories', 'tags', 'primaryCategory', 'seoMeta']);

        $head = $this->head()->for($article)->page('article')->preview($preview);
        $trail = $head->breadcrumbs()->push((string) __('xerads::blog.title', [], $article->language), route('xerads.blog.index'));

        if ($article->primaryCategory !== null) {
            $trail->push($article->primaryCategory->name, $article->primaryCategory->url());
        }

        $trail->push($article->displayTitle());

        return [
            'article' => $article,
            'locale' => $article->language ?? app()->getLocale(),
            'preview' => $preview,
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
        $perPage = max(1, (int) config('xerads.content.turnkey.per_page', 12));

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

    private function head(): HeadManager
    {
        return app(HeadManager::class);
    }

    /** A 301 or 410 the redirect table holds for this address, else 404. */
    private function notAnArticle(Request $request): RedirectResponse
    {
        $redirect = app(Tables::class)->exists('redirects') ? Redirect::forPath($request->path()) : null;

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
