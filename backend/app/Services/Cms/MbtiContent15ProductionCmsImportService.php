<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Support\Facades\DB;

final class MbtiContent15ProductionCmsImportService
{
    private const PROFILE_SNAPSHOT_KEY = 'mbti_content15_production_import_profile_v1';

    private const AT_COMPARISON_SNAPSHOT_KEY = 'mbti_content15_production_import_at_comparison_v1';

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    public function plan(array $package, array $authorizationPackage, array $options): array
    {
        return $this->buildSummary($package, $authorizationPackage, $options, false);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    public function import(array $package, array $authorizationPackage, array $options): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $authorizationPackage, $options, true));
    }

    public function __construct(private readonly MbtiContent15MixedImportPreflightPlanner $preflightPlanner) {}

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, array $authorizationPackage, array $options, bool $write): array
    {
        $preflight = $this->preflightPlanner->plan($package, $authorizationPackage, $options);
        $base = $this->baseSummary($package, $authorizationPackage, $options, $write, $preflight);
        if (($preflight['ok'] ?? false) !== true) {
            return array_merge($base, [
                'ok' => false,
                'status' => 'fail',
                'errors' => (array) ($preflight['errors'] ?? []),
                'warnings' => (array) ($preflight['warnings'] ?? []),
            ]);
        }

        $recordsById = [];
        foreach ((array) ($package['records'] ?? []) as $record) {
            if (is_array($record)) {
                $recordsById[(string) ($record['dry_run_record_id'] ?? '')] = $record;
            }
        }

        $errors = [];
        $warnings = (array) ($preflight['warnings'] ?? []);
        $preparedRows = [];
        foreach ((array) ($preflight['rows'] ?? []) as $planRow) {
            if (! is_array($planRow)) {
                $errors[] = $this->issue('rows', 'preflight_row_invalid', 'Preflight returned a malformed row.');

                continue;
            }

            $recordId = (string) ($planRow['dry_run_record_id'] ?? '');
            $record = $recordsById[$recordId] ?? null;
            if (! is_array($record)) {
                $errors[] = $this->issue('records', 'source_record_missing', 'The preflight row could not be resolved back to its source record.');

                continue;
            }

            $prepared = $this->prepareRow($planRow, $record, $package, $errors);
            if ($prepared !== null) {
                $preparedRows[] = $prepared;
            }
        }

        if ($errors !== []) {
            return array_merge($base, [
                'ok' => false,
                'status' => 'fail',
                'errors' => $errors,
                'warnings' => $warnings,
                'rows' => $preparedRows,
            ]);
        }

        $writtenRows = [];
        if ($write) {
            foreach ($preparedRows as $preparedRow) {
                $writtenRows[] = $this->applyRow($preparedRow);
            }
        }

        return array_merge($base, [
            'ok' => true,
            'status' => 'pass',
            'writes_committed' => $write,
            'cms_write_attempted' => $write,
            'published_content_count' => $write ? count($writtenRows) : 0,
            'rows' => $write ? $writtenRows : $preparedRows,
            'errors' => [],
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array<string,mixed>  $planRow
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $package
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>|null
     */
    private function prepareRow(array $planRow, array $record, array $package, array &$errors): ?array
    {
        $kind = (string) ($planRow['kind'] ?? '');
        $payload = is_array(data_get($record, 'dry_run_payload.payload'))
            ? data_get($record, 'dry_run_payload.payload')
            : [];
        $identity = is_array($planRow['identity'] ?? null) ? $planRow['identity'] : [];
        $recordId = (string) ($planRow['dry_run_record_id'] ?? '');
        $sourceSha = (string) data_get($package, 'exact_package.package_sha256', '');

        if ($kind === 'profile') {
            return $this->prepareProfileRow($planRow, $payload, $identity, $recordId, $sourceSha, $errors);
        }

        if (($identity['comparison_kind'] ?? null) === 'at') {
            return $this->prepareAtComparisonRow($planRow, $payload, $identity, $recordId, $sourceSha, $errors);
        }

        if ($kind === 'comparison' && ($identity['comparison_kind'] ?? null) === 'cross_type') {
            return $this->prepareCrossTypeComparisonRow($planRow, $payload, $identity, $recordId, $sourceSha, $errors);
        }

        $errors[] = $this->issue('records.'.$recordId, 'unsupported_import_target', 'CONTENT-15 production import only supports profile, A/T comparison, and cross-type comparison records.');

        return null;
    }

    /**
     * @param  array<string,mixed>  $planRow
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $identity
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>|null
     */
    private function prepareProfileRow(
        array $planRow,
        array $payload,
        array $identity,
        string $recordId,
        string $sourceSha,
        array &$errors,
    ): ?array {
        $runtimeTypeCode = (string) ($identity['runtime_type_code'] ?? '');
        $profile = $this->publishedBaseProfile((string) ($identity['base_type_code'] ?? ''), (string) ($identity['locale'] ?? ''));
        if (! $profile instanceof PersonalityProfile) {
            $errors[] = $this->issue('records.'.$recordId, 'published_base_profile_missing', 'The target MBTI base profile is not an existing public CMS authority row.');

            return null;
        }

        $variant = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', $runtimeTypeCode)
            ->where('is_published', true)
            ->first();
        if (! $variant instanceof PersonalityProfileVariant) {
            $errors[] = $this->issue('records.'.$recordId, 'published_variant_missing', 'The target MBTI A/T variant is not an existing published CMS authority row.');

            return null;
        }

        return [
            'record_id' => $recordId,
            'kind' => 'profile',
            'target_path' => (string) ($planRow['target_path'] ?? ''),
            'payload_sha256' => (string) ($planRow['payload_sha256'] ?? ''),
            'source_sha256' => $sourceSha,
            'profile' => $profile,
            'variant' => $variant,
            'payload' => $payload,
            'sections' => $this->normalizedSections($payload),
            'seo' => $this->normalizedSeo($payload),
            'action' => 'upsert_existing_profile_variant_content',
            'indexability_held' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $planRow
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $identity
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>|null
     */
    private function prepareAtComparisonRow(
        array $planRow,
        array $payload,
        array $identity,
        string $recordId,
        string $sourceSha,
        array &$errors,
    ): ?array {
        $profile = $this->publishedBaseProfile((string) ($identity['base_type_code'] ?? ''), (string) ($identity['locale'] ?? ''));
        if (! $profile instanceof PersonalityProfile) {
            $errors[] = $this->issue('records.'.$recordId, 'published_base_profile_missing', 'The target MBTI base profile is not an existing public CMS authority row.');

            return null;
        }

        return [
            'record_id' => $recordId,
            'kind' => 'at_comparison',
            'target_path' => (string) ($planRow['target_path'] ?? ''),
            'payload_sha256' => (string) ($planRow['payload_sha256'] ?? ''),
            'source_sha256' => $sourceSha,
            'profile' => $profile,
            'payload' => $payload,
            'action' => 'upsert_existing_at_comparison_section',
            'indexability_held' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $planRow
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $identity
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>|null
     */
    private function prepareCrossTypeComparisonRow(
        array $planRow,
        array $payload,
        array $identity,
        string $recordId,
        string $sourceSha,
        array &$errors,
    ): ?array {
        $slug = strtolower(trim((string) ($identity['comparison_slug'] ?? '')));
        $left = strtoupper(trim((string) ($identity['left_type_code'] ?? '')));
        $right = strtoupper(trim((string) ($identity['right_type_code'] ?? '')));
        if ($slug === '' || $left === '' || $right === '' || $left === $right) {
            $errors[] = $this->issue('records.'.$recordId, 'cross_type_identity_invalid', 'Cross-type comparison identity must contain a distinct pair and slug.');

            return null;
        }

        return [
            'record_id' => $recordId,
            'kind' => 'cross_type_comparison',
            'target_path' => (string) ($planRow['target_path'] ?? ''),
            'payload_sha256' => (string) ($planRow['payload_sha256'] ?? ''),
            'source_sha256' => $sourceSha,
            'locale' => (string) ($identity['locale'] ?? ''),
            'slug' => $slug,
            'left_type_code' => $left,
            'right_type_code' => $right,
            'payload' => $payload,
            'action' => 'upsert_cross_type_comparison_authority',
            'indexability_held' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     * @return array<string,mixed>
     */
    private function applyRow(array $preparedRow): array
    {
        return match ($preparedRow['kind']) {
            'profile' => $this->applyProfileRow($preparedRow),
            'at_comparison' => $this->applyAtComparisonRow($preparedRow),
            'cross_type_comparison' => $this->applyCrossTypeComparisonRow($preparedRow),
        };
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     * @return array<string,mixed>
     */
    private function applyProfileRow(array $preparedRow): array
    {
        /** @var PersonalityProfileVariant $variant */
        $variant = $preparedRow['variant'];
        $previous = $this->profileVariantState($variant);
        foreach ((array) $preparedRow['sections'] as $section) {
            PersonalityProfileVariantSection::query()
                ->withoutGlobalScopes()
                ->updateOrCreate(
                    [
                        'personality_profile_variant_id' => (int) $variant->id,
                        'section_key' => (string) $section['section_key'],
                    ],
                    array_merge($section, ['personality_profile_variant_id' => (int) $variant->id]),
                );
        }
        foreach ($this->profileSupportingSections((array) $preparedRow['payload']) as $section) {
            PersonalityProfileVariantSection::query()
                ->withoutGlobalScopes()
                ->updateOrCreate(
                    [
                        'personality_profile_variant_id' => (int) $variant->id,
                        'section_key' => (string) $section['section_key'],
                    ],
                    array_merge($section, ['personality_profile_variant_id' => (int) $variant->id]),
                );
        }
        PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                ['personality_profile_variant_id' => (int) $variant->id],
                $preparedRow['seo'],
            );
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'revision_no' => $this->nextVariantRevisionNo((int) $variant->id),
            'snapshot_json' => [
                self::PROFILE_SNAPSHOT_KEY => [
                    'record_id' => $preparedRow['record_id'],
                    'target_path' => $preparedRow['target_path'],
                    'source_sha256' => $preparedRow['source_sha256'],
                    'payload_sha256' => $preparedRow['payload_sha256'],
                    'indexability_held' => true,
                    'previous' => $previous,
                    'incoming' => $this->profileVariantState($variant->fresh(['sections', 'seoMeta'])),
                ],
            ],
            'note' => 'MBTI-CMS-27 CONTENT-15 authorized import '.substr((string) $preparedRow['payload_sha256'], 0, 12),
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);

        return $this->writtenRow($preparedRow, 'personality_profile_variant_revisions', (int) $variant->id);
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     * @return array<string,mixed>
     */
    private function applyAtComparisonRow(array $preparedRow): array
    {
        /** @var PersonalityProfile $profile */
        $profile = $preparedRow['profile'];
        $payload = is_array($preparedRow['payload'] ?? null) ? $preparedRow['payload'] : [];
        $sections = $this->normalizedSections($payload);
        $directAnswer = collect($sections)->firstWhere('section_key', 'direct_answer');
        PersonalityProfileSection::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'profile_id' => (int) $profile->id,
                    'section_key' => 'mbti64_comparison_a_vs_t',
                ],
                [
                    'org_id' => 0,
                    'title' => (string) ($payload['title'] ?? 'A/T 对比'),
                    'render_variant' => 'rich_text',
                    'body_md' => is_array($directAnswer) ? ($directAnswer['body_md'] ?? null) : null,
                    'body_html' => null,
                    'payload_json' => [
                        'source' => 'mbti_content15_production_import_v1',
                        'record_id' => $preparedRow['record_id'],
                        'target_path' => $preparedRow['target_path'],
                        'source_sha256' => $preparedRow['source_sha256'],
                        'payload_sha256' => $preparedRow['payload_sha256'],
                        'content' => $payload,
                        'indexability_held' => true,
                    ],
                    'sort_order' => 920,
                    'is_enabled' => true,
                ],
            );
        PersonalityProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => $this->nextProfileRevisionNo((int) $profile->id),
            'snapshot_json' => [
                self::AT_COMPARISON_SNAPSHOT_KEY => [
                    'record_id' => $preparedRow['record_id'],
                    'target_path' => $preparedRow['target_path'],
                    'source_sha256' => $preparedRow['source_sha256'],
                    'payload_sha256' => $preparedRow['payload_sha256'],
                    'indexability_held' => true,
                ],
            ],
            'note' => 'MBTI-CMS-27 CONTENT-15 A/T comparison import '.substr((string) $preparedRow['payload_sha256'], 0, 12),
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);

        return $this->writtenRow($preparedRow, 'personality_profile_sections', (int) $profile->id);
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     * @return array<string,mixed>
     */
    private function applyCrossTypeComparisonRow(array $preparedRow): array
    {
        $payload = is_array($preparedRow['payload'] ?? null) ? $preparedRow['payload'] : [];
        /** @var MbtiCrossTypeComparisonAuthority $authority */
        $authority = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'org_id' => 0,
                    'locale' => (string) $preparedRow['locale'],
                    'slug' => (string) $preparedRow['slug'],
                ],
                [
                    'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
                    'left_type_code' => (string) $preparedRow['left_type_code'],
                    'right_type_code' => (string) $preparedRow['right_type_code'],
                    'title' => (string) ($payload['title'] ?? ''),
                    'seo_title' => (string) data_get($payload, 'seo.title', ''),
                    'seo_description' => (string) data_get($payload, 'seo.meta_description', ''),
                    'summary' => (string) ($payload['summary'] ?? ''),
                    'content_payload_json' => [
                        'sections' => (array) ($payload['sections'] ?? []),
                        'faq' => (array) ($payload['faq'] ?? []),
                        'internal_links' => (array) ($payload['internal_links'] ?? []),
                        'source_notes' => (array) ($payload['evidence_notes'] ?? []),
                        'method_boundary' => (array) ($payload['method_boundary'] ?? []),
                        'canonical' => (string) ($payload['canonical'] ?? ''),
                        'robots' => (string) ($payload['robots'] ?? 'noindex,follow'),
                        'record_id' => $preparedRow['record_id'],
                        'payload_sha256' => $preparedRow['payload_sha256'],
                    ],
                    'claim_boundary' => $this->claimBoundary($payload),
                    'source_package_id' => 'mbti-cms-22-final-dry-run-2026-07-05',
                    'source_sha256' => (string) $preparedRow['source_sha256'],
                    'authority_contract_version' => MbtiCrossTypeComparisonAuthority::AUTHORITY_CONTRACT_VERSION,
                    'readmodel_contract_version' => MbtiCrossTypeComparisonAuthority::READMODEL_CONTRACT_VERSION,
                    'review_status' => 'approved',
                    'publish_status' => 'published',
                    'indexability_status' => 'held_for_mbti_index_24',
                    'is_public' => true,
                    'is_indexable' => false,
                    'sitemap_eligible' => false,
                    'llms_eligible' => false,
                    'search_submission_eligible' => false,
                    'published_at' => now(),
                    'imported_at' => now(),
                ],
            );

        return $this->writtenRow($preparedRow, 'mbti_cross_type_comparison_authorities', (int) $authority->id);
    }

    private function publishedBaseProfile(string $baseTypeCode, string $locale): ?PersonalityProfile
    {
        return PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', $locale)
            ->where('canonical_type_code', strtoupper(trim($baseTypeCode)))
            ->where('status', 'published')
            ->where('is_public', true)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function normalizedSections(array $payload): array
    {
        $sections = [];
        foreach ((array) ($payload['sections'] ?? []) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = trim((string) ($section['key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }
            $body = isset($section['body']) && is_scalar($section['body']) ? trim((string) $section['body']) : null;
            $sections[] = [
                'org_id' => 0,
                'section_key' => $sectionKey,
                'render_variant' => isset($section['rows']) ? 'cards' : 'rich_text',
                'body_md' => $body !== '' ? $body : null,
                'body_html' => null,
                'payload_json' => $section,
                'sort_order' => 960 + (int) $index,
                'is_enabled' => true,
            ];
        }

        return $sections;
    }

    /**
     * Keep CMS-backed supporting assets as first-class variant sections so the
     * public read model can expose them without reconstructing editorial data.
     *
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function profileSupportingSections(array $payload): array
    {
        $sections = [];
        $faq = array_values(array_filter((array) ($payload['faq'] ?? []), 'is_array'));
        if ($faq !== []) {
            $sections[] = [
                'org_id' => 0,
                'section_key' => 'mbti_content15_faq',
                'render_variant' => 'faq',
                'body_md' => null,
                'body_html' => null,
                'payload_json' => ['items' => $faq],
                'sort_order' => 980,
                'is_enabled' => true,
            ];
        }

        $internalLinks = array_values(array_filter((array) ($payload['internal_links'] ?? []), 'is_array'));
        if ($internalLinks !== []) {
            $sections[] = [
                'org_id' => 0,
                'section_key' => 'mbti_content15_internal_links',
                'render_variant' => 'links',
                'body_md' => null,
                'body_html' => null,
                'payload_json' => ['items' => $internalLinks],
                'sort_order' => 981,
                'is_enabled' => true,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizedSeo(array $payload): array
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];

        return [
            'org_id' => 0,
            'seo_title' => (string) ($seo['title'] ?? ''),
            'seo_description' => (string) ($seo['meta_description'] ?? ''),
            'canonical_url' => (string) ($payload['canonical'] ?? ''),
            'og_title' => (string) ($seo['title'] ?? ''),
            'og_description' => (string) ($seo['meta_description'] ?? ''),
            'og_image_url' => null,
            'twitter_title' => (string) ($seo['title'] ?? ''),
            'twitter_description' => (string) ($seo['meta_description'] ?? ''),
            'twitter_image_url' => null,
            'robots' => 'noindex,follow',
            'jsonld_overrides_json' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function claimBoundary(array $payload): string
    {
        $boundary = $payload['claim_boundary'] ?? null;
        if (is_string($boundary) && trim($boundary) !== '') {
            return trim($boundary);
        }

        return '人格内容仅用于自我理解，不用于医学诊断、招聘筛选、职业决定或关系预测。';
    }

    /**
     * @return array<string,mixed>
     */
    private function profileVariantState(PersonalityProfileVariant $variant): array
    {
        $variant->loadMissing(['sections', 'seoMeta']);

        return [
            'runtime_type_code' => (string) $variant->runtime_type_code,
            'sections' => $variant->sections->map(static fn (PersonalityProfileVariantSection $section): array => [
                'section_key' => (string) $section->section_key,
                'render_variant' => (string) $section->render_variant,
                'body_md' => $section->body_md,
                'payload_json' => $section->payload_json,
                'sort_order' => (int) $section->sort_order,
                'is_enabled' => (bool) $section->is_enabled,
            ])->values()->all(),
            'seo' => $variant->seoMeta instanceof PersonalityProfileVariantSeoMeta ? [
                'seo_title' => $variant->seoMeta->seo_title,
                'seo_description' => $variant->seoMeta->seo_description,
                'canonical_url' => $variant->seoMeta->canonical_url,
                'robots' => $variant->seoMeta->robots,
            ] : null,
        ];
    }

    private function nextVariantRevisionNo(int $variantId): int
    {
        return ((int) PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', $variantId)
            ->max('revision_no')) + 1;
    }

    private function nextProfileRevisionNo(int $profileId): int
    {
        return ((int) PersonalityProfileRevision::query()
            ->where('profile_id', $profileId)
            ->max('revision_no')) + 1;
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     * @return array<string,mixed>
     */
    private function writtenRow(array $preparedRow, string $targetTable, int $targetId): array
    {
        return [
            'record_id' => $preparedRow['record_id'],
            'kind' => $preparedRow['kind'],
            'target_path' => $preparedRow['target_path'],
            'payload_sha256' => $preparedRow['payload_sha256'],
            'action' => $preparedRow['action'],
            'target_table' => $targetTable,
            'target_id' => $targetId,
            'indexability_held' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  array<string,string>  $options
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, array $authorizationPackage, array $options, bool $write, array $preflight): array
    {
        return [
            'artifact' => 'MBTI-CMS-27-CONTENT15-PRODUCTION-IMPORT',
            'ok' => false,
            'status' => 'fail',
            'write' => $write,
            'dry_run' => ! $write,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => $write,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'source_package_sha256' => (string) data_get($package, 'exact_package.package_sha256', ''),
            'authorization_payload_sha256' => (string) data_get($authorizationPackage, 'authorization_package.exact_authorization_payload_sha256', ''),
            'import_scope_mode' => (string) data_get($authorizationPackage, 'authorization_package.import_scope_mode', ''),
            'record_count' => count((array) ($package['records'] ?? [])),
            'preflight_status' => (string) ($preflight['status'] ?? 'fail'),
            'preflight_artifact' => (string) ($preflight['artifact'] ?? ''),
            'required_indexability_holds' => [
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_eligible' => false,
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
