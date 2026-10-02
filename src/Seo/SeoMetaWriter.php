<?php

namespace XerAds\Laravel\Seo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use XerAds\Laravel\Content\Data\ArticlePayload;
use XerAds\Laravel\Content\Data\ProcessedContent;
use XerAds\Laravel\Content\Exceptions\ReceiverMisconfigured;
use XerAds\Laravel\Content\Receivers\MappedModel;
use XerAds\Laravel\Seo\Models\SeoMeta;

/**
 * Stores the SEO data XerAds sent with an article in `xerads_seo_meta`, for
 * the row the article became, in whichever mode.
 *
 * Fields the site locked (`locked_fields`) keep the value set on the site.
 */
final class SeoMetaWriter
{
    /**
     * @throws ReceiverMisconfigured when the row's key does not fit the morph column
     * @throws QueryException for anything else
     */
    public function write(Model $record, ArticlePayload $article, ?ProcessedContent $content): void
    {
        $values = [
            'title' => $article->seoTitle(),
            'description' => $article->seoDescription(),
            'focus_keyword' => $article->seo['focus_keyword'],
            'keywords' => $article->keywords(),
            'canonical_url' => $article->seo['canonical_url'],
            'robots' => $article->seo['robots'] !== [] ? $article->seo['robots'] : null,
            'og' => $article->seo['og'] !== [] ? $article->seo['og'] : null,
            'twitter' => $article->seo['twitter'] !== [] ? $article->seo['twitter'] : null,
            'schema' => ['type' => $article->seo['schema_type']],
            'extras' => [
                'xerads_id' => $article->xeradsId,
                'language' => $article->language,
                'headline' => $article->headline,
                'excerpt' => $content !== null ? $content->excerpt : $article->excerpt,
                'toc' => $content !== null ? $content->toc : $article->toc,
                'word_count' => $content !== null ? $content->wordCount : $article->wordCount,
                'reading_time_minutes' => $content !== null ? $content->readingTimeMinutes : $article->readingTimeMinutes,
                'image' => $article->image,
                'widgets' => $article->widgets,
                'score' => $article->seo['score'],
                'dates' => $article->dates,
            ],
        ];

        try {
            /** @var SeoMeta $meta */
            $meta = $this->query($record)->firstOrNew([]);

            foreach (array_keys($values) as $field) {
                if ($meta->exists && $meta->isLocked($field)) {
                    unset($values[$field]);
                }
            }

            $meta->forceFill($values + [
                'seoable_type' => $record->getMorphClass(),
                'seoable_id' => $record->getKey(),
                'source' => 'xerads',
            ])->save();
        } catch (QueryException $exception) {
            // Only a key of the wrong type is the site's setup; anything else
            // (a race, a lost connection) is retried like any failure.
            if (MappedModel::isTypeMismatch($exception->getMessage())) {
                throw new ReceiverMisconfigured(
                    'SEO data for '.$record::class.' could not be stored: its primary key is not the type xerads_seo_meta expects. Set xerads.database.morph_key_type (int, uuid, ulid or string) to match, before migrating.',
                    'seoable_id',
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /** @return Builder<SeoMeta> */
    public function query(Model $record): Builder
    {
        return SeoMeta::query()
            ->where('seoable_type', $record->getMorphClass())
            ->where('seoable_id', $record->getKey());
    }
}
