<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class MbtiComparisonCmsImportDryRunPlanner
{
    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~(?:^|["\'\s(])/(?:[a-z]{2}/)?(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

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
        'max_difference',
        'quick_judgment_table',
        'confusion_reason',
        'real_scene_differences',
        'misjudgment_warning',
    ];

    private const FIRST_CLASS_COMPARISON_FIELDS = [
        'url',
        'locale',
        'page_type',
        'comparison_kind',
        'canonical_target',
        'seo.seo_title',
        'seo.seo_description',
        'seo.breadcrumb_title',
        'seo.h1',
        'seo.quick_answer_summary',
        'content.quick_answer',
        'content.max_difference',
        'content.quick_judgment_table',
        'content.confusion_reason',
        'content.real_scene_differences',
        'content.misjudgment_warning',
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

        $comparisonPlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['page_type'] ?? null) === 'comparison'
        ));
        $profilePlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => in_array(($row['page_type'] ?? null), ['profile', 'variant'], true)
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
            'artifact' => 'MBTI-CMS-13-COMPARISON-IMPORT-DRY-RUN',
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
            'comparison_row_count' => count($comparisonPlans),
            'profile_row_count' => count($profilePlans),
            'at_comparison_count' => count($atPlans),
            'cross_type_comparison_count' => count($crossTypePlans),
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
        $rows = $package['rows'] ?? $package['comparisons'] ?? null;
        if (! is_array($rows)) {
            $errors[] = $this->issue('rows', 'rows_missing_or_not_array', 'Comparison package must contain rows or comparisons as an array.');

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
        if ($line !== '' && $line !== 'comparison') {
            $warnings[] = $this->issue('content_line', 'unexpected_content_line', 'MBTI-CMS-13 expects the comparison content line.');
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
        $comparisonKind = (string) ($row['comparison_kind'] ?? '');
        $seo = is_array($row['seo'] ?? null) ? $row['seo'] : [];
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $identity = $this->parseIdentity($url, $locale, $pageType, $comparisonKind, $errors, $index);

        $this->validateRowShape($row, $seo, $content, $errors, $warnings, $index);

        return [
            'position' => $index + 1,
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'comparison_kind' => $comparisonKind,
            'canonical_target' => $this->normalizePath((string) ($row['canonical_target'] ?? '')),
            'identity' => $identity,
            'target' => $this->targetFor($identity),
            'draft_revision' => $this->draftRevisionFor($identity, $url),
            'first_class_field_destinations' => $this->firstClassDestinations(),
            'structured_metadata_snapshot_path' => 'mbti_comparison_dry_run_plan.mbti_cms_13_comparison_import_dry_run_v1.structured_metadata',
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
            'action' => 'would_stage_comparison_revision',
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
                $errors[] = $this->issue('rows.'.(string) $index.'.content.'.$field, 'required_content_field_missing', 'Required comparison answer block field is missing.');
            }
        }

        if (! is_array($content['quick_judgment_table'] ?? null) || count((array) $content['quick_judgment_table']) < 2) {
            $errors[] = $this->issue('rows.'.(string) $index.'.content.quick_judgment_table', 'quick_judgment_table_minimum_not_met', 'Comparison rows need at least two quick judgment rows.');
        }

        if (! is_array($row['faq'] ?? null) || count((array) $row['faq']) < 3) {
            $errors[] = $this->issue('rows.'.(string) $index.'.faq', 'faq_minimum_not_met', 'Comparison dry-run rows need at least three FAQ entries.');
        }

        if (! is_array($row['internal_links'] ?? null) || count((array) $row['internal_links']) < 3) {
            $warnings[] = $this->issue('rows.'.(string) $index.'.internal_links', 'internal_link_minimum_not_met', 'Comparison rows should include at least three related public links before promotion.');
        }
    }

    /**
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function parseIdentity(string $url, string $locale, string $pageType, string $comparisonKind, array &$errors, int $index): array
    {
        if (! in_array($locale, ['en', 'zh-CN'], true)) {
            $errors[] = $this->issue('rows.'.(string) $index.'.locale', 'unsupported_locale', 'Only en and zh-CN are supported.');
        }

        if (in_array($pageType, ['profile', 'variant'], true)) {
            $errors[] = $this->issue('rows.'.(string) $index.'.page_type', 'profile_rows_out_of_scope', 'MBTI-CMS-13 accepts only comparison rows; profile import dry-run belongs to MBTI-CMS-12.');

            return [];
        }

        if ($pageType !== 'comparison') {
            $errors[] = $this->issue('rows.'.(string) $index.'.page_type', 'unsupported_page_type', 'Only comparison rows are supported in MBTI-CMS-13.');

            return [];
        }

        $atMatch = [];
        $crossMatch = [];
        if (preg_match('~^/(?:en|zh)/personality/(?<type>[a-z]{4})-a-vs-\k<type>-t$~', $url, $atMatch) === 1) {
            if (! in_array($comparisonKind, ['', 'at'], true)) {
                $errors[] = $this->issue('rows.'.(string) $index.'.comparison_kind', 'comparison_kind_url_mismatch', 'A/T comparison URL must use comparison_kind=at.');
            }

            $baseType = strtoupper((string) $atMatch['type']);

            return [
                'comparison_kind' => 'at',
                'comparison_slug' => strtolower($baseType).'-a-vs-'.strtolower($baseType).'-t',
                'base_type_code' => $baseType,
                'left_type_code' => $baseType.'-A',
                'right_type_code' => $baseType.'-T',
            ];
        }

        if (preg_match('~^/(?:en|zh)/personality/(?<left>[a-z]{4})-vs-(?<right>[a-z]{4})$~', $url, $crossMatch) === 1) {
            if (! in_array($comparisonKind, ['', 'cross_type'], true)) {
                $errors[] = $this->issue('rows.'.(string) $index.'.comparison_kind', 'comparison_kind_url_mismatch', 'Cross-type comparison URL must use comparison_kind=cross_type.');
            }

            $left = strtoupper((string) $crossMatch['left']);
            $right = strtoupper((string) $crossMatch['right']);
            if ($left === $right) {
                $errors[] = $this->issue('rows.'.(string) $index.'.url', 'cross_type_pair_must_differ', 'Cross-type comparison URL must compare two different base types.');
            }

            return [
                'comparison_kind' => 'cross_type',
                'comparison_slug' => strtolower($left).'-vs-'.strtolower($right),
                'left_type_code' => $left,
                'right_type_code' => $right,
            ];
        }

        $errors[] = $this->issue('rows.'.(string) $index.'.url', 'invalid_comparison_url', 'Comparison URL must look like /zh/personality/intj-a-vs-intj-t or /zh/personality/entj-vs-intj.');

        return [];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    private function targetFor(array $identity): array
    {
        if (($identity['comparison_kind'] ?? null) === 'at') {
            return [
                'target_model' => 'App\\Models\\PersonalityProfileSection',
                'target_table' => 'personality_profile_sections',
                'section_key' => 'mbti64_comparison_a_vs_t',
                'lookup' => [
                    'org_id' => 0,
                    'scale_code' => 'MBTI',
                    'locale' => 'row.locale',
                    'base_type_code' => (string) ($identity['base_type_code'] ?? ''),
                ],
            ];
        }

        return [
            'target_model' => 'backend_authority.mbti64_cross_type_comparison',
            'target_table' => 'planned_mbti_cross_type_comparison_authority',
            'lookup' => [
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'locale' => 'row.locale',
                'comparison_slug' => (string) ($identity['comparison_slug'] ?? ''),
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
            'revision_note' => 'MBTI-CMS-13 comparison import dry-run draft: '.$url,
            'snapshot_key' => 'mbti_cms_13_comparison_import_dry_run_v1',
            'snapshot_owner' => (string) ($identity['comparison_kind'] ?? 'comparison').' comparison authority draft',
            'comparison_slug' => (string) ($identity['comparison_slug'] ?? ''),
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
            'identity' => 'A/T rows map to personality_profile_sections by base type; cross-type rows map to a future comparison authority draft by comparison slug',
            'seo' => 'planned destination: comparison public projection SEO fields after a separate approved promotion/write PR',
            'sections' => 'planned destination: comparison answer/section projection after a separate approved promotion/write PR',
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
                'personality_profile_sections',
                'planned_mbti_cross_type_comparison_authority',
                'comparison_public_projection_v1',
            ],
            'lookup_contract' => [
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'locale' => 'row.locale',
                'comparison_kind' => 'row.identity.comparison_kind',
                'comparison_slug' => 'row.identity.comparison_slug',
            ],
            'first_class_fields_for_comparison_promotion' => self::FIRST_CLASS_COMPARISON_FIELDS,
            'structured_metadata_fields' => self::STRUCTURED_METADATA_FIELDS,
            'unsupported_field_policy' => [
                'decision' => 'structured_metadata_not_dropped',
                'storage' => 'mbti_comparison_dry_run_plan.mbti_cms_13_comparison_import_dry_run_v1.structured_metadata',
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
                'profile rows fail closed; MBTI-CMS-12 owns profile assets',
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
