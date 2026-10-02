<?php

namespace XerAds\Laravel\Seo;

/**
 * What a page tells search engines: index or not, follow or not, and the
 * preview limits from `robots.default`.
 *
 * Printed as `index, follow, max-snippet:-1, max-image-preview:large,
 * max-video-preview:-1`. A noindexed page carries no preview limits: there
 * is no snippet to limit.
 */
final class RobotsDirectives
{
    public function __construct(
        public readonly bool $index = true,
        public readonly bool $follow = true,
        public readonly ?int $maxSnippet = -1,
        public readonly ?string $maxImagePreview = 'large',
        public readonly ?int $maxVideoPreview = -1,
    ) {}

    /** @param  array<string, mixed>  $default  `robots.default` from the settings */
    public static function fromSettings(array $default): self
    {
        $preview = $default['max_image_preview'] ?? 'large';

        return new self(
            maxSnippet: is_int($default['max_snippet'] ?? null) ? $default['max_snippet'] : -1,
            maxImagePreview: is_string($preview) && in_array($preview, ['none', 'standard', 'large'], true) ? $preview : 'large',
            maxVideoPreview: is_int($default['max_video_preview'] ?? null) ? $default['max_video_preview'] : -1,
        );
    }

    /**
     * Directives as a site writes them: `noindex`, `index, nofollow`, or
     * `['index' => false, 'follow' => true]`. What is not said stays as it is.
     *
     * @param  string|array<string, mixed>  $directives
     */
    public function with(string|array $directives): self
    {
        $index = $this->index;
        $follow = $this->follow;

        if (is_array($directives)) {
            $index = is_bool($directives['index'] ?? null) ? $directives['index'] : $index;
            $follow = is_bool($directives['follow'] ?? null) ? $directives['follow'] : $follow;
        } else {
            foreach (array_map('trim', explode(',', strtolower($directives))) as $directive) {
                match ($directive) {
                    'index', 'all' => $index = true,
                    'noindex' => $index = false,
                    'follow' => $follow = true,
                    'nofollow' => $follow = false,
                    'none' => [$index, $follow] = [false, false],
                    default => null,
                };
            }
        }

        return new self($index, $follow, $this->maxSnippet, $this->maxImagePreview, $this->maxVideoPreview);
    }

    public function noindex(): self
    {
        return $this->with(['index' => false]);
    }

    public function isRestrictive(): bool
    {
        return ! $this->index || ! $this->follow;
    }

    public function toString(): string
    {
        $directives = [$this->index ? 'index' : 'noindex', $this->follow ? 'follow' : 'nofollow'];

        if ($this->index) {
            if ($this->maxSnippet !== null) {
                $directives[] = 'max-snippet:'.$this->maxSnippet;
            }

            if ($this->maxImagePreview !== null) {
                $directives[] = 'max-image-preview:'.$this->maxImagePreview;
            }

            if ($this->maxVideoPreview !== null) {
                $directives[] = 'max-video-preview:'.$this->maxVideoPreview;
            }
        }

        return implode(', ', $directives);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
