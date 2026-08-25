<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Services\SeoIntel\PageFamily\PageFamilyClassifier;
use App\Services\SeoIntel\UrlTruthInventoryRecord;

final class EffectivePublicUrlEvaluator
{
    public function __construct(
        private readonly ?PageFamilyClassifier $classifier = null,
    ) {}

    /** @return array<string,mixed> */
    public function evaluate(UrlTruthInventoryRecord $record): array
    {
        $classification = ($this->classifier ?? new PageFamilyClassifier)->classify([
            'canonical_url' => $record->canonicalUrl,
            'locale' => $record->locale,
            'page_entity_type' => $record->pageEntityType,
            'entity_source' => $record->entitySource,
            'source_authority' => $record->sourceAuthority,
            'authority_status' => $record->authorityStatus,
            'indexability_state' => $record->indexabilityState,
            'is_private_flow' => $record->isPrivateFlow,
        ]);

        $metadata = $record->metadata;
        $reasons = [];
        if (! in_array(strtolower($record->authorityStatus), ['active', 'published', 'published_approved'], true)) {
            $reasons[] = 'authority_not_current';
        }
        if ($record->isPrivateFlow || ($metadata['private_flow'] ?? false) === true) {
            $reasons[] = 'private_flow';
        }
        if (strtolower($record->indexabilityState) !== 'indexable') {
            $reasons[] = 'not_indexable';
        }
        if (str_contains(strtolower((string) ($metadata['robots'] ?? 'index')), 'noindex')) {
            $reasons[] = 'robots_noindex';
        }
        if (($metadata['redirect_only'] ?? false) === true || ($metadata['canonical_self'] ?? true) === false) {
            $reasons[] = 'not_current_canonical';
        }
        if (($classification['classification_status'] ?? null) !== 'classified') {
            $reasons[] = 'page_family_'.(string) ($classification['classification_status'] ?? 'unclassified');
        }

        $revision = $this->authorityRevision($record);
        if ($revision === null) {
            $reasons[] = 'authority_revision_untraceable';
        }

        return [
            'effective_public' => $reasons === [],
            'blocking_reasons' => array_values(array_unique($reasons)),
            'family_id' => (string) ($classification['family_id'] ?? 'unclassified'),
            'classification_status' => (string) ($classification['classification_status'] ?? 'unclassified'),
            'policy_hash' => (string) ($classification['policy_hash'] ?? ''),
            'authority_revision' => $revision,
        ];
    }

    private function authorityRevision(UrlTruthInventoryRecord $record): ?string
    {
        foreach (['authority_revision', 'published_revision_id', 'source_revision', 'revision'] as $key) {
            $value = trim((string) ($record->attributes[$key] ?? $record->metadata[$key] ?? ''));
            if ($value !== '') {
                return hash('sha256', $key.'|'.$value);
            }
        }

        if ($record->sourceUpdatedAt === null && $record->lastmodAt === null && $record->metadata === [] && $record->attributes === []) {
            return null;
        }

        return hash('sha256', json_encode([
            'source_updated_at' => $record->sourceUpdatedAt?->toIso8601String(),
            'lastmod_at' => $record->lastmodAt?->toIso8601String(),
            'metadata' => $record->metadata,
            'attributes' => $record->attributes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
