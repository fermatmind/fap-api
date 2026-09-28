<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;

final class ContentPageSourceTargetSnapshot
{
    public static function hash(ContentPage $target, bool $lock = false): string
    {
        $query = CmsTranslationRevision::query()->withoutGlobalScopes()
            ->where('org_id', $target->org_id)->where('content_type', 'content_page')
            ->where('content_id', $target->id)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return CanonicalTranslationPayloadHash::hash([
            'row' => $target->getAttributes(),
            'revisions' => $query->get()->map(fn ($revision): array => $revision->getAttributes())->all(),
        ]);
    }
}
