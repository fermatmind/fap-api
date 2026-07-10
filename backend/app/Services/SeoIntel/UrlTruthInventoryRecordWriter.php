<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UrlTruthInventoryRecordWriter
{
    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     */
    public function write(array $records): int
    {
        $bindingIssues = $this->authorityBindingIssues($records);
        if ($bindingIssues !== []) {
            throw new InvalidArgumentException(implode(',', $bindingIssues));
        }

        $connection = DB::connection((string) config('seo_intel.connection', 'seo_intel'));
        $now = now();
        $written = 0;

        foreach ($records as $record) {
            $hash = $record->canonicalUrlHash();

            $connection->table('seo_urls')->updateOrInsert(
                [
                    'canonical_url_hash' => $hash,
                    'locale' => $record->locale,
                ],
                [
                    'canonical_url' => $record->canonicalUrl,
                    'page_entity_type' => $record->pageEntityType,
                    'entity_id_or_slug' => $record->entityIdOrSlug,
                    'cluster' => $record->cluster,
                    'source_authority' => $record->sourceAuthority,
                    'indexability_state' => $record->indexabilityState,
                    'lastmod_at' => $record->lastmodAt,
                    'lastmod_source' => $record->lastmodSource,
                    'is_private_flow' => false,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'metadata_json' => json_encode($record->metadata, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            if ($record->entityIdOrSlug === null || $record->entityIdOrSlug === '') {
                $written++;

                continue;
            }

            $connection->table('seo_url_entities')->updateOrInsert(
                [
                    'canonical_url_hash' => $hash,
                    'locale' => $record->locale,
                    'page_entity_type' => $record->pageEntityType,
                    'entity_id_or_slug' => $record->entityIdOrSlug,
                ],
                [
                    'entity_source' => $record->entitySource,
                    'authority_status' => $record->authorityStatus,
                    'source_updated_at' => $record->sourceUpdatedAt,
                    'attributes_json' => json_encode($record->attributes, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $written++;
        }

        return $written;
    }

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @return list<string>
     */
    public function authorityBindingIssues(array $records): array
    {
        $articleIndexes = [];
        foreach ($records as $index => $record) {
            if ($record->pageEntityType === UrlTruthHandoffArtifact::ARTICLE_PAGE_ENTITY_TYPE) {
                $articleIndexes[$index] = $record;
            }
        }

        if ($articleIndexes === []) {
            return [];
        }

        $authoritativeKeys = [];
        foreach ((new BackendAuthorityUrlTruthSource)->candidates() as $candidate) {
            if ($candidate->pageEntityType === UrlTruthHandoffArtifact::ARTICLE_PAGE_ENTITY_TYPE) {
                $authoritativeKeys[$this->bindingKey($candidate)] = true;
            }
        }

        $issues = [];
        foreach ($articleIndexes as $index => $record) {
            if (! isset($authoritativeKeys[$this->bindingKey($record)])) {
                $issues[] = 'candidate_not_bound_to_backend_authority:'.$index;
            }
        }

        return $issues;
    }

    private function bindingKey(UrlTruthInventoryRecord $record): string
    {
        return hash('sha256', json_encode([
            $record->canonicalUrl,
            $record->locale,
            $record->pageEntityType,
            $record->entityIdOrSlug,
            $record->sourceAuthority,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
