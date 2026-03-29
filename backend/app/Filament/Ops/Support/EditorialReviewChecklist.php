<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class EditorialReviewChecklist
{
    /**
     * @return list<string>
     */
    public static function missing(string $type, object $record): array
    {
        $missing = [];

        if (! filled(data_get($record, 'title'))) {
            $missing[] = 'title';
        }

        if (! filled(data_get($record, 'slug'))) {
            $missing[] = 'slug';
        }

        $bodyField = match ($type) {
            'article' => 'content_md',
            'guide', 'job' => 'body_md',
            default => 'content_md',
        };

        if (! filled(data_get($record, $bodyField))) {
            $missing[] = 'body';
        }

        if (! filled(data_get($record, 'excerpt'))) {
            $missing[] = 'excerpt';
        }

        $seoMeta = data_get($record, 'seoMeta');
        foreach ([
            'seo_title' => 'seo title',
            'seo_description' => 'seo description',
            'canonical_url' => 'canonical url',
            'og_title' => 'og title',
            'og_description' => 'og description',
            'og_image_url' => 'og image',
            'robots' => 'robots',
        ] as $field => $label) {
            if (! filled(data_get($seoMeta, $field))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
