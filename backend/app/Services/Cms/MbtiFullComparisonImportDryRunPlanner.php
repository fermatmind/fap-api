<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;

final class MbtiFullComparisonImportDryRunPlanner
{
    private const AT_ARTIFACT = 'MBTI-COMP-AT-35-CONTENT-ASSETS';

    private const CROSS_ARTIFACT = 'MBTI-CMS-06-COMPARISON-CONTENT-ASSETS';

    private const EXPECTED_CROSS_SLUGS = [
        'intj-vs-intp',
        'entj-vs-intj',
        'infj-vs-infp',
        'istj-vs-isfj',
    ];

    private const REQUIRED_AT_SECTION_KEYS = [
        'biggest_difference',
        'quick_judgment_table',
        'easy_misread',
        'work_scenarios',
        'relationship_scenarios',
        'stress_scenarios',
        'do_not_misjudge',
    ];

    private const REQUIRED_CROSS_SECTION_KEYS = [
        'biggest_difference',
        'quick_judgment_table',
        'easy_misread',
        'real_scenario_differences',
        'do_not_misjudge',
        'faq',
    ];

    private const FORBIDDEN_ROUTE = '~/(?:result|results|attempt|report|order|orders|payment|pay|history|share|private|account)(?:/|$|[?#])~i';

