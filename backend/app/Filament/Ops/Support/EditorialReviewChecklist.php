<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Services\Cms\ArticlePublishService;

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

        $bodyOnly = false;
        if ($type === 'article' && $record instanceof Article && $record->is_public
            && $record->published_revision_id !== null
            && (int) $record->working_revision_id !== (int) $record->published_revision_id) {
            $import = ArticleEditorialPackageImport::withoutGlobalScopes()
                ->where('article_id', $record->id)->latest('id')->first();
            $bodyOnly = app(ArticlePublishService::class)->isBodyOnlyUpdate(
                $record, $record->workingRevision, $import, (int) $record->published_revision_id
            );
        }
        if (! $bodyOnly && ! filled(data_get($record, 'excerpt'))) {
            $missing[] = 'excerpt';
        }

        if ($bodyOnly) {
            return $missing;
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
