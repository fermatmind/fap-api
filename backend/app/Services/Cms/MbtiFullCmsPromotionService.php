<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class MbtiFullCmsPromotionService
{
    private const INTP_A_SEO_EXPERIMENT_SCHEMA_VERSION = 'personality.mbti-seo-title-experiment.v1';

    private const INTP_A_SEO_EXPERIMENT_ID = 'zh-intp-a-seo-title-20260810-v1';

    private const ARTIFACT = 'MBTI-CMS-APPROVAL-39-EXACT-43-RECORD-REPAIR-APPROVAL-PACKAGE';

    private const SOURCE_STATUS = 'approved_for_fail_closed_importer_preflight';

    private const PROFILE_SNAPSHOT_KEY = 'mbti_cms_import_40_profile_draft_v1';

    private const AT_SNAPSHOT_KEY = 'mbti_cms_import_40_at_comparison_draft_v1';

    public function __construct(
        private readonly PersonalityPublicReadModelCache $personalityPublicReadModelCache,
    ) {}

    /** @param array<string,mixed> $package @param array<string,mixed> $options @return array<string,mixed> */
    public function plan(array $package, array $options): array
    {
        return $this->buildSummary($package, $options, false);
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $options @return array<string,mixed> */
    public function promote(array $package, array $options): array
    {
        $summary = DB::transaction(fn (): array => $this->buildSummary($package, $options, true));
        if (($summary['ok'] ?? false) !== true) {
            return $summary;
        }

        $invalidated = 0;
        foreach ((array) ($summary['rows'] ?? []) as $row) {
            if (! is_array($row) || ($row['entity_kind'] ?? null) !== 'profile') {
                continue;
            }
            if ($this->personalityPublicReadModelCache->forgetType(
                (string) ($row['slug'] ?? ''),
                'zh-CN',
                0,
                PersonalityProfile::SCALE_CODE_MBTI,
            )) {
                $invalidated++;
            }
        }

        $summary['read_model_cache_invalidated_count'] = $invalidated;

        return $summary;
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $options @return array<string,mixed> */
    private function buildSummary(array $package, array $options, bool $write): array
    {
        $errors = $this->validateEnvelope($package, $options);
        $records = is_array($package['repair_records'] ?? null) ? array_values($package['repair_records']) : [];
        $rows = [];
        $seenSlugs = [];

        foreach ($records as $position => $record) {
            if (! is_array($record)) {
                $errors[] = $this->issue('repair_records.'.$position, 'record_invalid', 'Every repair record must be an object.');

                continue;
            }

            $row = $this->prepareRow($record, $position, $seenSlugs, $errors);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $promotionManifest = $this->promotionManifest($rows, $options);
        $promotionPackageSha256 = $this->hashCanonicalJson($promotionManifest);
        $authorizationPayload = $this->authorizationPayload($promotionPackageSha256, $options);
        $authorizationPayloadSha256 = $this->hashCanonicalJson($authorizationPayload);

        if ($write) {
            if (! hash_equals($promotionPackageSha256, (string) ($options['expected_promotion_package_sha256'] ?? ''))) {
                $errors[] = $this->issue('promotion_package_sha256', 'promotion_package_hash_mismatch', 'The exact promotion package hash does not match the current 43-record plan.');
            }
            if (! hash_equals($authorizationPayloadSha256, (string) ($options['expected_authorization_payload_sha256'] ?? ''))) {
                $errors[] = $this->issue('authorization_payload_sha256', 'authorization_payload_hash_mismatch', 'The exact promotion authorization hash does not match the current safety contract.');
            }
        }

        $base = $this->baseSummary($package, $options, $write, $promotionPackageSha256, $authorizationPayloadSha256, $rows);
        if ($errors !== []) {
            return array_merge($base, [
                'ok' => false,
                'status' => 'fail',
                'rows' => $rows,
                'errors' => $errors,
            ]);
        }

        $promoted = 0;
        $skipped = 0;
        foreach ($rows as &$row) {
            if (($row['live_matches_draft'] ?? false) === true) {
                $row['action'] = $write ? 'skipped_existing' : 'would_skip_existing';
                $skipped++;

                continue;
            }

            if ($write) {
                $this->applyPromotion($row);
                $row['action'] = 'promoted_public_content_noindex';
                $promoted++;
            } else {
                $row['action'] = 'would_promote_public_content_noindex';
            }
        }
        unset($row);

        return array_merge($base, [
            'ok' => true,
            'status' => 'pass',
            'promoted_count' => $promoted,
            'skipped_existing_count' => $skipped,
            'would_promote_count' => $write ? 0 : count($rows) - $skipped,
            'writes_committed' => $write && $promoted > 0,
            'rows' => $rows,
            'errors' => [],
        ]);
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $options @return list<array<string,string>> */
    private function validateEnvelope(array $package, array $options): array
    {
        $errors = [];
        $exact = is_array($package['exact_package'] ?? null) ? $package['exact_package'] : [];

        if (($package['artifact'] ?? null) !== self::ARTIFACT) {
            $errors[] = $this->issue('artifact', 'artifact_mismatch', 'Only the exact MBTI-CMS-39 repair package is accepted.');
        }
        if (($package['status'] ?? null) !== self::SOURCE_STATUS) {
            $errors[] = $this->issue('status', 'status_mismatch', 'The source package is not approved for controlled importer preflight.');
        }
        if ((string) ($exact['source_package_sha256'] ?? '') !== (string) ($options['expected_source_package_sha256'] ?? '')) {
            $errors[] = $this->issue('exact_package.source_package_sha256', 'source_package_hash_mismatch', 'The exact source package hash does not match.');
        }
        if ((string) ($exact['import_scope_mode'] ?? '') !== (string) ($options['expected_import_scope_mode'] ?? '')) {
            $errors[] = $this->issue('exact_package.import_scope_mode', 'scope_mode_mismatch', 'The exact import scope mode does not match.');
        }
        if ((int) ($exact['record_count'] ?? 0) !== (int) ($options['expected_record_count'] ?? 0)
            || (int) ($exact['record_count'] ?? 0) !== 43
            || count((array) ($package['repair_records'] ?? [])) !== 43) {
            $errors[] = $this->issue('exact_package.record_count', 'record_count_mismatch', 'The complete exact 43-record repair batch is required.');
        }
        if (($exact['production_import_authorized'] ?? null) !== false || ($exact['production_import_executed'] ?? null) !== false) {
            $errors[] = $this->issue('exact_package', 'source_package_state_invalid', 'The source approval package must remain non-authorizing and unexecuted.');
        }

        return $errors;
    }

    /** @param array<string,mixed> $record @param array<string,bool> $seenSlugs @param list<array<string,string>> $errors @return array<string,mixed>|null */
    private function prepareRow(array $record, int $position, array &$seenSlugs, array &$errors): ?array
    {
        $field = 'repair_records.'.$position;
        $kind = (string) ($record['entity_kind'] ?? '');
        $slug = trim((string) ($record['slug'] ?? ''));
        $payload = is_array($record['import_payload'] ?? null) ? $record['import_payload'] : [];
        $expectedPost = is_array($record['expected_post_state'] ?? null) ? $record['expected_post_state'] : [];

        if (! in_array($kind, ['profile', 'at_comparison'], true)) {
            $errors[] = $this->issue($field.'.entity_kind', 'unsupported_entity_kind', 'Only Profile and A/T comparison records are supported.');

            return null;
        }
        if ($slug === '' || isset($seenSlugs[$slug])) {
            $errors[] = $this->issue($field.'.slug', 'slug_invalid_or_duplicate', 'Every record must have a unique slug.');

            return null;
        }
        $seenSlugs[$slug] = true;
        if (($record['locale'] ?? null) !== 'zh-CN' || ($payload['locale'] ?? null) !== 'zh-CN') {
            $errors[] = $this->issue($field.'.locale', 'locale_mismatch', 'Only zh-CN records may be promoted.');
        }
        if (($payload['robots'] ?? null) !== 'noindex,follow'
            || data_get($payload, 'import_visibility.no_indexability_mutation') !== true
            || data_get($payload, 'import_visibility.no_sitemap_mutation') !== true
            || data_get($payload, 'import_visibility.no_llms_mutation') !== true) {
            $errors[] = $this->issue($field.'.import_payload', 'discoverability_hold_mismatch', 'The draft must preserve noindex and every discoverability hold.');
        }

        $payloadSha256 = $this->hashJson($payload);
        if (! hash_equals((string) ($expectedPost['content_payload_sha256'] ?? ''), $payloadSha256)) {
            $errors[] = $this->issue($field.'.expected_post_state.content_payload_sha256', 'payload_hash_mismatch', 'The approved payload hash does not match the source payload.');
        }

        $target = $kind === 'profile'
            ? $this->profileTarget($record, $payload, $field, $errors)
            : $this->comparisonTarget($record, $payload, $field, $errors);
        if ($target === null) {
            return null;
        }

        $snapshotKey = $kind === 'profile' ? self::PROFILE_SNAPSHOT_KEY : self::AT_SNAPSHOT_KEY;
        $revision = $this->latestRevision($target['model'], $target['id'], $snapshotKey);
        $snapshot = $revision instanceof Model && is_array($revision->getAttribute('snapshot_json'))
            ? $revision->getAttribute('snapshot_json')
            : [];
        $draft = is_array($snapshot[$snapshotKey] ?? null) ? $snapshot[$snapshotKey] : [];
        if (! $revision instanceof Model) {
            $errors[] = $this->issue($field.'.revision', 'latest_revision_missing', 'The target has no staged CMS-40 revision.');
        } elseif (($draft['approval_record_id'] ?? null) !== ($record['approval_record_id'] ?? null)
            || ($draft['payload_sha256'] ?? null) !== $payloadSha256
            || ($draft['target_path'] ?? null) !== ($record['target_path'] ?? null)
            || ($draft['entity_kind'] ?? null) !== $kind
            || ($draft['visibility'] ?? null) !== 'draft_only'
            || ($draft['public_projection_promoted'] ?? null) !== false
            || ($draft['indexability_mutated'] ?? null) !== false
            || ($draft['sitemap_eligibility_mutated'] ?? null) !== false
            || ($draft['llms_eligibility_mutated'] ?? null) !== false
            || ! is_array($draft['payload'] ?? null)
            || $this->hashCanonicalJson($draft['payload']) !== $this->hashCanonicalJson($payload)) {
            $errors[] = $this->issue($field.'.revision', 'latest_revision_contract_mismatch', 'The latest target revision is not the exact immutable CMS-40 draft.');
        }

        $promotion = $kind === 'profile'
            ? $this->profilePromotion($payload, $payloadSha256)
            : $this->comparisonPromotion($payload, $payloadSha256);

        return [
            'position' => $position + 1,
            'approval_record_id' => (string) ($record['approval_record_id'] ?? ''),
            'entity_kind' => $kind,
            'slug' => $slug,
            'url' => (string) ($record['target_url'] ?? $payload['canonical'] ?? ''),
            'target_path' => (string) ($record['target_path'] ?? ''),
            'target_model' => $target['model'],
            'target_id' => $target['id'],
            'snapshot_key' => $snapshotKey,
            'revision_id' => $revision instanceof Model ? (int) $revision->getKey() : null,
            'revision_no' => $revision instanceof Model ? (int) $revision->getAttribute('revision_no') : null,
            'payload_sha256' => $payloadSha256,
            'is_indexable_before' => $target['is_indexable'],
            'promotion_preview' => $promotion,
            'live_matches_draft' => $this->liveMatches($kind, $target['id'], $promotion),
            'action' => 'pending',
        ];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @param list<array<string,string>> $errors @return array{model:string,id:int,is_indexable:bool}|null */
    private function profileTarget(array $record, array $payload, string $field, array &$errors): ?array
    {
        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $baseType = strtoupper((string) ($identity['canonical_type_code'] ?? ''));
        $runtimeType = strtoupper((string) ($identity['runtime_type_code'] ?? ''));
        $profile = $this->publishedProfile($baseType);
        $variant = $profile instanceof PersonalityProfile
            ? PersonalityProfileVariant::query()->withoutGlobalScopes()
                ->where('personality_profile_id', $profile->id)
                ->where('runtime_type_code', $runtimeType)
                ->where('is_published', true)
                ->first()
            : null;

        if (! $profile instanceof PersonalityProfile
            || ! $variant instanceof PersonalityProfileVariant
            || strtolower($runtimeType) !== (string) ($record['slug'] ?? '')) {
            $errors[] = $this->issue($field, 'profile_target_mismatch', 'The exact published Profile variant target was not found.');

            return null;
        }

        return ['model' => 'variant', 'id' => (int) $variant->id, 'is_indexable' => (bool) $profile->is_indexable];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @param list<array<string,string>> $errors @return array{model:string,id:int,is_indexable:bool}|null */
    private function comparisonTarget(array $record, array $payload, string $field, array &$errors): ?array
    {
        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $baseType = strtoupper((string) ($identity['base_type_code'] ?? ''));
        $profile = $this->publishedProfile($baseType);
        $expectedSlug = strtolower($baseType).'-a-vs-'.strtolower($baseType).'-t';

        if (! $profile instanceof PersonalityProfile
            || ($payload['page_type'] ?? null) !== 'comparison'
            || ($payload['comparison_kind'] ?? null) !== 'at'
            || ($record['slug'] ?? null) !== $expectedSlug
            || ($identity['comparison_slug'] ?? null) !== $expectedSlug
            || ($identity['left_type_code'] ?? null) !== $baseType.'-A'
            || ($identity['right_type_code'] ?? null) !== $baseType.'-T') {
            $errors[] = $this->issue($field, 'comparison_target_mismatch', 'The exact published A/T comparison target was not found.');

            return null;
        }

        return ['model' => 'profile', 'id' => (int) $profile->id, 'is_indexable' => (bool) $profile->is_indexable];
    }

    private function publishedProfile(string $typeCode): ?PersonalityProfile
    {
        $profile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')
            ->where('canonical_type_code', $typeCode)
            ->where('status', 'published')
            ->where('is_public', true)
            ->first();

        return $profile instanceof PersonalityProfile ? $profile : null;
    }

    private function latestRevision(
        string $model,
        int $targetId,
        string $snapshotKey,
    ): PersonalityProfileRevision|PersonalityProfileVariantRevision|null {
        $query = $model === 'profile'
            ? PersonalityProfileRevision::query()->where('profile_id', $targetId)
            : PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $targetId);
        foreach ($query->orderByDesc('revision_no')->orderByDesc('id')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            if ($this->isIntpASeoExperimentRevision($snapshot)) {
                continue;
            }

            return $revision instanceof PersonalityProfileRevision || $revision instanceof PersonalityProfileVariantRevision
                ? $revision
                : null;
        }

        return null;
    }

    /** @param array<string,mixed> $snapshot */
    private function isIntpASeoExperimentRevision(array $snapshot): bool
    {
        return ($snapshot['schema_version'] ?? null) === self::INTP_A_SEO_EXPERIMENT_SCHEMA_VERSION
            && ($snapshot['experiment_id'] ?? null) === self::INTP_A_SEO_EXPERIMENT_ID;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function profilePromotion(array $payload, string $payloadSha256): array
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $canonical = (string) ($payload['canonical'] ?? '');
        $sections = [];
        foreach ((array) ($payload['content_sections'] ?? []) as $sort => $section) {
            if (! is_array($section)) {
                continue;
            }
            $key = (string) ($section['key'] ?? $section['id'] ?? '');
            if ($key === '') {
                continue;
            }
            $sections[] = $this->variantSection($key, 'rich_text', (string) ($section['body'] ?? ''), [
                'title' => (string) ($section['title'] ?? ''),
                'body' => (string) ($section['body'] ?? ''),
                'rows' => is_array($section['rows'] ?? null) ? $section['rows'] : [],
                'source' => self::PROFILE_SNAPSHOT_KEY,
                'raw' => $section,
            ], 100 + ($sort * 10));
        }

        $sections[] = $this->variantSection('faq', 'faq', null, [
            'title' => '常见问题',
            'items' => array_values((array) ($payload['faq'] ?? [])),
            'source' => self::PROFILE_SNAPSHOT_KEY,
        ], 900);
        $sections[] = $this->variantSection('mbti_content15_internal_links', 'links', null, [
            'title' => '继续了解',
            'items' => $this->normalizedLinks((array) ($payload['internal_links'] ?? [])),
            'source' => self::PROFILE_SNAPSHOT_KEY,
        ], 910);
        $sections[] = $this->variantSection('mbti_cms_import_40_metadata', 'callout', (string) ($seo['quick_answer_summary'] ?? ''), [
            'title' => (string) ($seo['h1'] ?? ''),
            'quick_answer_summary' => (string) ($seo['quick_answer_summary'] ?? ''),
            'content' => is_array($payload['content'] ?? null) ? $payload['content'] : [],
            'structured_metadata' => is_array($payload['structured_metadata'] ?? null) ? $payload['structured_metadata'] : [],
            'payload_sha256' => $payloadSha256,
            'source' => self::PROFILE_SNAPSHOT_KEY,
        ], 920);

        return [
            'seo' => [
                'seo_title' => (string) ($seo['seo_title'] ?? ''),
                'seo_description' => (string) ($seo['seo_description'] ?? ''),
                'canonical_url' => $canonical,
                'og_title' => (string) ($seo['seo_title'] ?? ''),
                'og_description' => (string) ($seo['seo_description'] ?? ''),
                'twitter_title' => (string) ($seo['seo_title'] ?? ''),
                'twitter_description' => (string) ($seo['seo_description'] ?? ''),
                'robots' => 'noindex,follow',
                'jsonld_overrides_json' => [
                    'name' => (string) ($seo['h1'] ?? $seo['seo_title'] ?? ''),
                    'description' => (string) ($seo['seo_description'] ?? ''),
                    'url' => $canonical,
                ],
            ],
            'sections' => $sections,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function comparisonPromotion(array $payload, string $payloadSha256): array
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];

        return ['comparison_section' => [
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => (string) ($seo['h1'] ?? $seo['seo_title'] ?? ''),
            'render_variant' => 'rich_text',
            'body_md' => (string) ($seo['quick_answer_summary'] ?? ''),
            'body_html' => null,
            'payload_json' => [
                'url' => (string) ($payload['canonical_target'] ?? $payload['url'] ?? ''),
                'canonical' => (string) ($payload['canonical'] ?? ''),
                'robots' => 'noindex,follow',
                'seo' => $seo,
                'content' => is_array($payload['content'] ?? null) ? $payload['content'] : [],
                'sections' => is_array($payload['content_sections'] ?? null) ? $payload['content_sections'] : [],
                'faq' => array_values((array) ($payload['faq'] ?? [])),
                'internal_links' => $this->normalizedLinks((array) ($payload['internal_links'] ?? [])),
                'structured_metadata' => is_array($payload['structured_metadata'] ?? null) ? $payload['structured_metadata'] : [],
                'identity' => is_array($payload['identity'] ?? null) ? $payload['identity'] : [],
                'payload_sha256' => $payloadSha256,
                'source' => self::AT_SNAPSHOT_KEY,
            ],
            'sort_order' => 920,
            'is_enabled' => true,
        ]];
    }

    /** @param list<mixed> $links @return list<array<string,mixed>> */
    private function normalizedLinks(array $links): array
    {
        $normalized = [];
        foreach ($links as $index => $link) {
            if (! is_array($link)) {
                continue;
            }
            $normalized[] = [
                'href' => (string) ($link['href'] ?? ''),
                'anchor_text' => (string) ($link['label'] ?? $link['anchor_text'] ?? ''),
                'role' => (string) ($link['purpose'] ?? $link['reason'] ?? 'content_continue_'.$index),
                'safe_public_route' => ($link['safe_public_route'] ?? true) === true,
            ];
        }

        return $normalized;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function variantSection(string $key, string $renderVariant, ?string $body, array $payload, int $sortOrder): array
    {
        return [
            'section_key' => $key,
            'render_variant' => $renderVariant,
            'body_md' => $body,
            'body_html' => null,
            'payload_json' => $payload,
            'sort_order' => $sortOrder,
            'is_enabled' => true,
        ];
    }

    /** @param array<string,mixed> $row */
    private function applyPromotion(array $row): void
    {
        $targetId = (int) $row['target_id'];
        $promotion = is_array($row['promotion_preview'] ?? null) ? $row['promotion_preview'] : [];
        if (($row['entity_kind'] ?? null) === 'at_comparison') {
            $section = (array) ($promotion['comparison_section'] ?? []);
            PersonalityProfileSection::query()->withoutGlobalScopes()->updateOrCreate(
                ['profile_id' => $targetId, 'section_key' => 'mbti64_comparison_a_vs_t'],
                array_merge($section, ['profile_id' => $targetId])
            );

            return;
        }

        PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->updateOrCreate(
            ['personality_profile_variant_id' => $targetId],
            array_merge((array) ($promotion['seo'] ?? []), ['personality_profile_variant_id' => $targetId])
        );
        foreach ((array) ($promotion['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            PersonalityProfileVariantSection::query()->withoutGlobalScopes()->updateOrCreate(
                ['personality_profile_variant_id' => $targetId, 'section_key' => (string) ($section['section_key'] ?? '')],
                array_merge($section, ['personality_profile_variant_id' => $targetId])
            );
        }
    }

    /** @param array<string,mixed> $promotion */
    private function liveMatches(string $kind, int $targetId, array $promotion): bool
    {
        if ($kind === 'at_comparison') {
            $live = PersonalityProfileSection::query()->withoutGlobalScopes()
                ->where('profile_id', $targetId)
                ->where('section_key', 'mbti64_comparison_a_vs_t')
                ->first();

            return $live instanceof PersonalityProfileSection
                && $this->modelSubsetMatches($live, (array) ($promotion['comparison_section'] ?? []));
        }

        $seo = PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()
            ->where('personality_profile_variant_id', $targetId)
            ->first();
        if (! $seo instanceof PersonalityProfileVariantSeoMeta
            || ! $this->modelSubsetMatches($seo, (array) ($promotion['seo'] ?? []))) {
            return false;
        }
        foreach ((array) ($promotion['sections'] ?? []) as $expected) {
            if (! is_array($expected)) {
                continue;
            }
            $live = PersonalityProfileVariantSection::query()->withoutGlobalScopes()
                ->where('personality_profile_variant_id', $targetId)
                ->where('section_key', (string) ($expected['section_key'] ?? ''))
                ->first();
            if (! $live instanceof PersonalityProfileVariantSection || ! $this->modelSubsetMatches($live, $expected)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $expected */
    private function modelSubsetMatches(Model $model, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            $actual = $model->getAttribute($key);
            if (is_array($value)) {
                if ($this->canonicalize(is_array($actual) ? $actual : []) !== $this->canonicalize($value)) {
                    return false;
                }
            } elseif ($actual !== $value && (string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $options @return array<string,mixed> */
    private function promotionManifest(array $rows, array $options): array
    {
        return [
            'artifact' => 'MBTI-CMS-40-PUBLIC-CONTENT-PROMOTION-PACKAGE-V1',
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'import_scope_mode' => (string) ($options['expected_import_scope_mode'] ?? ''),
            'record_count' => (int) ($options['expected_record_count'] ?? 0),
            'records' => array_map(static fn (array $row): array => [
                'approval_record_id' => $row['approval_record_id'],
                'entity_kind' => $row['entity_kind'],
                'slug' => $row['slug'],
                'url' => $row['url'],
                'target_path' => $row['target_path'],
                'payload_sha256' => $row['payload_sha256'],
                'snapshot_key' => $row['snapshot_key'],
            ], $rows),
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function authorizationPayload(string $promotionPackageSha256, array $options): array
    {
        return [
            'artifact' => 'MBTI-CMS-40-PUBLIC-CONTENT-PROMOTION-AUTHORIZATION-V1',
            'promotion_package_sha256' => $promotionPackageSha256,
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'import_scope_mode' => (string) ($options['expected_import_scope_mode'] ?? ''),
            'record_count' => (int) ($options['expected_record_count'] ?? 0),
            'mutations' => [
                'public_content' => true,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'search_release' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $options @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function baseSummary(array $package, array $options, bool $write, string $promotionSha, string $authorizationSha, array $rows): array
    {
        return [
            'artifact' => 'MBTI-CMS-40-PUBLIC-CONTENT-PROMOTION-V1',
            'source_artifact' => (string) ($package['artifact'] ?? ''),
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'promotion_package_sha256' => $promotionSha,
            'authorization_payload_sha256' => $authorizationSha,
            'import_scope_mode' => (string) ($options['expected_import_scope_mode'] ?? ''),
            'record_count' => (int) ($options['expected_record_count'] ?? 0),
            'row_count' => count($rows),
            'profile_row_count' => count(array_filter($rows, static fn (array $row): bool => $row['entity_kind'] === 'profile')),
            'at_comparison_row_count' => count(array_filter($rows, static fn (array $row): bool => $row['entity_kind'] === 'at_comparison')),
            'exact_urls' => array_values(array_map(static fn (array $row): string => (string) $row['url'], $rows)),
            'dry_run' => ! $write,
            'write' => $write,
            'public_content_promotion_attempted' => $write,
            'indexability_mutated' => false,
            'sitemap_mutated' => false,
            'llms_mutated' => false,
            'search_release_mutated' => false,
            'writes_committed' => false,
            'read_model_cache_invalidated_count' => 0,
        ];
    }

    /** @param array<string,mixed> $value */
    private function hashJson(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @param array<string,mixed> $value */
    private function hashCanonicalJson(array $value): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
