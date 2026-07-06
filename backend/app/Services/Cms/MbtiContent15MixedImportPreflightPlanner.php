<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class MbtiContent15MixedImportPreflightPlanner
{
    private const EXPECTED_SOURCE_PACKAGE_SHA256 = '75244fa4af3c234851519eba5a426daf8766c13e7c4b2bc9e94d5a5855ce6ccb';

    private const EXPECTED_AUTHORIZATION_PAYLOAD_SHA256 = 'be0d1bb584c15f383322c9e5aff560709c46ea1e34d135cb9ced6d1e4601fe15';

    private const EXPECTED_IMPORT_SCOPE_MODE = 'top_blocker_batch_only';

    private const EXPECTED_RECORD_COUNT = 9;

    private const PROFILE_SECTION_KEYS = [
        'direct_answer',
        'who_it_fits',
        'who_it_does_not_fit',
        'common_misunderstanding',
        'at_difference',
        'career_scenario',
        'relationship_scenario',
        'stress_scenario',
    ];

    private const COMPARISON_SECTION_KEYS = [
        'direct_answer',
        'quick_judgment_table',
        'easy_misread',
        'real_scenario_differences',
        'do_not_misjudge',
    ];

    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~(?:^|["\'\s(])/(?:[a-z]{2}/)?(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

    private const FORBIDDEN_QUERY_PATTERN =
        '/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i';

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  array<string,string>  $options
     * @return array<string,mixed>
     */
    public function plan(array $package, array $authorizationPackage, array $options): array
    {
        $errors = [];
        $warnings = [];
        $records = $this->records($package, $errors);
        $authorizationRecords = $this->authorizationRecords($authorizationPackage, $errors);

        $this->validateTopLevel($package, $authorizationPackage, $options, $errors, $warnings);
        $this->validateForbiddenRoutes($package, $authorizationPackage, $errors);

        $authorizationBySourceId = [];
        foreach ($authorizationRecords as $record) {
            if (is_array($record)) {
                $authorizationBySourceId[(string) ($record['source_dry_run_record_id'] ?? '')] = $record;
            }
        }

        $rowPlans = [];
        foreach ($records as $index => $record) {
            if (! is_array($record)) {
                $errors[] = $this->issue('records.'.(string) $index, 'record_not_object', 'Each CONTENT-15 import record must be a JSON object.');

                continue;
            }

            $rowPlans[] = $this->rowPlan($record, $authorizationBySourceId, $index, $errors, $warnings);
        }

        $profilePlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['kind'] ?? null) === 'profile'
        ));
        $comparisonPlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['kind'] ?? null) === 'comparison'
        ));
        $atPlans = array_values(array_filter(
            $comparisonPlans,
            static fn (array $row): bool => ($row['identity']['comparison_kind'] ?? null) === 'at'
        ));
        $crossTypePlans = array_values(array_filter(
            $comparisonPlans,
            static fn (array $row): bool => ($row['identity']['comparison_kind'] ?? null) === 'cross_type'
        ));

        return [
            'artifact' => 'MBTI-CMS-26-CONTENT15-MIXED-IMPORT-PREFLIGHT',
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
            'package_file_sha256' => $options['package_file_sha256'] ?? '',
            'authorization_file_sha256' => $options['authorization_file_sha256'] ?? '',
            'source_package_sha256' => (string) data_get($package, 'exact_package.package_sha256', ''),
            'authorization_payload_sha256' => (string) data_get($authorizationPackage, 'authorization_package.exact_authorization_payload_sha256', ''),
            'import_scope_mode' => (string) data_get($authorizationPackage, 'authorization_package.import_scope_mode', ''),
            'record_count' => count($records),
            'authorization_record_count' => count($authorizationRecords),
            'profile_record_count' => count($profilePlans),
            'comparison_record_count' => count($comparisonPlans),
            'at_comparison_count' => count($atPlans),
            'cross_type_comparison_count' => count($crossTypePlans),
            'field_mapping_contract' => $this->fieldMappingContract(),
            'production_import_gate' => $this->productionImportGate(),
            'rows' => $rowPlans,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  list<array<string,string>>  $errors
     * @return list<mixed>
     */
    private function records(array $package, array &$errors): array
    {
        $records = $package['records'] ?? null;
        if (! is_array($records)) {
            $errors[] = $this->issue('records', 'records_missing_or_not_array', 'CONTENT-15 final dry-run package must contain records as an array.');

            return [];
        }

        return array_values($records);
    }

    /**
     * @param  array<string,mixed>  $authorizationPackage
     * @param  list<array<string,string>>  $errors
     * @return list<mixed>
     */
    private function authorizationRecords(array $authorizationPackage, array &$errors): array
    {
        $records = data_get($authorizationPackage, 'authorization_package.records');
        if (! is_array($records)) {
            $errors[] = $this->issue('authorization_package.records', 'authorization_records_missing_or_not_array', 'CMS-23 authorization package must contain authorization_package.records as an array.');

            return [];
        }

        return array_values($records);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  array<string,string>  $options
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     */
    private function validateTopLevel(array $package, array $authorizationPackage, array $options, array &$errors, array &$warnings): void
    {
        if ((string) ($package['id'] ?? '') !== 'MBTI-CMS-22') {
            $warnings[] = $this->issue('id', 'unexpected_source_artifact_id', 'Expected MBTI-CMS-22 as the final dry-run package source.');
        }

        if ((string) ($authorizationPackage['id'] ?? '') !== 'MBTI-CMS-23') {
            $warnings[] = $this->issue('authorization_package.id', 'unexpected_authorization_artifact_id', 'Expected MBTI-CMS-23 as the authorization package source.');
        }

        $sourceSha = (string) data_get($package, 'exact_package.package_sha256', '');
        $expectedSourceSha = $options['expected_source_package_sha256'] !== ''
            ? $options['expected_source_package_sha256']
            : self::EXPECTED_SOURCE_PACKAGE_SHA256;
        if ($sourceSha !== $expectedSourceSha) {
            $errors[] = $this->issue('exact_package.package_sha256', 'source_package_sha256_mismatch', 'CMS-22 exact package sha256 does not match the authorized source package sha256.');
        }

        $authorizationSourceSha = (string) data_get($authorizationPackage, 'authorization_package.source_package_sha256', '');
        if ($authorizationSourceSha !== $sourceSha) {
            $errors[] = $this->issue('authorization_package.source_package_sha256', 'authorization_source_package_sha256_mismatch', 'CMS-23 source package sha256 must match CMS-22 exact package sha256.');
        }

        $authorizationSha = (string) data_get($authorizationPackage, 'authorization_package.exact_authorization_payload_sha256', '');
        $expectedAuthorizationSha = $options['expected_authorization_payload_sha256'] !== ''
            ? $options['expected_authorization_payload_sha256']
            : self::EXPECTED_AUTHORIZATION_PAYLOAD_SHA256;
        if ($authorizationSha !== $expectedAuthorizationSha) {
            $errors[] = $this->issue('authorization_package.exact_authorization_payload_sha256', 'authorization_payload_sha256_mismatch', 'CMS-23 authorization payload sha256 does not match the operator authorization package.');
        }

        $scopeMode = (string) data_get($authorizationPackage, 'authorization_package.import_scope_mode', '');
        $expectedScopeMode = $options['expected_import_scope_mode'] !== ''
            ? $options['expected_import_scope_mode']
            : self::EXPECTED_IMPORT_SCOPE_MODE;
        if ($scopeMode !== $expectedScopeMode) {
            $errors[] = $this->issue('authorization_package.import_scope_mode', 'import_scope_mode_mismatch', 'Import scope mode must remain top_blocker_batch_only.');
        }

        $expectedRecordCount = $options['expected_record_count'] !== ''
            ? (int) $options['expected_record_count']
            : self::EXPECTED_RECORD_COUNT;
        if (count((array) ($package['records'] ?? [])) !== $expectedRecordCount) {
            $errors[] = $this->issue('records', 'record_count_mismatch', 'CONTENT-15 final dry-run package must contain exactly '.$expectedRecordCount.' records.');
        }

        if (count((array) data_get($authorizationPackage, 'authorization_package.records', [])) !== $expectedRecordCount) {
            $errors[] = $this->issue('authorization_package.records', 'authorization_record_count_mismatch', 'CMS-23 authorization package must contain exactly '.$expectedRecordCount.' records.');
        }

        if ((bool) data_get($authorizationPackage, 'authorization_package.production_import_authorized', true) !== false) {
            $errors[] = $this->issue('authorization_package.production_import_authorized', 'unexpected_production_authorization_state', 'MBTI-CMS-26 only accepts the not-authorized package state and never writes CMS.');
        }
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @param  list<array<string,string>>  $errors
     */
    private function validateForbiddenRoutes(array $package, array $authorizationPackage, array &$errors): void
    {
        $json = json_encode([$package, $authorizationPackage], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            $errors[] = $this->issue('package', 'json_encode_failed', 'Packages could not be normalized for route safety scanning.');

            return;
        }

        if (preg_match(self::FORBIDDEN_PUBLIC_ROUTE_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'forbidden_public_route_pattern_present', 'CONTENT-15 import payload must not contain result/order/share/payment/history/private/account routes.');
        }

        if (preg_match(self::FORBIDDEN_QUERY_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'forbidden_query_pattern_present', 'CONTENT-15 import payload must not contain sensitive query keys.');
        }
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,array<string,mixed>>  $authorizationBySourceId
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     * @return array<string,mixed>
     */
    private function rowPlan(array $record, array $authorizationBySourceId, int $index, array &$errors, array &$warnings): array
    {
        $kind = (string) ($record['kind'] ?? '');
        $targetPath = $this->normalizePath((string) ($record['target_path'] ?? ''));
        $cmsKey = is_array($record['cms_key'] ?? null) ? $record['cms_key'] : [];
        $payload = is_array(data_get($record, 'dry_run_payload.payload')) ? data_get($record, 'dry_run_payload.payload') : [];
        $sectionKeys = (array) data_get($record, 'schema_validation.section_keys', []);
        $authorizationRecord = $authorizationBySourceId[(string) ($record['dry_run_record_id'] ?? '')] ?? null;
        $identity = $kind === 'comparison'
            ? $this->comparisonIdentity($record, $cmsKey, $targetPath, $errors, $index)
            : $this->profileIdentity($record, $cmsKey, $targetPath, $errors, $index);

        $this->validateRecordShape($record, $authorizationRecord, $payload, $sectionKeys, $errors, $warnings, $index);

        return [
            'position' => $index + 1,
            'dry_run_record_id' => (string) ($record['dry_run_record_id'] ?? ''),
            'authorization_record_id' => is_array($authorizationRecord) ? (string) ($authorizationRecord['authorization_record_id'] ?? '') : null,
            'kind' => $kind,
            'target_path' => $targetPath,
            'locale' => (string) ($record['locale'] ?? ''),
            'slug' => (string) ($record['slug'] ?? ''),
            'cms_resource' => (string) ($record['cms_resource'] ?? ''),
            'import_action' => (string) ($record['import_action'] ?? ''),
            'approval_state' => (string) ($record['approval_state'] ?? ''),
            'identity' => $identity,
            'target' => $this->targetFor($kind, $identity),
            'payload_sha256' => (string) ($record['exact_payload_sha256'] ?? ''),
            'authorization_payload_sha256' => is_array($authorizationRecord) ? (string) ($authorizationRecord['exact_payload_sha256'] ?? '') : '',
            'section_keys' => array_values(array_map('strval', $sectionKeys)),
            'faq_count' => (int) data_get($record, 'schema_validation.faq_count', 0),
            'internal_link_count' => is_array($payload['internal_links'] ?? null) ? count((array) $payload['internal_links']) : 0,
            'seo_keys' => is_array($payload['seo'] ?? null) ? array_keys($payload['seo']) : [],
            'indexability_after_preflight' => [
                'index_eligible' => (bool) ($payload['index_eligible'] ?? false),
                'sitemap_eligible' => (bool) ($payload['sitemap_eligible'] ?? false),
                'llms_eligible' => (bool) ($payload['llms_eligible'] ?? false),
                'robots' => (string) ($payload['robots'] ?? ''),
            ],
            'action' => $this->actionFor($kind, $identity),
            'write_mode_in_this_pr' => 'not_supported',
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $cmsKey
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function profileIdentity(array $record, array $cmsKey, string $targetPath, array &$errors, int $index): array
    {
        $code = strtoupper((string) ($cmsKey['profile_code'] ?? $record['code'] ?? ''));
        if (! preg_match('/^[EINSFTJP]{4}-[AT]$/', $code)) {
            $errors[] = $this->issue('records.'.(string) $index.'.cms_key.profile_code', 'invalid_profile_code', 'Profile code must be a 32-personality A/T code.');
        }

        return [
            'runtime_type_code' => $code,
            'base_type_code' => substr($code, 0, 4),
            'variant_code' => substr($code, -1),
            'locale' => (string) ($record['locale'] ?? ''),
            'target_path' => $targetPath,
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $cmsKey
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function comparisonIdentity(array $record, array $cmsKey, string $targetPath, array &$errors, int $index): array
    {
        $left = strtoupper((string) ($cmsKey['left_code'] ?? ''));
        $right = strtoupper((string) ($cmsKey['right_code'] ?? ''));
        $slug = (string) ($cmsKey['comparison_slug'] ?? $record['slug'] ?? '');
        $kind = preg_match('/^[A-Z]{4}-A$/', $left) === 1
            && preg_match('/^[A-Z]{4}-T$/', $right) === 1
            && substr($left, 0, 4) === substr($right, 0, 4)
                ? 'at'
                : 'cross_type';

        if ($left === '' || $right === '') {
            $errors[] = $this->issue('records.'.(string) $index.'.cms_key', 'comparison_codes_missing', 'Comparison records require left_code and right_code.');
        }

        return [
            'comparison_kind' => $kind,
            'comparison_slug' => $slug,
            'left_type_code' => $left,
            'right_type_code' => $right,
            'base_type_code' => $kind === 'at' ? substr($left, 0, 4) : null,
            'locale' => (string) ($record['locale'] ?? ''),
            'target_path' => $targetPath,
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>|null  $authorizationRecord
     * @param  array<string,mixed>  $payload
     * @param  list<mixed>  $sectionKeys
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     */
    private function validateRecordShape(array $record, ?array $authorizationRecord, array $payload, array $sectionKeys, array &$errors, array &$warnings, int $index): void
    {
        $fieldPrefix = 'records.'.(string) $index;
        $kind = (string) ($record['kind'] ?? '');

        if (! in_array($kind, ['profile', 'comparison'], true)) {
            $errors[] = $this->issue($fieldPrefix.'.kind', 'unsupported_record_kind', 'CONTENT-15 mixed import supports only profile and comparison records.');
        }

        if (! is_array($authorizationRecord)) {
            $errors[] = $this->issue($fieldPrefix.'.dry_run_record_id', 'authorization_record_missing', 'Each CMS-22 record must have a matching CMS-23 authorization record.');
        } elseif ((string) ($authorizationRecord['exact_payload_sha256'] ?? '') !== (string) ($record['exact_payload_sha256'] ?? '')) {
            $errors[] = $this->issue($fieldPrefix.'.exact_payload_sha256', 'authorization_payload_record_sha_mismatch', 'CMS-22 record payload sha must match the CMS-23 authorization record payload sha.');
        }

        if ((string) ($record['approval_state'] ?? '') !== 'approved_for_final_dry_run') {
            $errors[] = $this->issue($fieldPrefix.'.approval_state', 'approval_state_not_final_dry_run_approved', 'Records must be approved for final dry-run before production import planning.');
        }

        if ((string) data_get($record, 'schema_validation.status', '') !== 'pass') {
            $errors[] = $this->issue($fieldPrefix.'.schema_validation.status', 'schema_validation_not_pass', 'Only schema-validation-pass records can enter the mixed import preflight.');
        }

        $requiredSections = $kind === 'profile' ? self::PROFILE_SECTION_KEYS : self::COMPARISON_SECTION_KEYS;
        foreach ($requiredSections as $requiredSection) {
            if (! in_array($requiredSection, $sectionKeys, true)) {
                $errors[] = $this->issue($fieldPrefix.'.schema_validation.section_keys', 'required_section_missing', 'Required section key is missing: '.$requiredSection);
            }
        }

        if ((int) data_get($record, 'schema_validation.faq_count', 0) < ($kind === 'profile' ? 6 : 4)) {
            $errors[] = $this->issue($fieldPrefix.'.schema_validation.faq_count', 'faq_count_below_content15_minimum', 'FAQ count is below the CONTENT-15 minimum.');
        }

        if ((bool) data_get($record, 'schema_validation.indexability_held', false) !== true) {
            $errors[] = $this->issue($fieldPrefix.'.schema_validation.indexability_held', 'indexability_not_held', 'CONTENT-15 import preflight requires indexability to remain held.');
        }

        if ((bool) ($payload['index_eligible'] ?? false) !== false || (bool) ($payload['sitemap_eligible'] ?? false) !== false || (bool) ($payload['llms_eligible'] ?? false) !== false) {
            $errors[] = $this->issue($fieldPrefix.'.dry_run_payload.payload', 'indexability_flags_not_fail_closed', 'index_eligible, sitemap_eligible, and llms_eligible must remain false before MBTI-INDEX-24.');
        }

        if (trim((string) data_get($payload, 'seo.title', '')) === '') {
            $errors[] = $this->issue($fieldPrefix.'.dry_run_payload.payload.seo.title', 'seo_title_missing', 'SEO title is required for CMS import preflight.');
        }

        if (! is_array($payload['internal_links'] ?? null) || count((array) $payload['internal_links']) < 3) {
            $warnings[] = $this->issue($fieldPrefix.'.dry_run_payload.payload.internal_links', 'internal_link_count_low', 'Expected at least three internal links before import.');
        }
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    private function targetFor(string $kind, array $identity): array
    {
        if ($kind === 'profile') {
            return [
                'target_table' => 'personality_profile_variant_revisions',
                'target_model' => 'App\\Models\\PersonalityProfileVariantRevision',
                'planned_live_tables' => [
                    'personality_profile_variant_sections',
                    'personality_profile_variant_seo_meta',
                ],
                'lookup' => [
                    'locale' => $identity['locale'] ?? '',
                    'runtime_type_code' => $identity['runtime_type_code'] ?? '',
                ],
            ];
        }

        if (($identity['comparison_kind'] ?? null) === 'at') {
            return [
                'target_table' => 'personality_profile_sections',
                'target_model' => 'App\\Models\\PersonalityProfileSection',
                'section_key' => 'mbti64_comparison_a_vs_t',
                'lookup' => [
                    'locale' => $identity['locale'] ?? '',
                    'base_type_code' => $identity['base_type_code'] ?? '',
                ],
            ];
        }

        return [
            'target_table' => 'planned_mbti_cross_type_comparison_authority',
            'target_model' => 'backend_authority_layer',
            'planned_live_tables' => [
                'mbti_cross_type_comparison_authority',
                'comparison_public_projection_v1',
            ],
            'lookup' => [
                'locale' => $identity['locale'] ?? '',
                'comparison_slug' => $identity['comparison_slug'] ?? '',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $identity
     */
    private function actionFor(string $kind, array $identity): string
    {
        if ($kind === 'profile') {
            return 'would_stage_profile_content_draft';
        }

        return ($identity['comparison_kind'] ?? null) === 'at'
            ? 'would_stage_at_comparison_content_draft'
            : 'would_stage_cross_type_comparison_content_draft';
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldMappingContract(): array
    {
        return [
            'profile_target_tables' => [
                'personality_profile_variant_revisions',
                'personality_profile_variant_sections',
                'personality_profile_variant_seo_meta',
            ],
            'at_comparison_target_tables' => [
                'personality_profile_sections',
            ],
            'cross_type_comparison_target_tables' => [
                'planned_mbti_cross_type_comparison_authority',
                'comparison_public_projection_v1',
            ],
            'runtime_release_blocked_until' => [
                'MBTI-CMS-27 production import execution',
                'MBTI-CMS-28 post-import read-only verification',
                'MBTI-INDEX-24 sitemap/llms/indexability gate',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function productionImportGate(): array
    {
        return [
            'current_pr' => 'MBTI-CMS-26',
            'write_supported_in_this_pr' => false,
            'next_write_task' => 'MBTI-CMS-27',
            'required_exact_authorization' => [
                'source_package_sha256' => self::EXPECTED_SOURCE_PACKAGE_SHA256,
                'authorization_payload_sha256' => self::EXPECTED_AUTHORIZATION_PAYLOAD_SHA256,
                'import_scope_mode' => self::EXPECTED_IMPORT_SCOPE_MODE,
                'record_count' => self::EXPECTED_RECORD_COUNT,
            ],
        ];
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $parsed = parse_url($trimmed, PHP_URL_PATH);
        $pathOnly = is_string($parsed) ? $parsed : $trimmed;

        return '/'.ltrim($pathOnly, '/');
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
