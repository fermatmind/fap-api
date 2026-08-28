<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\ContentMaterialDecision;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Services\SeoIntel\MaterialFingerprint\MaterialFingerprintV1;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class PersonalityMaterialDecisionService
{
    public const FAMILY = 'personality';

    public const ASSET_REVISION_KIND = 'personality_public_asset_revision';

    public const MBTI_PROFILE_REVISION_KIND = 'mbti_profile_revision';

    public const MBTI_VARIANT_REVISION_KIND = 'mbti_variant_revision';

    public function __construct(private readonly MaterialFingerprintV1 $fingerprint) {}

    public function recordPublicAsset(
        PersonalityPublicContentAsset $asset,
        PersonalityPublicContentAssetRevision $revision,
        PersonalityPublicContentAssetRevisionReview $review,
        CarbonInterface $effectiveAt,
        string $operation = 'publish',
    ): ContentMaterialDecision {
        $this->assertInsideTransaction();
        if ((int) $asset->id <= 0
            || (int) $asset->published_revision_id !== (int) $revision->id
            || (int) $revision->asset_id !== (int) $asset->id
            || (int) $review->revision_id !== (int) $revision->id
            || (int) $review->asset_id !== (int) $asset->id
            || (string) $review->decision !== PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED
            || ! hash_equals((string) $revision->source_hash, (string) $review->asset_sha256)
            || ! hash_equals((string) $revision->authority_package_sha256, (string) $review->authority_package_sha256)
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $review->evidence_sha256) !== 1
            || ! (bool) $asset->is_public
            || (string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            || ! is_array($revision->snapshot_json)
            || ! hash_equals($this->canonical($revision->snapshot_json), $this->canonical($this->assetRevisionSnapshot($asset)))) {
            throw new InvalidArgumentException('Personality material decision requires the reviewed published public asset revision.');
        }

        return $this->record(
            $asset,
            self::ASSET_REVISION_KIND,
            (string) $revision->id,
            $this->assetPayload($asset),
            $effectiveAt,
            $operation,
            'personality_asset_review:'.$review->id,
        );
    }

    public function recordLegacyRollbackHold(
        PersonalityPublicContentAsset $asset,
        CarbonInterface $effectiveAt,
    ): ContentMaterialDecision {
        $this->assertInsideTransaction();
        $identity = $this->identity($asset);
        $latest = ContentMaterialDecision::query()
            ->where('org_id', $identity['org_id'])->where('family', self::FAMILY)
            ->where('locale', $identity['locale'])->where('authority_subject_key', $identity['authority_subject_key'])
            ->latest('id')->lockForUpdate()->first();
        if ($latest instanceof ContentMaterialDecision
            && (string) $latest->operation === 'rollback'
            && (string) $latest->decision_code === 'rollback_hold_unknown_legacy_fingerprint') {
            return $latest;
        }

        return ContentMaterialDecision::query()->create([
            ...$identity,
            'previous_public_identity' => $latest?->public_identity,
            'authority_revision_kind' => self::ASSET_REVISION_KIND,
            'authority_revision' => 'unknown',
            'material_fingerprint' => null,
            'previous_material_fingerprint' => $latest?->material_fingerprint,
            'publication_state' => 'published',
            'operation' => 'rollback',
            'decision_code' => 'rollback_hold_unknown_legacy_fingerprint',
            'material_changed' => false,
            'material_changed_at' => $latest?->material_changed_at,
            'evidence_ref' => 'personality_asset:'.$asset->id.':rollback_unknown',
            'decision_key' => hash('sha256', implode('|', [
                (string) $identity['org_id'], self::FAMILY, $identity['locale'],
                $identity['authority_subject_key'], (string) ($latest?->id ?? 'initial'),
                'unknown', 'rollback_hold', $effectiveAt->toIso8601String(),
            ])),
        ]);
    }

    /** @param array{kind:string,model:Model,seo:Model} $resolved */
    public function recordMbti(
        array $resolved,
        Model $revision,
        ReviewAttestation $review,
        string $reviewTargetIdentity,
        string $reviewTargetSha256,
        CarbonInterface $effectiveAt,
        string $operation = 'publish',
    ): ContentMaterialDecision {
        $this->assertInsideTransaction();
        $kind = (string) ($resolved['kind'] ?? '');
        $model = $resolved['model'] ?? null;
        $seo = $resolved['seo'] ?? null;
        $isProfile = $kind === 'mbti_profile'
            && $model instanceof PersonalityProfile
            && $revision instanceof PersonalityProfileRevision;
        $isVariant = $kind === 'mbti_variant'
            && $model instanceof PersonalityProfileVariant
            && $revision instanceof PersonalityProfileVariantRevision;
        if ((! $isProfile && ! $isVariant) || ! $seo instanceof Model) {
            throw new InvalidArgumentException('Unsupported MBTI public material authority.');
        }
        $revisionSnapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
        $revisionOwnerId = $isProfile ? (int) $revision->profile_id : (int) $revision->personality_profile_variant_id;
        if ((int) $model->getKey() <= 0
            || $revisionOwnerId !== (int) $model->getKey()
            || ($isProfile && ((string) $model->status !== 'published' || ! (bool) $model->is_public))
            || ($isVariant && ! (bool) $model->is_published)) {
            throw new InvalidArgumentException('MBTI material decision requires public profile authority.');
        }
        $revisionPackageSha256 = data_get($revisionSnapshot, 'seo_top100_frozen_20260812_v1.package_sha256');
        if (! $this->containsValue($revisionSnapshot, $reviewTargetSha256)
            || (is_string($revisionPackageSha256)
                && ! hash_equals($revisionPackageSha256, (string) $review->package_sha256))) {
            throw new InvalidArgumentException('MBTI material decision review does not bind the public revision.');
        }
        $targetEvidence = $review->loadMissing('targetEvidences')->targetEvidences
            ->first(static fn (ReviewAttestationTargetEvidence $evidence): bool => (string) $evidence->target_identity === $reviewTargetIdentity
                && hash_equals((string) $evidence->target_sha256, $reviewTargetSha256)
                && (string) $evidence->target_decision === 'approved');
        if (! $targetEvidence instanceof ReviewAttestationTargetEvidence
            || (string) $review->decision !== 'approved_all') {
            throw new InvalidArgumentException('MBTI material decision review evidence is missing or stale.');
        }

        return $this->record(
            $model,
            $isProfile ? self::MBTI_PROFILE_REVISION_KIND : self::MBTI_VARIANT_REVISION_KIND,
            (string) $revision->getKey(),
            $this->mbtiPayload($resolved),
            $effectiveAt,
            $operation,
            'review_attestation_target:'.$targetEvidence->id,
        );
    }

    /** @param array<string,mixed> $payload */
    private function record(
        Model $authority,
        string $revisionKind,
        string $authorityRevision,
        array $payload,
        CarbonInterface $effectiveAt,
        string $operation,
        string $evidenceRef,
    ): ContentMaterialDecision {
        if (! in_array($operation, ['publish', 'rollback'], true)) {
            throw new InvalidArgumentException('Unsupported Personality material decision operation.');
        }
        $identity = $this->identity($authority);
        $latest = ContentMaterialDecision::query()
            ->where('org_id', $identity['org_id'])
            ->where('family', self::FAMILY)
            ->where('locale', $identity['locale'])
            ->where('authority_subject_key', $identity['authority_subject_key'])
            ->latest('id')->lockForUpdate()->first();
        $materialFingerprint = $this->fingerprint->fingerprint($payload);
        if ($latest instanceof ContentMaterialDecision
            && (string) $latest->operation === $operation
            && (string) $latest->authority_revision_kind === $revisionKind
            && (string) $latest->authority_revision === $authorityRevision
            && hash_equals((string) $latest->material_fingerprint, $materialFingerprint)) {
            return $latest;
        }
        $same = $latest instanceof ContentMaterialDecision
            && (string) $latest->publication_state === 'published'
            && is_string($latest->material_fingerprint)
            && hash_equals((string) $latest->material_fingerprint, $materialFingerprint);
        $changed = ! $same;
        $decisionCode = match (true) {
            $operation === 'rollback' && $same => 'rollback_unchanged',
            $operation === 'rollback' => 'rollback_material_change',
            ! $latest instanceof ContentMaterialDecision => 'initial_publish',
            $same => 'unchanged_republish',
            default => 'material_change',
        };

        return ContentMaterialDecision::query()->create([
            ...$identity,
            'previous_public_identity' => $latest?->public_identity,
            'authority_revision_kind' => $revisionKind,
            'authority_revision' => $authorityRevision,
            'material_fingerprint' => $materialFingerprint,
            'previous_material_fingerprint' => $latest?->material_fingerprint,
            'publication_state' => 'published',
            'operation' => $operation,
            'decision_code' => $decisionCode,
            'material_changed' => $changed,
            'material_changed_at' => $changed ? $effectiveAt : $latest?->material_changed_at,
            'evidence_ref' => $evidenceRef,
            'decision_key' => hash('sha256', implode('|', [
                (string) $identity['org_id'], self::FAMILY, $identity['locale'],
                $identity['authority_subject_key'], (string) ($latest?->id ?? 'initial'),
                $revisionKind, $authorityRevision, $materialFingerprint, $operation,
            ])),
        ]);
    }

    /** @return array<string,mixed> */
    private function assetPayload(PersonalityPublicContentAsset $asset): array
    {
        return [
            'family' => self::FAMILY,
            'locale' => (string) $asset->locale,
            'public_identity' => $this->publicIdentity($asset),
            'authority_revision_kind' => self::ASSET_REVISION_KIND,
            'visible_content' => [
                'title' => $asset->title, 'summary' => $asset->summary,
                'sections' => $asset->content_sections_json, 'faq' => $asset->faq_json,
                'method_boundary' => $asset->method_boundary_json,
                'evidence_notes' => $asset->evidence_notes_json,
            ],
            'claims_and_sources' => ['authority' => $asset->authority_json],
            'search_surface' => [
                'seo' => $asset->seo_json, 'robots' => $asset->robots,
                'canonical' => $asset->canonical_json, 'hreflang' => $asset->hreflang_json,
                'schema' => $asset->schema_json, 'internal_links' => $this->semanticList($asset->internal_links_json),
                'index_eligible' => (bool) $asset->index_eligible,
                'sitemap_eligible' => (bool) $asset->sitemap_eligible,
                'llms_eligible' => (bool) $asset->llms_eligible,
            ],
            'locale_linkage' => ['hreflang' => $asset->hreflang_json],
            'public_structure' => [
                'framework' => $asset->framework, 'entity_type' => $asset->entity_type,
                'entity_key' => $asset->entity_key, 'slug' => $asset->slug,
                'is_public' => (bool) $asset->is_public, 'launch_state' => $asset->launch_state,
            ],
            'non_material_context' => [
                'published_revision_id' => $asset->published_revision_id,
                'source_hash' => $asset->source_hash,
            ],
        ];
    }

    /** @param array{kind:string,model:Model,seo:Model} $resolved @return array<string,mixed> */
    private function mbtiPayload(array $resolved): array
    {
        $model = $resolved['model'];
        $seo = $resolved['seo'];
        $isVariant = $model instanceof PersonalityProfileVariant;
        $profile = $isVariant
            ? PersonalityProfile::query()->withoutGlobalScopes()->findOrFail($model->personality_profile_id)
            : $model;
        $contentFields = $isVariant
            ? ['canonical_type_code', 'variant_code', 'runtime_type_code', 'type_name', 'nickname', 'rarity_text', 'keywords_json', 'hero_summary_md', 'hero_summary_html']
            : ['type_code', 'canonical_type_code', 'title', 'type_name', 'nickname', 'rarity_text', 'keywords_json', 'subtitle', 'excerpt', 'hero_kicker', 'hero_quote', 'hero_summary_md', 'hero_summary_html', 'hero_image_url'];
        $sections = $model->sections()->where('is_enabled', true)->get()
            ->map(fn (Model $section): array => $this->attributes($section, [
                'section_key', 'title', 'render_variant', 'body_md', 'body_html',
                'payload_json', 'sort_order', 'is_enabled',
            ]))
            ->all();

        return [
            'family' => self::FAMILY,
            'locale' => (string) $profile->locale,
            'public_identity' => $this->publicIdentity($model),
            'authority_revision_kind' => $isVariant ? self::MBTI_VARIANT_REVISION_KIND : self::MBTI_PROFILE_REVISION_KIND,
            'visible_content' => [
                'profile' => $this->attributes($model, $contentFields),
                'sections' => $sections,
            ],
            'claims_and_sources' => [],
            'search_surface' => $this->attributes($seo, [
                'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description',
                'og_image_url', 'twitter_title', 'twitter_description', 'twitter_image_url',
                'robots', 'jsonld_overrides_json',
            ]),
            'locale_linkage' => ['locale' => $profile->locale],
            'public_structure' => [
                'scale_code' => $profile->scale_code, 'slug' => $isVariant ? strtolower((string) $model->runtime_type_code) : $profile->slug,
                'canonical_type_code' => $profile->canonical_type_code,
                'variant_code' => $isVariant ? $model->variant_code : null,
                'is_public' => $isVariant ? (bool) $model->is_published : (bool) $profile->is_public,
                'is_indexable' => (bool) $profile->is_indexable,
            ],
            'non_material_context' => ['profile_id' => $profile->id],
        ];
    }

    /** @return array{org_id:int,family:string,locale:string,authority_subject_key:string,public_identity:string} */
    private function identity(Model $authority): array
    {
        $profile = $authority instanceof PersonalityProfileVariant
            ? PersonalityProfile::query()->withoutGlobalScopes()->findOrFail($authority->personality_profile_id)
            : null;
        $locale = $authority instanceof PersonalityPublicContentAsset
            ? (string) $authority->locale
            : (string) ($profile?->locale ?? $authority->locale);
        $kind = match (true) {
            $authority instanceof PersonalityPublicContentAsset => 'asset',
            $authority instanceof PersonalityProfileVariant => 'mbti_variant',
            default => 'mbti_profile',
        };

        return [
            'org_id' => (int) $authority->org_id,
            'family' => self::FAMILY,
            'locale' => $locale,
            'authority_subject_key' => hash('sha256', $kind.':'.$authority->getKey()),
            'public_identity' => $this->publicIdentity($authority),
        ];
    }

    private function publicIdentity(Model $authority): string
    {
        if ($authority instanceof PersonalityPublicContentAsset) {
            $path = trim((string) data_get($authority->canonical_json, 'path', ''));
            if ($path !== '') {
                if (! str_starts_with($path, '/')) {
                    throw new InvalidArgumentException('Public personality asset canonical identity is invalid.');
                }

                return $path;
            }
            $framework = str_replace('_', '-', (string) $authority->framework);
            $entityKey = trim((string) $authority->entity_key, '/');

            return '/'.$this->localeSegment((string) $authority->locale).'/personality/'.$framework.($entityKey === $framework ? '' : '/'.$entityKey);
        }
        if ($authority instanceof PersonalityProfileVariant) {
            $profile = PersonalityProfile::query()->withoutGlobalScopes()->findOrFail($authority->personality_profile_id);

            return '/'.$this->localeSegment((string) $profile->locale).'/personality/'.strtolower((string) $authority->runtime_type_code);
        }
        if ($authority instanceof PersonalityProfile) {
            return '/'.$this->localeSegment((string) $authority->locale).'/personality/'.$authority->slug;
        }

        throw new InvalidArgumentException('Unsupported personality public identity authority.');
    }

    private function localeSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    /** @return array<string,mixed> */
    private function assetRevisionSnapshot(PersonalityPublicContentAsset $asset): array
    {
        $fields = ['title', 'summary', 'content_sections_json', 'seo_json', 'faq_json', 'method_boundary_json', 'evidence_notes_json', 'authority_json', 'internal_links_json', 'source_package', 'source_hash'];

        return collect($asset->getAttributes())->only($fields)->map(function (mixed $value, string $key): mixed {
            return str_ends_with($key, '_json') && is_string($value) ? json_decode($value, true) : $value;
        })->all();
    }

    /** @return list<mixed> */
    private function semanticList(mixed $value): array
    {
        $items = is_array($value) ? array_values($value) : [];
        usort($items, fn (mixed $left, mixed $right): int => $this->canonical($left) <=> $this->canonical($right));

        return $items;
    }

    /** @param list<string> $fields @return array<string,mixed> */
    private function attributes(Model $model, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $model->getAttribute($field);
        }

        return $result;
    }

    private function containsValue(mixed $value, string $expected): bool
    {
        if (is_string($value)) {
            return hash_equals($value, $expected);
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $nested) {
            if ($this->containsValue($nested, $expected)) {
                return true;
            }
        }

        return false;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($this->sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->sort($nested);
        }

        return $value;
    }

    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Personality material decisions must be recorded inside the publication transaction.');
        }
    }
}
