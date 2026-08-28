<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class UrlTruthInventoryRecordWriter
{
    public const AGENT_INVOCABLE = false;

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
        $schema = Schema::connection((string) config('seo_intel.connection', 'seo_intel'));
        $hardenedSchema = $schema->hasColumn('seo_url_entities', 'current_binding_key')
            && $schema->hasColumn('seo_urls', 'authority_revision');

        return $connection->transaction(function () use ($connection, $hardenedSchema, $records): int {
            foreach ($records as $record) {
                $this->writeRecord($connection, $record, $hardenedSchema);
            }

            return count($records);
        });
    }

    private function writeRecord(mixed $connection, UrlTruthInventoryRecord $record, bool $hardenedSchema): void
    {
        $now = now();
        $hash = $record->canonicalUrlHash();
        $isCurrent = $this->isCurrent($record);
        $identityKey = $record->entityIdOrSlug === null || $record->entityIdOrSlug === ''
            ? null
            : $this->currentBindingKey($record);
        $trace = $this->traceability($record);

        if ($isCurrent && $identityKey !== null) {
            $this->retireOldCanonicals($connection, $record, $hash, $now, $hardenedSchema);
        }

        $existingUrl = $connection->table('seo_urls')
            ->where('canonical_url_hash', $hash)
            ->where('locale', $record->locale)
            ->first();
        $urlValues = [
            'canonical_url' => $record->canonicalUrl,
            'page_entity_type' => $record->pageEntityType,
            'entity_id_or_slug' => $record->entityIdOrSlug,
            'cluster' => $record->cluster,
            'source_authority' => $record->sourceAuthority,
            'indexability_state' => $record->indexabilityState,
            'lastmod_at' => $record->lastmodAt,
            'lastmod_source' => $record->lastmodSource,
            'is_private_flow' => $record->isPrivateFlow,
            'last_seen_at' => $now,
            'metadata_json' => json_encode($record->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        if ($hardenedSchema) {
            $urlValues += $trace;
        }

        if ($existingUrl === null) {
            $connection->table('seo_urls')->insert($urlValues + [
                'canonical_url_hash' => $hash,
                'locale' => $record->locale,
                'first_seen_at' => $now,
                'created_at' => $now,
            ]);
        } else {
            $connection->table('seo_urls')->where('id', $existingUrl->id)->update($urlValues);
        }

        if ($identityKey === null) {
            return;
        }

        $existingEntityQuery = $connection->table('seo_url_entities')
            ->where('canonical_url_hash', $hash)
            ->where('locale', $record->locale)
            ->where('page_entity_type', $record->pageEntityType)
            ->where('entity_id_or_slug', $record->entityIdOrSlug);
        if ($hardenedSchema) {
            $existingEntityQuery->orderByRaw("CASE WHEN binding_status = 'current' THEN 0 ELSE 1 END");
        }
        $existingEntity = $existingEntityQuery->orderByDesc('id')->first();

        if ($hardenedSchema && $isCurrent) {
            $connection->table('seo_url_entities')
                ->where('current_binding_key', $identityKey)
                ->when($existingEntity !== null, fn ($query) => $query->where('id', '!=', $existingEntity->id))
                ->update([
                    'current_binding_key' => null,
                    'binding_status' => 'superseded_canonical',
                    'retired_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $entityValues = [
            'entity_source' => $record->entitySource,
            'authority_status' => $record->authorityStatus,
            'source_updated_at' => $record->sourceUpdatedAt,
            'attributes_json' => json_encode($record->attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        if ($hardenedSchema) {
            $entityValues += $trace + [
                'binding_status' => $isCurrent ? 'current' : 'retired',
                'current_binding_key' => $isCurrent ? $identityKey : null,
                'superseded_by_id' => null,
                'retired_at' => $isCurrent ? null : $now,
            ];
        }

        if ($existingEntity === null) {
            $entityId = $connection->table('seo_url_entities')->insertGetId($entityValues + [
                'canonical_url_hash' => $hash,
                'locale' => $record->locale,
                'page_entity_type' => $record->pageEntityType,
                'entity_id_or_slug' => $record->entityIdOrSlug,
                'created_at' => $now,
            ]);
        } else {
            $connection->table('seo_url_entities')->where('id', $existingEntity->id)->update($entityValues);
            $entityId = (int) $existingEntity->id;
        }

        if ($hardenedSchema && $isCurrent) {
            $connection->table('seo_url_entities')
                ->where('binding_status', 'superseded_canonical')
                ->where('page_entity_type', $record->pageEntityType)
                ->where('entity_id_or_slug', $record->entityIdOrSlug)
                ->where('locale', $record->locale)
                ->where('id', '!=', $entityId)
                ->update(['superseded_by_id' => $entityId]);
        }
    }

    private function retireOldCanonicals(mixed $connection, UrlTruthInventoryRecord $record, string $newHash, mixed $now, bool $hardenedSchema): void
    {
        $oldUrls = $connection->table('seo_urls')
            ->where('page_entity_type', $record->pageEntityType)
            ->where('entity_id_or_slug', $record->entityIdOrSlug)
            ->where('locale', $record->locale)
            ->where('canonical_url_hash', '!=', $newHash)
            ->where('indexability_state', 'indexable')
            ->get();

        foreach ($oldUrls as $oldUrl) {
            $metadata = json_decode((string) ($oldUrl->metadata_json ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['superseded_canonical'] = true;
            $metadata['superseded_by_hash'] = $newHash;

            $connection->table('seo_urls')->where('id', $oldUrl->id)->update([
                'indexability_state' => 'superseded_canonical',
                'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);

            $entityUpdate = [
                'authority_status' => 'superseded_canonical',
                'updated_at' => $now,
            ];
            if ($hardenedSchema) {
                $entityUpdate += [
                    'binding_status' => 'superseded_canonical',
                    'current_binding_key' => null,
                    'retired_at' => $now,
                ];
            }
            $connection->table('seo_url_entities')
                ->where('canonical_url_hash', $oldUrl->canonical_url_hash)
                ->where('locale', $record->locale)
                ->where('page_entity_type', $record->pageEntityType)
                ->where('entity_id_or_slug', $record->entityIdOrSlug)
                ->update($entityUpdate);
        }
    }

    /** @return array{page_family:string,authority_revision:string,canonical_revision:string} */
    private function traceability(UrlTruthInventoryRecord $record): array
    {
        $evaluation = (new EffectivePublicUrlEvaluator)->evaluate($record);
        $pageFamily = mb_substr((string) ($evaluation['family_id'] ?? $record->pageEntityType), 0, 64);
        $authorityRevision = (string) ($evaluation['authority_revision'] ?? '');
        if ($authorityRevision === '') {
            $authorityRevision = hash('sha256', json_encode([
                $record->sourceAuthority,
                $record->pageEntityType,
                $record->entityIdOrSlug,
                $record->locale,
                $record->sourceUpdatedAt?->toIso8601String() ?? $record->lastmodAt?->toIso8601String() ?? $record->canonicalUrlHash(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return [
            'page_family' => $pageFamily,
            'authority_revision' => $this->revisionHash($authorityRevision),
            'canonical_revision' => $this->revisionHash((string) ($record->metadata['canonical_revision'] ?? $record->canonicalUrlHash())),
        ];
    }

    private function revisionHash(string $revision): string
    {
        $revision = trim($revision);

        return preg_match('/^[a-f0-9]{64}$/', $revision) === 1 ? $revision : hash('sha256', $revision);
    }

    private function currentBindingKey(UrlTruthInventoryRecord $record): string
    {
        return hash('sha256', json_encode([
            $record->pageEntityType,
            $record->entityIdOrSlug,
            $record->locale,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function isCurrent(UrlTruthInventoryRecord $record): bool
    {
        if ($record->isPrivateFlow || $record->indexabilityState !== 'indexable') {
            return false;
        }

        $status = strtolower($record->authorityStatus);
        foreach (['private', 'draft', 'unpublished', 'pending', 'retired', 'superseded', 'blocked', 'noindex'] as $token) {
            if (str_contains($status, $token)) {
                return false;
            }
        }

        return true;
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
