<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;

final class UnlinkedPolicyContentPagePair
{
    public static function matches(ContentPage $source, ContentPage $target, bool $lock): bool
    {
        $pairs = [
            55 => [53, 'methodology', 'big5-v2-e34761eea2865c6ce6a0cc7d09897877215e4fd6'],
            56 => [54, 'source-review-policy', 'big5-v2-795d661fc1241f4311c1f6ce4f5ba082f74a5dac'],
        ];
        $pair = $pairs[(int) $source->id] ?? null;
        if ($pair !== [(int) $target->id, (string) $source->slug, (string) $source->translation_group_id]
            || $source->translation_status !== ContentPage::TRANSLATION_STATUS_SOURCE
            || $target->translation_status !== ContentPage::TRANSLATION_STATUS_SOURCE
            || $target->source_locale !== 'en' || $target->source_content_id !== null
            || $target->translated_from_version_hash !== null || $target->working_revision_id !== null
            || $target->published_revision_id !== null || $target->is_indexable
            || ! str_contains((string) $target->content_md, 'Draft candidate')) {
            return false;
        }
        $query = CmsTranslationRevision::query()->withoutGlobalScopes()
            ->where('content_type', 'content_page')->where('content_id', $target->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return ! $query->exists();
    }
}
