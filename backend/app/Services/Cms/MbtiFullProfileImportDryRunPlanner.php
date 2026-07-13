<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSeoMeta;

final class MbtiFullProfileImportDryRunPlanner
{
    private const EXPECTED_ARTIFACTS = [
        'MBTI-PROFILE-NT-31-CONTENT-PACKAGE',
        'MBTI-PROFILE-NF-32-CONTENT-PACKAGE',
        'MBTI-PROFILE-SJ-33-CONTENT-PACKAGE',
        'MBTI-PROFILE-SP-34-CONTENT-PACKAGE',
    ];

    private const REQUIRED_SECTION_KEYS = [
        'definition', 'suitable_for', 'not_suitable_for', 'common_misread', 'base16_difference',
        'at_difference', 'career_scenarios', 'relationship_scenarios', 'stress_scenarios',
    ];

    private const FORBIDDEN_ROUTE = '~/(?:result|results|attempt|report|order|orders|payment|pay|history|share|private|account)(?:/|$|[?#])~i';

    /**
     * @param  list<array{path:string,sha256:string,payload:array<string,mixed>}>  $packages
     * @return array<string,mixed>
     */
    public function plan(array $packages): array
    {
        $errors = [];
        $warnings = [];
        $rows = [];
        $artifacts = [];

        if (count($packages) !== 4) {
            $errors[] = $this->issue('packages', 'package_count_must_be_four', 'MBTI-CMS-PROFILE-37 requires exactly four Profile content packages.');
        }

        foreach ($packages as $packageIndex => $package) {
            $payload = $package['payload'];
            $artifact = trim((string) ($payload['artifact'] ?? ''));
            $artifacts[] = $artifact;
            if (! in_array($artifact, self::EXPECTED_ARTIFACTS, true)) {
                $errors[] = $this->issue('packages.'.$packageIndex.'.artifact', 'unexpected_artifact', 'Package artifact is not one of the four approved Profile cohorts.');
            }
            $assets = $payload['assets'] ?? null;
            if (! is_array($assets) || count($assets) !== 8) {
                $errors[] = $this->issue('packages.'.$packageIndex.'.assets', 'package_asset_count_must_be_eight', 'Each approved Profile package must contain exactly eight assets.');

                continue;
            }
            foreach ($assets as $assetIndex => $asset) {
                if (! is_array($asset)) {
                    $errors[] = $this->issue('packages.'.$packageIndex.'.assets.'.$assetIndex, 'asset_not_object', 'Each asset must be an object.');

                    continue;
                }
                $rows[] = $this->rowPlan($asset, count($rows), $errors, $warnings);
            }
        }

        if (count(array_unique($artifacts)) !== count($artifacts)) {
            $errors[] = $this->issue('packages', 'duplicate_artifact', 'Each of the four approved cohort artifacts must be provided once.');
        }
        foreach (self::EXPECTED_ARTIFACTS as $artifact) {
            if (! in_array($artifact, $artifacts, true)) {
                $errors[] = $this->issue('packages', 'required_artifact_missing', 'Required Profile cohort artifact missing: '.$artifact.'.');
            }
        }
        if (count($rows) !== 32) {
            $errors[] = $this->issue('records', 'record_count_must_be_32', 'The full Profile dry-run must normalize exactly 32 records.');
        }
        $slugs = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['slug'] ?? ''), $rows)));
        if (count($slugs) !== count(array_unique($slugs))) {
            $errors[] = $this->issue('records', 'duplicate_slug', 'Each normalized Profile slug must be unique.');
        }

        $repairCount = count(array_filter($rows, static fn (array $row): bool => ($row['action'] ?? '') === 'would_stage_existing_profile_variant_draft'));
        $verifyOnlyCount = count(array_filter($rows, static fn (array $row): bool => ($row['action'] ?? '') === 'would_verify_existing_profile_variant_only'));
        if ($repairCount !== 28 || $verifyOnlyCount !== 4) {
            $errors[] = $this->issue('records.audit_status', 'repair_and_verify_cohort_mismatch', 'The approved batch must contain 28 repair records and 4 verify-only records.');
        }

        $packageHashes = array_map(static fn (array $package): string => $package['sha256'], $packages);
        sort($packageHashes, SORT_STRING);

        return [
            'artifact' => 'MBTI-CMS-PROFILE-37-FULL-DRY-RUN',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'package_count' => count($packages),
            'record_count' => count($rows),
            'profile_record_count' => count($rows),
            'repair_record_count' => $repairCount,
            'verify_only_record_count' => $verifyOnlyCount,
            'source_packages' => array_map(static fn (array $package): array => [
                'path' => $package['path'], 'sha256' => $package['sha256'], 'artifact' => (string) ($package['payload']['artifact'] ?? ''),
            ], $packages),
            'idempotency_key' => hash('sha256', implode('|', $packageHashes)),
            'field_mapping_contract' => $this->fieldMappingContract(),
            'expected_public_state' => [
                'is_public' => true,
                'is_indexable' => true,
                'robots' => 'index,follow',
                'sitemap_eligible' => true,
                'llms_eligible' => true,
            ],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string,mixed> $asset @param list<array<string,string>> $errors @param list<array<string,string>> $warnings @return array<string,mixed> */
    private function rowPlan(array $asset, int $index, array &$errors, array &$warnings): array
    {
        $prefix = 'records.'.$index;
        $path = trim((string) ($asset['path'] ?? ''));
        $slug = basename($path);
        $type = strtoupper(trim((string) ($asset['mbti_type'] ?? '')));
        $variant = strtoupper(trim((string) ($asset['variant'] ?? '')));
        $runtime = $type.'-'.$variant;
        $status = trim((string) ($asset['audit_status'] ?? ''));
        $cms = is_array($asset['cms_fields'] ?? null) ? $asset['cms_fields'] : null;

        if ((string) ($asset['page_type'] ?? '') !== 'profile') {
            $errors[] = $this->issue($prefix.'.page_type', 'profile_page_type_required', 'Only Profile assets belong to MBTI-CMS-PROFILE-37.');
        }
        if ((string) ($asset['locale'] ?? '') !== 'zh') {
            $errors[] = $this->issue($prefix.'.locale', 'zh_locale_required', 'Chinese Profile assets must declare locale zh and normalize to zh-CN.');
        }
        if (! in_array($type, PersonalityProfile::BASE_TYPE_CODES, true) || ! in_array($variant, ['A', 'T'], true)) {
            $errors[] = $this->issue($prefix.'.identity', 'unsupported_mbti_variant_identity', 'Profile assets must use a supported MBTI base type and A/T variant.');
        }
        if (trim((string) ($asset['asset_id'] ?? '')) === '' || (string) ($asset['framework'] ?? '') !== 'mbti64') {
            $errors[] = $this->issue($prefix.'.asset', 'asset_identity_invalid', 'Profile asset id and mbti64 framework are required.');
        }
        if (preg_match('~^/zh/personality/(?<type>[a-z]{4})-(?<variant>[at])$~', $path, $matches) !== 1
            || strtoupper((string) ($matches['type'] ?? '')) !== $type
            || strtoupper((string) ($matches['variant'] ?? '')) !== $variant) {
            $errors[] = $this->issue($prefix.'.path', 'invalid_profile_identity', 'Path, MBTI type, and A/T variant must agree on a Chinese Profile route.');
        }
        if (! in_array($status, ['needs_content_repair', 'verify_only'], true)) {
            $errors[] = $this->issue($prefix.'.audit_status', 'unsupported_audit_status', 'Profile assets must be repair candidates or verify-only records.');
        }
        $this->validateSafetyAndAuthority($asset, $prefix, $errors);

        $existing = $this->existingTarget($type, $runtime);
        if (($existing['matched'] ?? false) !== true) {
            $errors[] = $this->issue($prefix.'.target', 'existing_published_target_missing', 'A public zh-CN CMS Profile and published A/T Variant must already exist for this asset.');
        } elseif (($existing['effective_indexable'] ?? false) !== true) {
            $errors[] = $this->issue($prefix.'.target', 'existing_target_not_indexable', 'Existing public Profile and Variant SEO robots must remain indexable before a dry-run can prepare this approved batch.');
        }

        if ($status === 'needs_content_repair') {
            $this->validateCmsFields($cms, $prefix, $errors);
        } elseif ($cms !== null) {
            $warnings[] = $this->issue($prefix.'.cms_fields', 'verify_only_has_cms_fields', 'Verify-only records are not staged by this dry-run and should not carry rewrite fields.');
        }

        return [
            'position' => $index + 1,
            'asset_id' => (string) ($asset['asset_id'] ?? ''),
            'slug' => $slug,
            'target_path' => $path,
            'locale' => 'zh-CN',
            'page_type' => 'profile',
            'identity' => ['base_type_code' => $type, 'variant_code' => $variant, 'runtime_type_code' => $runtime],
            'target' => [
                'target_model' => 'App\\Models\\PersonalityProfileVariantRevision',
                'target_table' => 'personality_profile_variant_revisions',
                'lookup' => ['org_id' => 0, 'scale_code' => 'MBTI', 'locale' => 'zh-CN', 'runtime_type_code' => $runtime],
            ],
            'existing_target' => $existing,
            'audit_status' => $status,
            'action' => $status === 'verify_only' ? 'would_verify_existing_profile_variant_only' : 'would_stage_existing_profile_variant_draft',
            'content_mapping' => $cms === null ? null : [
                'seo' => ['seo_title' => $cms['title'] ?? null, 'seo_description' => $cms['meta_description'] ?? null, 'h1' => $cms['h1'] ?? null],
                'direct_answer' => $cms['direct_answer'] ?? null,
                'section_keys' => $this->sectionKeys($cms['sections'] ?? []),
                'faq_count' => count((array) ($cms['faq'] ?? [])),
                'internal_link_count' => count((array) ($cms['internal_links'] ?? [])),
            ],
            'source_refs' => array_values((array) ($asset['source_ledger'] ?? [])),
            'expected_public_state' => ['is_indexable' => true, 'robots' => 'index,follow', 'sitemap_eligible' => true, 'llms_eligible' => true],
            'write_mode_in_this_pr' => 'not_supported',
        ];
    }

    /** @param array<string,mixed>|null $cms @param list<array<string,string>> $errors */
    private function validateCmsFields(?array $cms, string $prefix, array &$errors): void
    {
        if ($cms === null) {
            $errors[] = $this->issue($prefix.'.cms_fields', 'cms_fields_missing', 'Repair candidates require CMS-backed content fields.');

            return;
        }
        foreach (['title', 'h1', 'meta_description', 'direct_answer'] as $field) {
            if (trim((string) ($cms[$field] ?? '')) === '') {
                $errors[] = $this->issue($prefix.'.cms_fields.'.$field, 'required_field_missing', 'Required CMS content field is missing.');
            }
        }
        $sections = is_array($cms['sections'] ?? null) ? $cms['sections'] : [];
        $keys = [];
        foreach ($sections as $section) {
            if (! is_array($section) || trim((string) ($section['key'] ?? '')) === '' || trim((string) ($section['title'] ?? '')) === '' || trim((string) ($section['body'] ?? '')) === '') {
                $errors[] = $this->issue($prefix.'.cms_fields.sections', 'invalid_section', 'Every Profile section needs a key, title, and body.');

                continue;
            }
            $keys[] = (string) ($section['key'] ?? '');
        }
        foreach (self::REQUIRED_SECTION_KEYS as $key) {
            if (! in_array($key, $keys, true)) {
                $errors[] = $this->issue($prefix.'.cms_fields.sections', 'required_section_missing', 'Required Profile section missing: '.$key.'.');
            }
        }
        $faq = is_array($cms['faq'] ?? null) ? $cms['faq'] : [];
        $validFaqCount = count(array_filter($faq, static fn (mixed $item): bool => is_array($item) && trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== ''));
        if (count($faq) < 5 || $validFaqCount !== count($faq)) {
            $errors[] = $this->issue($prefix.'.cms_fields.faq', 'faq_minimum_or_shape_invalid', 'Repair candidates require at least five question-and-answer FAQ items.');
        }
        $links = is_array($cms['internal_links'] ?? null) ? $cms['internal_links'] : [];
        if (count($links) < 5) {
            $errors[] = $this->issue($prefix.'.cms_fields.internal_links', 'internal_link_minimum_not_met', 'Repair candidates require at least five public internal links.');
        }
        foreach ($links as $linkIndex => $link) {
            $href = is_array($link) ? trim((string) ($link['href'] ?? '')) : '';
            if ($href === '' || preg_match(self::FORBIDDEN_ROUTE, $href) === 1 || str_contains($href, '?')) {
                $errors[] = $this->issue($prefix.'.cms_fields.internal_links.'.$linkIndex, 'unsafe_internal_link', 'Internal links must be safe public canonical paths without query strings.');
            }
        }
    }

    /** @param array<string,mixed> $asset @param list<array<string,string>> $errors */
    private function validateSafetyAndAuthority(array $asset, string $prefix, array &$errors): void
    {
        if ((array) ($asset['source_ledger'] ?? []) === []) {
            $errors[] = $this->issue($prefix.'.source_ledger', 'source_refs_missing', 'Every Profile asset requires source references.');
        }
        foreach ((array) ($asset['claim_boundary'] ?? []) as $key => $value) {
            if ($value !== false) {
                $errors[] = $this->issue($prefix.'.claim_boundary.'.$key, 'claim_boundary_not_clear', 'Profile source asset includes an unsupported public claim boundary.');
            }
        }
        $handoffPolicy = (array) ($asset['handoff_policy'] ?? []);
        if (($handoffPolicy['artifact_only'] ?? false) !== true) {
            $errors[] = $this->issue($prefix.'.handoff_policy.artifact_only', 'artifact_only_required', 'Profile content artifact must be limited to a dry-run approval artifact.');
        }

        foreach ([
            'cms_write_attempted',
            'production_import_attempted',
            'frontend_runtime_change_attempted',
            'sitemap_llms_mutation_attempted',
            'gsc_mutation_attempted',
            'production_deploy_attempted',
        ] as $key) {
            if (($handoffPolicy[$key] ?? false) === true) {
                $errors[] = $this->issue($prefix.'.handoff_policy.'.$key, 'handoff_side_effect_declared', 'Profile content artifact must not declare a completed write or release side effect.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function existingTarget(string $type, string $runtime): array
    {
        $profile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')->where('canonical_type_code', $type)
            ->where('status', 'published')->where('is_public', true)->first();
        $variant = $profile instanceof PersonalityProfile
            ? PersonalityProfileVariant::query()->withoutGlobalScopes()->where('personality_profile_id', $profile->id)->where('runtime_type_code', $runtime)->where('is_published', true)->first()
            : null;
        $seoMeta = $variant instanceof PersonalityProfileVariant
            ? PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()
                ->where('personality_profile_variant_id', $variant->id)
                ->first()
            : null;
        $robots = strtolower(trim((string) ($seoMeta?->robots ?? '')));
        $effectiveIndexable = $profile instanceof PersonalityProfile
            && $variant instanceof PersonalityProfileVariant
            && (bool) $profile->is_indexable
            && $robots !== ''
            && ! str_contains($robots, 'noindex');

        return [
            'matched' => $profile instanceof PersonalityProfile && $variant instanceof PersonalityProfileVariant,
            'profile_id' => $profile?->id,
            'variant_id' => $variant?->id,
            'profile_is_indexable' => $profile?->is_indexable,
            'variant_robots' => $seoMeta?->robots,
            'effective_indexable' => $effectiveIndexable,
        ];
    }

    /** @return list<string> */
    private function sectionKeys(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $section): string => is_array($section) ? (string) ($section['key'] ?? '') : '',
            $sections,
        ));
    }

    /** @return array<string,mixed> */
    private function fieldMappingContract(): array
    {
        return [
            'target_tables' => ['personality_profiles', 'personality_profile_variants', 'personality_profile_variant_revisions', 'personality_profile_variant_sections', 'personality_profile_variant_seo_meta'],
            'locale_mapping' => ['zh' => 'zh-CN'],
            'repair_action' => 'would_stage_existing_profile_variant_draft',
            'verify_only_action' => 'would_verify_existing_profile_variant_only',
            'no_write' => true,
        ];
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
