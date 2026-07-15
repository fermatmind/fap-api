<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\VisibleDate;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class BigFiveVisibleDateProjector
{
    public const CONTRACT_VERSION = 'big5-visible-date.v1';

    /** @return array<string, mixed> */
    public function forArticle(Article $article, ?ArticleTranslationRevision $publishedRevision = null): array
    {
        $revisionBelongs = $publishedRevision instanceof ArticleTranslationRevision
            && (int) $publishedRevision->article_id === (int) $article->id;
        $revisionMatches = $revisionBelongs
            && $publishedRevision->revision_status === ArticleTranslationRevision::STATUS_PUBLISHED;
        $published = null;
        if ($article->status === 'published' && (bool) $article->is_public) {
            $published = $this->directDate(
                $revisionMatches && $publishedRevision->published_at !== null
                    ? $publishedRevision->published_at
                    : $article->published_at,
                $revisionMatches && $publishedRevision->published_at !== null
                    ? 'article_translation_revisions.published_at'
                    : 'articles.published_at',
                'cms_publication',
            );
        }
        $reviewed = $revisionMatches
            && $publishedRevision->reviewed_at !== null
            && $publishedRevision->reviewed_by !== null
            ? $this->directDate(
                $publishedRevision->reviewed_at,
                'article_translation_revisions.reviewed_at',
                'manual_review',
                'article_translation_revision:'.(int) $publishedRevision->id.':reviewed_by:'.(int) $publishedRevision->reviewed_by,
            )
            : null;
        $metadata = $revisionBelongs && is_array($publishedRevision->authority_metadata_json)
            ? $publishedRevision->authority_metadata_json
            : [];
        $updatedCandidate = $revisionMatches ? $publishedRevision->updated_at : $article->updated_at;

        return $this->project(
            authoritySurface: 'Article',
            identity: 'article:'.(int) $article->id.':'.(string) $article->locale.':'.(string) $article->slug,
            published: $published,
            reviewed: $reviewed,
            updated: $this->metadataDate(
                $revisionMatches ? $metadata : [],
                'updated_at',
                'editorial_update',
                $updatedCandidate,
                $revisionMatches
                    ? 'article_translation_revisions.updated_at'
                    : 'articles.updated_at',
            ),
            revisionCreatedAt: $revisionBelongs ? $publishedRevision->created_at : null,
            metadata: $metadata,
        );
    }

    /** @return array<string, mixed> */
    public function forPersonalityAsset(
        PersonalityPublicContentAsset $asset,
        ?PersonalityPublicContentAssetRevision $revision = null,
    ): array {
        $metadata = is_array($asset->authority_json) ? $asset->authority_json : [];
        $published = $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (bool) $asset->is_public
            ? $this->directDate(
                $asset->published_at,
                'personality_public_content_assets.published_at',
                'cms_publication',
            )
            : null;
        $reviewed = $this->isCompletedReviewState((string) $asset->review_state)
            ? $this->directDate(
                $asset->last_reviewed_at,
                'personality_public_content_assets.last_reviewed_at',
                'manual_review',
            )
            : null;
        $revisionCreatedAt = $revision instanceof PersonalityPublicContentAssetRevision
            && (int) $revision->asset_id === (int) $asset->id
            ? $revision->created_at
            : null;

        return $this->project(
            authoritySurface: 'PersonalityPublicContentAsset',
            identity: 'personality_public_content_asset:'.(int) $asset->id.':'.(string) $asset->locale.':'.(string) $asset->slug,
            published: $published,
            reviewed: $reviewed,
            updated: $this->metadataDate(
                $metadata,
                'updated_at',
                'editorial_update',
                $asset->updated_at,
                'personality_public_content_assets.updated_at',
            ),
            revisionCreatedAt: $revisionCreatedAt,
            metadata: $metadata,
        );
    }

    /** @return array<string, mixed> */
    public function forTopic(TopicProfile $topic, ?TopicProfileRevision $revision = null): array
    {
        $revisionMatches = $revision instanceof TopicProfileRevision
            && (int) $revision->profile_id === (int) $topic->id;
        $metadata = $revisionMatches && is_array($revision->snapshot_json)
            ? $revision->snapshot_json
            : [];
        $published = $topic->status === TopicProfile::STATUS_PUBLISHED && (bool) $topic->is_public
            ? $this->directDate(
                $topic->published_at,
                'topic_profiles.published_at',
                'cms_publication',
            )
            : null;

        return $this->project(
            authoritySurface: 'Topic',
            identity: 'topic:'.(int) $topic->id.':'.(string) $topic->locale.':'.(string) $topic->slug,
            published: $published,
            reviewed: $this->metadataDate($metadata, 'reviewed_at', 'manual_review'),
            updated: $this->metadataDate(
                $metadata,
                'updated_at',
                'editorial_update',
                $topic->updated_at,
                'topic_profiles.updated_at',
            ),
            revisionCreatedAt: $revisionMatches ? $revision->created_at : null,
            metadata: $metadata,
        );
    }

    /** @return array<string, mixed> */
    public function forLandingSurface(LandingSurface $surface): array
    {
        $metadata = is_array($surface->payload_json) ? $surface->payload_json : [];
        $published = $surface->status === LandingSurface::STATUS_PUBLISHED && (bool) $surface->is_public
            ? $this->directDate(
                $surface->published_at,
                'landing_surfaces.published_at',
                'cms_publication',
            )
            : null;

        return $this->project(
            authoritySurface: 'LandingSurface',
            identity: 'landing_surface:'.(int) $surface->id.':'.(string) $surface->locale.':'.(string) $surface->surface_key,
            published: $published,
            reviewed: $this->metadataDate($metadata, 'reviewed_at', 'manual_review'),
            updated: $this->metadataDate(
                $metadata,
                'updated_at',
                'editorial_update',
                $surface->updated_at,
                'landing_surfaces.updated_at',
            ),
            revisionCreatedAt: null,
            metadata: $metadata,
        );
    }

    /**
     * @param  array{value:string,source_field:string,source_kind:string,authority_ref:?string}|null  $published
     * @param  array{value:string,source_field:string,source_kind:string,authority_ref:?string}|null  $reviewed
     * @param  array{value:string,source_field:string,source_kind:string,authority_ref:?string}|null  $updated
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function project(
        string $authoritySurface,
        string $identity,
        ?array $published,
        ?array $reviewed,
        ?array $updated,
        mixed $revisionCreatedAt,
        array $metadata,
    ): array {
        $visibleDates = [
            'published_at' => $published['value'] ?? null,
            'reviewed_at' => $reviewed['value'] ?? null,
            'updated_at' => $updated['value'] ?? null,
        ];
        $blocked = [];
        foreach ($visibleDates as $field => $value) {
            if ($value === null) {
                $blocked[] = $field.'_authority_missing';
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'authority_surface' => $authoritySurface,
            'identity' => $identity,
            'visible_dates' => $visibleDates,
            'provenance' => [
                'published_at' => $this->withoutValue($published),
                'reviewed_at' => $this->withoutValue($reviewed),
                'updated_at' => $this->withoutValue($updated),
            ],
            'eligibility' => [
                'visible_date_eligible' => array_filter($visibleDates, static fn (?string $value): bool => $value !== null) !== [],
                'published_date_eligible' => $published !== null,
                'reviewed_date_eligible' => $reviewed !== null,
                'updated_date_eligible' => $updated !== null,
                'blocked_reasons' => $blocked,
            ],
            'audit_only_dates' => [
                'revision_created_at' => $this->normalizeDate($revisionCreatedAt),
                'imported_at' => $this->metadataAuditDate($metadata, 'imported_at', 'import_event'),
                'built_at' => $this->metadataAuditDate($metadata, 'built_at', 'build_event'),
                'deployed_at' => $this->metadataAuditDate($metadata, 'deployed_at', 'deploy_event'),
            ],
            'forbidden_published_at_fallbacks' => [
                'revision_created_at',
                'imported_at',
                'built_at',
                'deployed_at',
                'model_created_at',
                'model_updated_at',
            ],
        ];
    }

    /**
     * @return array{value:string,source_field:string,source_kind:string,authority_ref:?string}|null
     */
    private function directDate(
        mixed $value,
        string $sourceField,
        string $sourceKind,
        ?string $authorityRef = null,
    ): ?array {
        $normalized = $this->normalizeDate($value);
        if ($normalized === null) {
            return null;
        }

        return [
            'value' => $normalized,
            'source_field' => $sourceField,
            'source_kind' => $sourceKind,
            'authority_ref' => $authorityRef,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{value:string,source_field:string,source_kind:string,authority_ref:?string}|null
     */
    private function metadataDate(
        array $metadata,
        string $field,
        string $requiredKind,
        mixed $canonicalValue = null,
        ?string $canonicalSourceField = null,
    ): ?array {
        $entry = data_get($metadata, 'date_provenance.'.$field);
        if (! is_array($entry)
            || trim((string) ($entry['source_kind'] ?? '')) !== $requiredKind
            || trim((string) ($entry['authority_ref'] ?? '')) === '') {
            return null;
        }
        $normalized = $this->normalizeDate($entry['value'] ?? null);
        if ($normalized === null) {
            return null;
        }
        if ($canonicalValue !== null && $normalized !== $this->normalizeDate($canonicalValue)) {
            return null;
        }

        return [
            'value' => $normalized,
            'source_field' => $canonicalSourceField ?? 'date_provenance.'.$field,
            'source_kind' => $requiredKind,
            'authority_ref' => trim((string) $entry['authority_ref']),
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function metadataAuditDate(array $metadata, string $field, string $requiredKind): ?string
    {
        return $this->metadataDate($metadata, $field, $requiredKind)['value'] ?? null;
    }

    /**
     * @param  array{value:string,source_field:string,source_kind:string,authority_ref:?string}|null  $entry
     * @return array{source_field:string,source_kind:string,authority_ref:?string}|null
     */
    private function withoutValue(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'source_field' => $entry['source_field'],
            'source_kind' => $entry['source_kind'],
            'authority_ref' => $entry['authority_ref'],
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse((string) $value);

            return $date->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function isCompletedReviewState(string $reviewState): bool
    {
        return in_array(strtolower(trim($reviewState)), [
            'approved',
            'human_reviewed',
            'published',
            'reviewed',
        ], true);
    }
}
