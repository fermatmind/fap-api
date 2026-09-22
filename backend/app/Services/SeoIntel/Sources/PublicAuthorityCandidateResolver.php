<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Sources;

use App\Services\SeoIntel\UrlTruthInventoryRecord;
use RuntimeException;

/** Resolve overlapping source records without inventing publication authority. */
final class PublicAuthorityCandidateResolver
{
    /** @param list<UrlTruthInventoryRecord> $records @return list<UrlTruthInventoryRecord> */
    public static function resolve(array $records): array
    {
        $unique = [];
        foreach ($records as $record) {
            $key = $record->locale.'|'.rtrim(trim($record->canonicalUrl), '/');
            $previous = $unique[$key] ?? null;
            if ($previous === null) {
                $unique[$key] = $record;

                continue;
            }
            $formal = self::formal($record);
            $previousFormal = self::formal($previous);
            if ($formal && $previousFormal && self::identity($record) !== self::identity($previous)) {
                throw new RuntimeException('PUBLIC_AUTHORITY_IDENTITY_CONFLICT');
            }
            if ($formal && ! $previousFormal) {
                $unique[$key] = $record;
            }
        }
        ksort($unique);

        return array_values($unique);
    }

    private static function formal(UrlTruthInventoryRecord $record): bool
    {
        return in_array(strtolower($record->authorityStatus), ['active', 'published', 'published_approved'], true);
    }

    private static function identity(UrlTruthInventoryRecord $record): array
    {
        return [$record->pageEntityType, $record->entityIdOrSlug, $record->sourceAuthority,
            $record->entitySource, $record->indexabilityState, $record->isPrivateFlow,
            $record->metadata['redirect_only'] ?? false, $record->metadata['canonical_self'] ?? true,
            $record->metadata['robots'] ?? null, $record->attributes, $record->metadata];
    }
}