    /**
     * @param  list<array{role:string,path:string,sha256:string,payload:array<string,mixed>}>  $packages
     * @return array<string,mixed>
     */
    public function plan(array $packages): array
    {
        $errors = [];
        $warnings = [];
        $byRole = [];

        if (count($packages) !== 2) {
            $errors[] = $this->issue('packages', 'package_count_must_be_two', 'MBTI-CMS-COMP-38 requires exactly one A/T package and one cross-type package.');
        }

        foreach ($packages as $index => $package) {
            $role = (string) ($package['role'] ?? '');
            if (! in_array($role, ['at', 'cross_type'], true)) {
                $errors[] = $this->issue('packages.'.$index.'.role', 'package_role_invalid', 'Package role must be at or cross_type.');

                continue;
            }
            if (isset($byRole[$role])) {
                $errors[] = $this->issue('packages.'.$index.'.role', 'package_role_duplicate', 'Each comparison package role must be provided once.');

                continue;
            }
            $byRole[$role] = $package;
        }

        $atPackage = $byRole['at'] ?? null;
        $crossPackage = $byRole['cross_type'] ?? null;
        if (! is_array($atPackage) || (string) data_get($atPackage, 'payload.artifact') !== self::AT_ARTIFACT) {
            $errors[] = $this->issue('packages.at.artifact', 'at_artifact_invalid', 'The approved MBTI-COMP-AT-35 artifact is required.');
        }
        if (! is_array($crossPackage) || (string) data_get($crossPackage, 'payload.artifact') !== self::CROSS_ARTIFACT) {
            $errors[] = $this->issue('packages.cross_type.artifact', 'cross_type_artifact_invalid', 'The approved MBTI-CMS-06 cross-type artifact is required.');
        }

        $rows = [];
        if (is_array($atPackage)) {
            $assets = data_get($atPackage, 'payload.assets');
            if (! is_array($assets) || count($assets) !== 16) {
                $errors[] = $this->issue('packages.at.assets', 'at_asset_count_must_be_16', 'The A/T package must contain exactly sixteen A/T comparison assets.');
            } else {
                foreach ($assets as $asset) {
                    if (! is_array($asset)) {
                        $errors[] = $this->issue('packages.at.assets', 'at_asset_not_object', 'Every A/T comparison asset must be an object.');

                        continue;
                    }
                    $rows[] = $this->rowPlan($asset, 'at', count($rows), $errors, $warnings);
                }
            }
        }
        if (is_array($crossPackage)) {
            $assets = data_get($crossPackage, 'payload.assets');
            $crossAssets = is_array($assets) ? array_values(array_filter($assets, static fn (mixed $asset): bool => is_array($asset) && ($asset['page_type'] ?? null) === 'hot_comparison')) : [];
            if (count($crossAssets) !== 4) {
                $errors[] = $this->issue('packages.cross_type.assets', 'cross_type_asset_count_must_be_4', 'The cross-type source package must select exactly four hot comparison assets.');
            }
            foreach ($crossAssets as $asset) {
                $rows[] = $this->rowPlan($asset, 'cross_type', count($rows), $errors, $warnings);
            }
        }

        $slugs = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['slug'] ?? ''), $rows)));
        if (count($rows) !== 20) {
            $errors[] = $this->issue('records', 'record_count_must_be_20', 'The full comparison dry-run must normalize exactly twenty records.');
        }
        if (count($slugs) !== count(array_unique($slugs))) {
            $errors[] = $this->issue('records', 'duplicate_slug', 'Each normalized comparison slug must be unique.');
        }
        $atCount = count(array_filter($rows, static fn (array $row): bool => ($row['comparison_kind'] ?? null) === 'at'));
        $crossCount = count(array_filter($rows, static fn (array $row): bool => ($row['comparison_kind'] ?? null) === 'cross_type'));
        $repairCount = count(array_filter($rows, static fn (array $row): bool => ($row['audit_status'] ?? null) === 'needs_content_repair'));
        $verifyCount = count(array_filter($rows, static fn (array $row): bool => ($row['audit_status'] ?? null) === 'verify_only'));
        if ($atCount !== 16 || $crossCount !== 4 || $repairCount !== 15 || $verifyCount !== 5) {
            $errors[] = $this->issue('records.audit_status', 'comparison_cohort_mismatch', 'The approved batch must contain 16 A/T, 4 cross-type, 15 repair, and 5 verify-only records.');
        }
        $crossSlugs = array_values(array_map(static fn (array $row): string => (string) ($row['slug'] ?? ''), array_values(array_filter($rows, static fn (array $row): bool => ($row['comparison_kind'] ?? null) === 'cross_type'))));
        sort($crossSlugs, SORT_STRING);
        $expectedCrossSlugs = self::EXPECTED_CROSS_SLUGS;
        sort($expectedCrossSlugs, SORT_STRING);
        if ($crossSlugs !== $expectedCrossSlugs) {
            $errors[] = $this->issue('records.cross_type', 'cross_type_cohort_mismatch', 'The approved cross-type comparison cohort does not match the four fixed Chinese MBTI routes.');
        }

        $hashes = array_map(static fn (array $package): string => (string) ($package['sha256'] ?? ''), $packages);
        sort($hashes, SORT_STRING);

        return [
            'artifact' => 'MBTI-CMS-COMP-38-FULL-DRY-RUN',
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
            'at_comparison_count' => $atCount,
            'cross_type_comparison_count' => $crossCount,
            'repair_record_count' => $repairCount,
            'verify_only_record_count' => $verifyCount,
            'source_packages' => array_map(static fn (array $package): array => [
                'role' => (string) ($package['role'] ?? ''),
                'path' => (string) ($package['path'] ?? ''),
                'sha256' => (string) ($package['sha256'] ?? ''),
                'artifact' => (string) data_get($package, 'payload.artifact', ''),
            ], $packages),
            'idempotency_key' => hash('sha256', implode('|', $hashes)),
            'field_mapping_contract' => $this->fieldMappingContract(),
            'expected_public_state' => ['is_indexable' => true, 'robots' => 'index,follow', 'sitemap_eligible' => true, 'llms_eligible' => true],
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string,mixed> $asset @param list<array<string,string>> $errors @param list<array<string,string>> $warnings @return array<string,mixed> */
    private function rowPlan(array $asset, string $kind, int $index, array &$errors, array &$warnings): array
    {
        $prefix = 'records.'.$index;
        $path = trim((string) ($asset['path'] ?? ''));
        $slug = basename($path);
        $cms = is_array($asset['cms_fields'] ?? null) ? $asset['cms_fields'] : null;
        $status = trim((string) ($asset['audit_status'] ?? ($kind === 'cross_type' ? 'verify_only' : '')));
        $identity = $kind === 'at'
            ? $this->atIdentity($asset, $path, $prefix, $errors)
            : $this->crossIdentity($asset, $path, $prefix, $errors);

        if (trim((string) ($asset['asset_id'] ?? '')) === '') {
            $errors[] = $this->issue($prefix.'.asset_id', 'asset_id_missing', 'Comparison assets require an asset id.');
        }
        if ($kind === 'at' && (string) ($asset['framework'] ?? '') !== 'mbti64') {
            $errors[] = $this->issue($prefix.'.framework', 'at_framework_invalid', 'A/T comparison assets must declare the mbti64 framework.');
        }
        if ($kind === 'cross_type' && (string) ($asset['framework'] ?? '') !== 'mbti_comparison') {
            $errors[] = $this->issue($prefix.'.framework', 'cross_type_framework_invalid', 'Cross-type comparison assets must declare the mbti_comparison framework.');
        }
        if (! in_array($status, ['needs_content_repair', 'verify_only'], true)) {
            $errors[] = $this->issue($prefix.'.audit_status', 'audit_status_invalid', 'Comparison assets must be repair candidates or verify-only records.');
        }
        $this->validateSafetyAndAuthority($asset, $kind, $prefix, $errors);

        if ($status === 'needs_content_repair') {
            $this->validateCmsFields($cms, $kind, $prefix, $errors);
        } elseif ($cms !== null && $kind === 'at') {
            $warnings[] = $this->issue($prefix.'.cms_fields', 'verify_only_has_cms_fields', 'The A/T verify-only record is not staged and should not carry rewrite fields.');
        } elseif ($kind === 'cross_type') {
            $this->validateCmsFields($cms, $kind, $prefix, $errors);
        }

        $existing = $kind === 'at'
            ? $this->existingAtTarget((string) ($identity['base_type_code'] ?? ''), $status === 'verify_only')
            : $this->existingCrossTypeTarget($slug, (string) ($identity['left_type_code'] ?? ''), (string) ($identity['right_type_code'] ?? ''));
        if (($existing['matched'] ?? false) !== true) {
            $errors[] = $this->issue($prefix.'.target', 'existing_published_target_missing', 'A matching published public CMS target must exist before this dry-run can prepare the asset.');
        } elseif (($existing['effective_indexable'] ?? false) !== true) {
            $errors[] = $this->issue($prefix.'.target', 'existing_target_not_indexable', 'The matching public comparison target must remain indexable before this dry-run can prepare the asset.');
        }

        $mapping = $cms === null ? null : $this->contentMapping($cms, $kind, $path);

        return [
            'position' => $index + 1,
            'asset_id' => (string) ($asset['asset_id'] ?? ''),
            'slug' => $slug,
            'target_path' => $path,
            'canonical_target' => $path,
            'locale' => 'zh-CN',
            'page_type' => 'comparison',
            'comparison_kind' => $kind,
            'identity' => $identity,
            'target' => $kind === 'at' ? [
                'target_model' => 'App\\Models\\PersonalityProfileSection',
                'target_table' => 'personality_profile_sections',
                'section_key' => 'mbti64_comparison_a_vs_t',
                'lookup' => ['org_id' => 0, 'locale' => 'zh-CN', 'base_type_code' => $identity['base_type_code'] ?? ''],
            ] : [
                'target_model' => 'App\\Models\\MbtiCrossTypeComparisonAuthority',
                'target_table' => 'mbti_cross_type_comparison_authorities',
                'lookup' => ['org_id' => 0, 'locale' => 'zh-CN', 'slug' => $slug],
            ],
            'existing_target' => $existing,
            'audit_status' => $status,
            'action' => $status === 'verify_only'
                ? 'would_verify_existing_'.($kind === 'at' ? 'at_comparison_only' : 'cross_type_comparison_only')
                : 'would_stage_existing_at_comparison_draft',
            'content_mapping' => $mapping,
            'source_refs' => array_values((array) ($asset['source_refs'] ?? [])),
            'expected_public_state' => ['is_indexable' => true, 'robots' => 'index,follow', 'sitemap_eligible' => true, 'llms_eligible' => true],
            'write_mode_in_this_pr' => 'not_supported',
        ];
    }

    /** @param array<string,mixed> $asset @param list<array<string,string>> $errors @return array<string,string> */
    private function atIdentity(array $asset, string $path, string $prefix, array &$errors): array
    {
        $pair = is_array($asset['comparison_pair'] ?? null) ? $asset['comparison_pair'] : [];
        $left = strtoupper(trim((string) ($pair['left'] ?? '')));
        $right = strtoupper(trim((string) ($pair['right'] ?? '')));
        $leftMatches = [];
        $rightMatches = [];
        $pathMatches = [];
        $leftIsVariant = preg_match('~^([A-Z]{4})-A$~', $left, $leftMatches) === 1;
        $rightIsVariant = preg_match('~^([A-Z]{4})-T$~', $right, $rightMatches) === 1;
        $pathMatchesPair = preg_match('~^/zh/personality/([a-z]{4})-a-vs-\\1-t$~', $path, $pathMatches) === 1;
        $baseType = $leftIsVariant ? (string) ($leftMatches[1] ?? '') : '';
        if ((string) ($asset['page_type'] ?? '') !== 'at_comparison' || (string) ($asset['locale'] ?? '') !== 'zh') {
            $errors[] = $this->issue($prefix.'.route', 'at_route_type_or_locale_invalid', 'A/T comparisons must use the Chinese at_comparison route contract.');
        }
        if (! $leftIsVariant || ! $rightIsVariant || ! $pathMatchesPair
            || ! in_array($baseType, PersonalityProfile::BASE_TYPE_CODES, true)
            || (string) ($rightMatches[1] ?? '') !== $baseType
            || strtoupper((string) ($pathMatches[1] ?? '')) !== $baseType) {
            $errors[] = $this->issue($prefix.'.identity', 'at_identity_invalid', 'A/T path, base type, and comparison pair must agree.');
        }

        return ['base_type_code' => $baseType, 'left_type_code' => $left, 'right_type_code' => $right];
    }

    /** @param array<string,mixed> $asset @param list<array<string,string>> $errors @return array<string,string> */
    private function crossIdentity(array $asset, string $path, string $prefix, array &$errors): array
    {
        $pair = is_array($asset['comparison_pair'] ?? null) ? $asset['comparison_pair'] : [];
        $left = strtoupper(trim((string) ($pair['left'] ?? '')));
        $right = strtoupper(trim((string) ($pair['right'] ?? '')));
        if ((string) ($asset['page_type'] ?? '') !== 'hot_comparison' || ! in_array((string) ($asset['locale'] ?? ''), ['zh', 'zh-CN'], true)
            || preg_match('~^/zh/personality/([a-z]{4})-vs-([a-z]{4})$~', $path, $matches) !== 1
            || strtoupper((string) ($matches[1] ?? '')) !== $left || strtoupper((string) ($matches[2] ?? '')) !== $right
            || $left === $right || ! in_array($left, PersonalityProfile::BASE_TYPE_CODES, true) || ! in_array($right, PersonalityProfile::BASE_TYPE_CODES, true)) {
            $errors[] = $this->issue($prefix.'.identity', 'cross_type_identity_invalid', 'Cross-type path and distinct MBTI pair must agree on a Chinese public route.');
        }

        return ['left_type_code' => $left, 'right_type_code' => $right];
    }

    /** @param array<string,mixed>|null $cms @param list<array<string,string>> $errors */
    private function validateCmsFields(?array $cms, string $kind, string $prefix, array &$errors): void
    {
        if ($cms === null) {
            $errors[] = $this->issue($prefix.'.cms_fields', 'cms_fields_missing', 'Repair and cross-type verification records require CMS content fields.');

            return;
        }
        foreach (['title', 'h1', 'meta_description', $kind === 'at' ? 'direct_answer' : 'answer_block'] as $field) {
            if (trim((string) ($cms[$field] ?? '')) === '') {
                $errors[] = $this->issue($prefix.'.cms_fields.'.$field, 'required_field_missing', 'Required CMS field is missing.');
            }
        }
        $sections = is_array($cms[$kind === 'at' ? 'sections' : 'modules'] ?? null) ? $cms[$kind === 'at' ? 'sections' : 'modules'] : [];
        $keys = [];
        foreach ($sections as $section) {
            if (! is_array($section) || trim((string) ($section['key'] ?? '')) === '' || trim((string) ($section['body'] ?? '')) === '') {
                $errors[] = $this->issue($prefix.'.cms_fields.sections', 'section_shape_invalid', 'Every comparison section requires a key and visible body.');

                continue;
            }
            $keys[] = (string) $section['key'];
        }
        $requiredSectionKeys = $kind === 'at'
            ? self::REQUIRED_AT_SECTION_KEYS
            : self::REQUIRED_CROSS_SECTION_KEYS;
        foreach ($requiredSectionKeys as $required) {
            if (! in_array($required, $keys, true)) {
                $errors[] = $this->issue($prefix.'.cms_fields.sections', 'required_section_missing', 'Required comparison section missing: '.$required.'.');
            }
        }
        $quick = is_array($cms['quick_judgment_table'] ?? null) ? $cms['quick_judgment_table'] : [];
        if (count($quick) < 4 || count(array_filter($quick, static fn (mixed $row): bool => is_array($row) && trim((string) ($row['dimension'] ?? '')) !== '' && trim((string) ($row[$kind === 'at' ? 'a' : 'left'] ?? '')) !== '' && trim((string) ($row[$kind === 'at' ? 't' : 'right'] ?? '')) !== '')) !== count($quick)) {
            $errors[] = $this->issue($prefix.'.cms_fields.quick_judgment_table', 'quick_judgment_table_invalid', 'Comparison content requires four complete quick-judgment rows.');
        }
        $faq = is_array($cms['faq'] ?? null) ? $cms['faq'] : [];
        if (count($faq) < 5 || count(array_filter($faq, static fn (mixed $item): bool => is_array($item) && trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== '')) !== count($faq)) {
            $errors[] = $this->issue($prefix.'.cms_fields.faq', 'faq_minimum_or_shape_invalid', 'Comparison content requires at least five visible FAQ items.');
        }
        $links = is_array($cms['internal_links'] ?? null) ? $cms['internal_links'] : [];
        if (count($links) < 5) {
            $errors[] = $this->issue($prefix.'.cms_fields.internal_links', 'internal_link_minimum_not_met', 'Comparison content requires at least five internal links.');
        }
        foreach ($links as $linkIndex => $link) {
            $href = is_array($link) ? trim((string) ($link['href'] ?? '')) : '';
            if ($href === '' || preg_match(self::FORBIDDEN_ROUTE, $href) === 1 || str_contains($href, '?') || (($link['safe_public_route'] ?? true) !== true)) {
                $errors[] = $this->issue($prefix.'.cms_fields.internal_links.'.$linkIndex, 'unsafe_internal_link', 'Internal links must be explicitly safe canonical public paths without query strings.');
            }
        }
    }

    /** @param array<string,mixed> $asset @param list<array<string,string>> $errors */
    private function validateSafetyAndAuthority(array $asset, string $kind, string $prefix, array &$errors): void
    {
        $policy = (array) ($asset['handoff_policy'] ?? []);
        if ($kind === 'at' && ($policy['artifact_only'] ?? false) !== true) {
            $errors[] = $this->issue($prefix.'.handoff_policy.artifact_only', 'artifact_only_required', 'A/T assets must remain artifact-only during dry-run.');
        }
        if ($kind === 'at' && (array) ($asset['source_refs'] ?? []) === []) {
            $errors[] = $this->issue($prefix.'.source_refs', 'source_refs_missing', 'A/T assets require source references.');
        }
        foreach (['cms_write_attempted', 'production_import_attempted', 'frontend_runtime_change_attempted', 'sitemap_llms_mutation_attempted', 'gsc_mutation_attempted', 'production_deploy_attempted', 'db_migration_attempted', 'frontend_local_editorial_fallback_added'] as $key) {
            if (($policy[$key] ?? false) === true) {
                $errors[] = $this->issue($prefix.'.handoff_policy.'.$key, 'handoff_side_effect_declared', 'Comparison artifact must not declare a completed write or release side effect.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function existingAtTarget(string $baseType, bool $requiresExistingSection): array
    {
        $profile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)->where('locale', 'zh-CN')
            ->where('canonical_type_code', $baseType)->where('status', 'published')->where('is_public', true)->first();
        $section = $profile instanceof PersonalityProfile
            ? PersonalityProfileSection::query()->withoutGlobalScopes()->where('profile_id', $profile->id)->where('section_key', 'mbti64_comparison_a_vs_t')->where('is_enabled', true)->first()
            : null;

        return [
            'matched' => $profile instanceof PersonalityProfile && (! $requiresExistingSection || $section instanceof PersonalityProfileSection),
            'profile_id' => $profile?->id,
            'section_id' => $section?->id,
            'profile_is_indexable' => $profile?->is_indexable,
            'effective_indexable' => $profile instanceof PersonalityProfile && (bool) $profile->is_indexable,
        ];
    }

    /** @return array<string,mixed> */
    private function existingCrossTypeTarget(string $slug, string $left, string $right): array
    {
        $authority = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('locale', 'zh-CN')->where('slug', $slug)
            ->where('left_type_code', $left)->where('right_type_code', $right)
            ->where('is_public', true)->where('publish_status', 'published')->first();

        return [
            'matched' => $authority instanceof MbtiCrossTypeComparisonAuthority,
            'authority_id' => $authority?->id,
            'is_indexable' => $authority?->is_indexable,
            'indexability_status' => $authority?->indexability_status,
            'effective_indexable' => $authority instanceof MbtiCrossTypeComparisonAuthority && (bool) $authority->is_indexable,
        ];
    }

    /** @param array<string,mixed> $cms @return array<string,mixed> */
    private function contentMapping(array $cms, string $kind, string $canonical): array
    {
        $sections = (array) ($cms[$kind === 'at' ? 'sections' : 'modules'] ?? []);

        return [
            'seo' => ['title' => $cms['title'] ?? null, 'description' => $cms['meta_description'] ?? null, 'h1' => $cms['h1'] ?? null],
            'direct_answer' => $cms[$kind === 'at' ? 'direct_answer' : 'answer_block'] ?? null,
            'section_keys' => array_values(array_map(static fn (mixed $section): string => is_array($section) ? (string) ($section['key'] ?? '') : '', $sections)),
            'quick_judgment_row_count' => count((array) ($cms['quick_judgment_table'] ?? [])),
            'faq_count' => count((array) ($cms['faq'] ?? [])),
            'internal_link_count' => count((array) ($cms['internal_links'] ?? [])),
            'jsonld_source_fields' => [
                'canonical' => $canonical,
                'headline' => $cms['h1'] ?? null,
                'description' => $cms['meta_description'] ?? null,
                'faq_page' => count((array) ($cms['faq'] ?? [])) > 0,
                'collection_page' => true,
                'breadcrumb_list' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fieldMappingContract(): array
    {
        return [
            'at_comparison' => ['target_table' => 'personality_profile_sections', 'section_key' => 'mbti64_comparison_a_vs_t', 'locale_mapping' => ['zh' => 'zh-CN']],
            'cross_type_comparison' => ['target_table' => 'mbti_cross_type_comparison_authorities', 'readmodel' => 'comparison_public_projection_v1', 'locale_mapping' => ['zh-CN' => 'zh-CN']],
            'required_content' => ['direct_answer', 'quick_judgment_table', 'faq', 'internal_links', 'jsonld_source_fields', 'canonical'],
            'no_write' => true,
        ];
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
