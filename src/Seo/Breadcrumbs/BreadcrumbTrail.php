<?php

namespace XerAds\Laravel\Seo\Breadcrumbs;

/**
 * The page's breadcrumbs, after the home page: one list feeds both
 * `<x-xerads::breadcrumbs/>` and the `BreadcrumbList` in the structured data,
 * so the two never disagree.
 *
 * One per request (a scoped binding), so a long-running worker never carries
 * one page's trail into the next. The blog fills it itself (Blog, category,
 * article); elsewhere `Xerads::breadcrumbs()->push('Products', '/products')`.
 */
final class BreadcrumbTrail
{
    /** @var list<array{name: string, url: string|null}> */
    private array $items = [];

    /** @param  string|null  $url  a path or an address on this site; null for the current page */
    public function push(string $name, ?string $url = null): self
    {
        $name = trim($name);

        if ($name !== '') {
            $this->items[] = ['name' => $name, 'url' => $url];
        }

        return $this;
    }

    /** @return list<array{name: string, url: string|null}> */
    public function items(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function clear(): self
    {
        $this->items = [];

        return $this;
    }
}
