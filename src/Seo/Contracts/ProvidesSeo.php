<?php

namespace XerAds\Laravel\Seo\Contracts;

use XerAds\Laravel\Seo\Models\SeoMeta;

/**
 * A model that carries XerAds SEO data. Implemented by HasXeradsSeo. The head
 * (`<x-xerads::head :for="$post" />`, `@xeradsHead($post)` and
 * `Xerads::head()->for($post)`) reads the model's SEO data through this
 * interface, so a site can also supply it from somewhere else entirely.
 */
interface ProvidesSeo
{
    public function xeradsSeo(): ?SeoMeta;
}
