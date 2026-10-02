<?php

namespace XerAds\Laravel\Seo\Contracts;

use XerAds\Laravel\Seo\Models\SeoMeta;

/**
 * A model that carries XerAds SEO data. Implemented by HasXeradsSeo; the head
 * tags rendered in a later release read it through this interface, so a site
 * can also supply SEO data from somewhere else entirely.
 */
interface ProvidesSeo
{
    public function xeradsSeo(): ?SeoMeta;
}
