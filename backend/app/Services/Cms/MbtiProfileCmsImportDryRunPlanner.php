<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class MbtiProfileCmsImportDryRunPlanner
{
    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~(?:^|["\'\s(])/(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

    private const FORBIDDEN_QUERY_PATTERN =
        '/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i';

    private const REQUIRED_SEO_FIELDS = [
        'seo_title',
        'seo_description',
        'breadcrumb_title',
        'h1',
        'quick_answer_summary',
    ];

    private const REQUIRED_CONTENT_FIELDS = [
        'quick_answer',
        'definition',
        'suitable_for',
        'not_suitable_for',
        'common_misconception',
        'base_type_difference',
        'at_difference',
        'career_scenarios',
        'relationship_scenarios',
        'stress_scenarios',
    ];

    private const FIRST_CLASS_PROFILE_FIELDS = [
        'url',
        'locale',
        'page_type',
        'canonical_target',
        'seo.seo_title',
        'seo.seo_description',
        'seo.breadcrumb_title',
        'seo.h1',
        'seo.quick_answer_summary',
        'content.quick_answer',
        'content.definition',
        'content.suitable_for',
        'content.not_suitable_for',
        'content.common_misconception',
        'content.base_type_difference',
        'content.at_difference',
        'content.career_scenarios',
        'content.relationship_scenarios',
        'content.stress_scenarios',
        'faq',
        'internal_links',
    ];

    private const STRUCTURED_METADATA_FIELDS = [
        'primary_query',
        'secondary_queries',
        'gsc_query_evidence',
        'target_intent',
        'method_boundary',
        'trademark_boundary',
        'claim_risk_notes',
        'qa_flags_for_codex',
        'route_safety',
        'source_document',
        'status',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function plan(array $package, string $sourceSha256 = ''): array
    {
        $errors = [];
        $warnings = [];
        $rows = $this->rows($package, $errors);
        $rowPlans = [];

        $this->validateTopLevel($package, $warnings);
        $this->validateForbiddenRoutes($package, $errors);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->issue('rows.'.(string) $index, 'row_not_object', 'Each package row must be a JSON object.');

                continue;
            }

            $rowPlans[] = $this->rowPlan($row, $index, $errors, $warnings);
        }

        $profilePlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['page_type'] ?? null) === 'variant'
        ));
        $comparisonPlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['page_type'] ?? null) === 'comparison'
        ));

        return [
            'artifact' => 'MBTI-CMS-12-PROFILE-IMPORT-DRY-RUN',
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
            'source_sha256' => $sourceSha256,
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'row_count' => count($rows),
            'profile_row_count' => count($profilePlans),
            'comparison_row_count' => count($comparisonPlans),
            'field_mapping_contract' => $this->fieldMappingContract(),
            'dry_run_write_guard_contract' => $this->dryRunWriteGuardContract(),
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
    private function rows(array $package, array &$errors): array
    {
        $rows = $package['rows'] ?? $package['profiles'] ?? null;
        if (! is_array($rows)) {
            $errors[] = $this->issue('rows', 'rows_missing_or_not_array', 'Profile package must contain rows or profiles as an array.');

            return [];
        }

        return array_values($rows);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  list<array<string,string>>  $warnings
     */
    private function validateTopLevel(array $package, array &$warnings): void
    {
        $line = trim((string) ($package['content_line'] ?? ''));
        if ($line !== '' && $line !== 'profile') {
            $warnings[] = $this->issue('content_line', 'unexpected_content_line', 'MBTI-CMS-12 expects the profile content line.');
        }

        $version = trim((string) ($package['version'] ?? ''));
        if ($version === '') {
            $warnings[] = $this->issue('version', 'missing_package_version', 'Package version was not provided.');
        }
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  list<array<string,string>>  $errors
     */
    private function validateForbiddenRoutes(array $package, array &$errors): void
    {
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            $errors[] = $this->issue('package', 'json_encode_failed', 'Package could not be normalized for route safety scanning.');

            return;
        }

        if (preg_match(self::FORBIDDEN_PUBLIC_ROUTE_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'forbidden_public_route_pattern_present', 'Package active payload must not contain result/order/share/payment/history/private/account routes.');
        }

        if (preg_match(self::FORBIDDEN_QUERY_PATTERN, $json) === 1) {
            $errors[] = $this->issue('package', 'forbidden_query_pattern_present', 'Package active payload must not contain sensitive query keys.');
        }
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     * @return array<string,mixed>
     */
    private function rowPlan(array $row, int $index, array &$errors, array &$warnings): array
    {
        $url = $this->normalizePath((string) ($row['url'] ?? ''));
        $locale = (string) ($row['locale'] ?? '');
        $pageType = (string) ($row['page_type'] ?? '');
        $seo = is_array($row['seo'] ?? null) ? $row['seo'] : [];
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $identity = $this->parseIdentity($url, $locale, $pageType, $errors, $index);

        $this->validateRowShape($row, $seo, $content, $errors, $warnings, $index);

        return [
            'position' => $index + 1,
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'canonical_target' => $this->normalizePath((string) ($row['canonical_target'] ?? '')),
            'identity' => $identity,
            'target' => $this->targetFor($identity),
            'draft_revision' => $this->draftRevisionFor($identity, $url),
            'first_class_field_destinations' => $this->firstClassDestinations(),
            'structured_metadata_snapshot_path' => 'personality_profile_variant_revisions.snapshot_json.mbti_cms_12_profile_import_dry_run_v1.structured_metadata',
            'content_section_keys' => array_keys($content),
            'seo_keys' => array_keys($seo),
            'faq_count' => is_array($row['faq'] ?? null) ? count((array) $row['faq']) : 0,
            'internal_link_count' => is_array($row['internal_links'] ?? null) ? count((array) $row['internal_links']) : 0,
            'draft_state_after_import' => [
                'status' => 'draft',
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'published_at' => null,
            ],
            'action' => 'would_stage_profile_variant_revision',
            'write_mode_in_this_pr' => 'not_supported',
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $seo
     * @param  array<string,mixed>  $content
     * @param  list<array<string,string>>  $errors
     * @param  list<array<string,string>>  $warnings
     */
    private function validateRowShape(array $row, array $seo, array $content, array &$errors, array &$warnings, int $index): void
    {
        foreach (self::REQUIRED_SEO_FIELDS as $field) {
            if (trim((string) ($seo[$field] ?? '')) === '') {
                $errors[] = $this->issue('rows.'.(string) $index.'.seo.'.$field, 'required_seo_field_missing', 'Required SEO field is missing.');
            }
        }

        foreach (self::REQUIRED_CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $content)) {
                $errors[] = $this->issue('rows.'.(string) $index.'.content.'.$field, 'required_content_field_missing', 'Required profile answer block field is missing.');
            }
        }

        if (! is_array($row['faq'] ?? null) || count((array) $row['faq']) < 2) {
            $errors[] = $this->issue('rows.'.(string) $index.'.faq', 'faq_minimum_not_met', 'Profile dry-run rows need at least two FAQ entries.');
        }

        if (! is_array($row['internal_links'] ?? null) || count((array) $row['internal_links']) < 3) {
            $warnings[] = $this->issue('rows.'.(string) $index.'.internal_links', 'internal_link_minimum_not_met', 'Profile rows should include at least three related public links before promotion.');
        }
    }

    /**
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function parseIdentity(string $url, string $locale, string $pageType, array &$errors, int $index): array
    {
        if (! in_array($locale, ['en', 'zh-CN'], true)) {
            $errors[] = $this->issue('rows.'.(string) $index.'.locale', 'unsupported_locale', 'Only en and zh-CN are supported.');
        }

        if ($pageType === 'comparison') {
            $errors[] = $this->issue('rows.'.(string) $index.'.page_type', 'comparison_rows_out_of_scope', 'MBTI-CMS-12 accepts only profile/variant rows; comparison import dry-run belongs to MBTI-CMS-13.');

            return [];
        }

        if ($pageType !== 'variant') {
            $errors[] = $this->issue('rows.'.(string) $index.'.page_type', 'unsupported_page_type', 'Only variant rows are supported in MBTI-CMS-12.');

            return [];
        }

        if (preg_match('~^/(?:en|zh)/personality/(?<type>[a-z]{4})-(?<variant>[at])$~', $url, $matches) !== 1) {
            $errors[] = $this->issue('rows.'.(string) $index.'.url', 'invalid_variant_url', 'Variant URL must look like /en/personality/intj-a or /zh/personality/intj-t.');

            return [];
        }

        $baseType = strtoupper((string) $matches['type']);
        $variant = strtoupper((string) $matches['variant']);

        return [
            'canonical_type_code' => $baseType,
            'variant_code' => $variant,
            'runtime_type_code' => $baseType.'-'.$variant,
        ];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    private function targetFor(array $identity): array
    {
        return [
            'target_model' => 'App\\Models\\PersonalityProfileVariantRevision',
            'target_table' => 'personality_profile_variant_revisions',
            'lookup' => [
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'locale' => 'row.locale',
                'runtime_type_code' => (string) ($identity['runtime_type_code'] ?? ''),
            ],
            'companion_tables_for_future_promotion' => [
                'personality_profile_variants',
                'personality_profile_variant_sections',
                'personality_profile_variant_seo_meta',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    private function draftRevisionFor(array $identity, string $url): array
    {
        return [
            'revision_note' => 'MBTI-CMS-12 profile import dry-run draft: '.$url,
            'snapshot_key' => 'mbti_cms_12_profile_import_dry_run_v1',
            'snapshot_owner' => 'PersonalityProfileVariant revision',
            'runtime_type_code' => (string) ($identity['runtime_type_code'] ?? ''),
            'publish_visibility' => 'not_public_until_separate_approved_promotion_pr',
            'search_visibility' => 'not_search_released_until_separate_search_gate',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstClassDestinations(): array
    {
        return [
            'identity' => 'personality_profile_variants lookup + personality_profile_variant_revisions personality_profile_variant_id/revision_no/note',
            'seo' => 'planned destination: personality_profile_variant_seo_meta after a separate approved promotion/write PR',
            'sections' => 'planned destination: personality_profile_variant_sections after a separate approved promotion/write PR',
            'draft_payload' => 'this dry-run emits the complete importable draft contract only; it does not write DB rows',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldMappingContract(): array
    {
        return [
            'target_tables' => [
                'personality_profile_variants',
                'personality_profile_variant_revisions',
                'personality_profile_variant_sections',
                'personality_profile_variant_seo_meta',
            ],
            'lookup_contract' => [
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'locale' => 'row.locale',
                'runtime_type_code' => 'row.identity.runtime_type_code',
            ],
            'first_class_fields_for_profile_promotion' => self::FIRST_CLASS_PROFILE_FIELDS,
            'structured_metadata_fields' => self::STRUCTURED_METADATA_FIELDS,
            'unsupported_field_policy' => [
                'decision' => 'structured_metadata_not_dropped',
                'storage' => 'personality_profile_variant_revisions.snapshot_json.mbti_cms_12_profile_import_dry_run_v1.structured_metadata',
                'first_class_promotion_rule' => 'promote only through a later approved CMS write/promotion PR; do not silently map unsupported fields to unrelated columns',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dryRunWriteGuardContract(): array
    {
        return [
            'this_command_requires' => '--dry-run',
            'this_command_refuses' => '--write',
            'write_mode_available_in_this_pr' => false,
            'future_write_minimum_required_flags' => [
                '--write',
                '--draft-only',
                '--no-publish',
                '--no-index',
                '--no-sitemap',
                '--no-llms',
                '--no-search-release',
                '--operator-approved',
            ],
            'hard_guards' => [
                'writes_committed=false in this PR',
                'cms_write_attempted=false',
                'publish_attempted=false',
                'index_attempted=false',
                'search_release_attempted=false',
                'sitemap_llms_release_attempted=false',
                'no published_at mutation',
                'no public/indexable/sitemap/llms flags enabled',
                'comparison rows fail closed for MBTI-CMS-13',
            ],
        ];
    }

    private function normalizePath(string $path): string
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return '';
        }

        $parsedPath = (string) (parse_url($normalized, PHP_URL_PATH) ?: $normalized);
        $parsedPath = '/'.ltrim($parsedPath, '/');

        return $parsedPath !== '/' ? rtrim($parsedPath, '/') : $parsedPath;
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
