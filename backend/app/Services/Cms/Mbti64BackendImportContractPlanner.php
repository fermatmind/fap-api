<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class Mbti64BackendImportContractPlanner
{
    private const EXPECTED_URLS = [
        '/en/personality/intj-a-vs-intj-t',
        '/zh/personality/istj-a',
        '/en/personality/intp-a-vs-intp-t',
        '/zh/personality/infp-t',
        '/en/personality/intj-a',
        '/en/personality/intj-t',
        '/zh/personality/intj-a',
        '/zh/personality/intj-t',
    ];

    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~(?:^|["\'\s(])/(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

    private const FORBIDDEN_QUERY_PATTERN =
        '/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i';

    private const FIRST_CLASS_VARIANT_FIELDS = [
        'url',
        'locale',
        'page_type',
        'canonical_target',
        'seo.seo_title',
        'seo.seo_description',
        'seo.breadcrumb_title',
        'seo.h1',
        'seo.quick_answer_summary',
        'content',
        'faq',
        'internal_links',
    ];

    private const STRUCTURED_METADATA_FIELDS = [
        'primary_query',
        'secondary_queries',
        'excluded_queries',
        'target_intent',
        'target_test_route',
        'method_boundary',
        'trademark_boundary',
        'information_gain',
        'claim_risk_notes',
        'qa_flags_for_codex',
        'route_safety',
        'v2_optimization',
        'above_the_fold_module',
        'serp_ctr_package_v2',
        'status',
    ];

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    public function plan(array $package): array
    {
        $errors = [];
        $warnings = [];
        $rows = $this->rows($package, $errors);
        $rowPlans = [];

        $this->validateTopLevel($package, $warnings);
        $this->validateRowOrder($rows, $errors);
        $this->validateForbiddenRoutes($package, $errors);

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = $this->issue('rows.'.(string) $index, 'row_not_object', 'Each package row must be a JSON object.');

                continue;
            }

            $rowPlans[] = $this->rowPlan($row, $index, $errors, $warnings);
        }

        $variantPlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['page_type'] ?? null) === 'variant'
        ));
        $comparisonPlans = array_values(array_filter(
            $rowPlans,
            static fn (array $row): bool => ($row['page_type'] ?? null) === 'comparison'
        ));

        return [
            'artifact' => 'MBTI64-BACKEND-IMPORT-CONTRACT-PATCH-01',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'publish_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'expected_row_count' => count(self::EXPECTED_URLS),
            'row_count' => count($rows),
            'variant_row_count' => count($variantPlans),
            'comparison_row_count' => count($comparisonPlans),
            'row_order_locked' => $this->actualUrls($rows) === self::EXPECTED_URLS,
            'expected_urls' => self::EXPECTED_URLS,
            'cms_revision_draft_contract' => $this->cmsRevisionDraftContract(),
            'comparison_storage_contract' => $this->comparisonStorageContract(),
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
        $rows = $package['rows'] ?? null;
        if (! is_array($rows)) {
            $errors[] = $this->issue('rows', 'rows_missing_or_not_array', 'V2.1 package must contain rows as an array.');

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
        $version = trim((string) ($package['version'] ?? ''));
        if ($version !== 'pilot-v2.1') {
            $warnings[] = $this->issue('version', 'unexpected_package_version', 'Expected pilot-v2.1; keep this as a human review warning.');
        }

        $status = trim((string) ($package['status'] ?? ''));
        if ($status === '') {
            $warnings[] = $this->issue('status', 'missing_package_status', 'Package status was not provided.');
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<array<string,string>>  $errors
     */
    private function validateRowOrder(array $rows, array &$errors): void
    {
        $actual = $this->actualUrls($rows);
        if (count($actual) !== count(self::EXPECTED_URLS)) {
            $errors[] = $this->issue('rows', 'unexpected_row_count', 'V2.1 pilot package must contain exactly 8 rows.');

            return;
        }

        if ($actual !== self::EXPECTED_URLS) {
            $errors[] = $this->issue('rows', 'pilot_queue_order_mismatch', 'V2.1 pilot package row order must match the locked 8-page queue.');
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<string>
     */
    private function actualUrls(array $rows): array
    {
        $urls = [];
        foreach ($rows as $row) {
            $urls[] = is_array($row) ? $this->normalizePath((string) ($row['url'] ?? '')) : '';
        }

        return $urls;
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
        $identity = $this->parseIdentity($url, $locale, $pageType, $errors, $index);
        $content = is_array($row['content'] ?? null) ? $row['content'] : [];
        $seo = is_array($row['seo'] ?? null) ? $row['seo'] : [];

        return [
            'position' => $index + 1,
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'canonical_target' => $this->normalizePath((string) ($row['canonical_target'] ?? '')),
            'identity' => $identity,
            'target' => $this->targetFor($identity, $pageType),
            'draft_revision' => $this->draftRevisionFor($identity, $pageType, $url),
            'first_class_field_destinations' => $this->firstClassDestinations($pageType),
            'structured_metadata_snapshot_path' => $pageType === 'comparison'
                ? 'personality_profile_revisions.snapshot_json.mbti64_comparison_draft_v2_1.structured_metadata'
                : 'personality_profile_variant_revisions.snapshot_json.mbti64_variant_content_package_v2_1.structured_metadata',
            'content_section_keys' => array_keys($content),
            'seo_keys' => array_keys($seo),
            'faq_count' => is_array($row['faq'] ?? null) ? count((array) $row['faq']) : 0,
            'internal_link_count' => is_array($row['internal_links'] ?? null) ? count((array) $row['internal_links']) : 0,
            'publish_state_after_import' => [
                'status' => 'draft',
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'published_at' => null,
            ],
            'write_mode_in_this_pr' => 'not_supported',
        ];
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

        if ($pageType === 'variant') {
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

        if ($pageType === 'comparison') {
            if (preg_match('~^/(?:en|zh)/personality/(?<type>[a-z]{4})-a-vs-(?<type2>[a-z]{4})-t$~', $url, $matches) !== 1) {
                $errors[] = $this->issue('rows.'.(string) $index.'.url', 'invalid_comparison_url', 'Comparison URL must look like /en/personality/intj-a-vs-intj-t.');

                return [];
            }

            $baseType = strtoupper((string) $matches['type']);
            if ($baseType !== strtoupper((string) $matches['type2'])) {
                $errors[] = $this->issue('rows.'.(string) $index.'.url', 'comparison_base_type_mismatch', 'Comparison URL must compare A/T variants of the same base type.');
            }

            return [
                'canonical_type_code' => $baseType,
                'variant_pair' => [$baseType.'-A', $baseType.'-T'],
            ];
        }

        $errors[] = $this->issue('rows.'.(string) $index.'.page_type', 'unsupported_page_type', 'Only variant and comparison rows are supported.');

        return [];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    private function targetFor(array $identity, string $pageType): array
    {
        if ($pageType === 'comparison') {
            return [
                'target_model' => 'App\\Models\\PersonalityProfileRevision',
                'target_table' => 'personality_profile_revisions',
                'lookup' => [
                    'org_id' => 0,
                    'scale_code' => 'MBTI',
                    'locale' => 'row.locale',
                    'canonical_type_code' => (string) ($identity['canonical_type_code'] ?? ''),
                ],
                'standalone_comparison_model' => false,
            ];
        }

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
                'personality_profile_variant_sections',
                'personality_profile_variant_seo_meta',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $identity
     * @return array<string,mixed>
     */
    private function draftRevisionFor(array $identity, string $pageType, string $url): array
    {
        if ($pageType === 'comparison') {
            return [
                'revision_note' => 'mbti64 pilot-v2.1 comparison draft overlay: '.$url,
                'snapshot_key' => 'mbti64_comparison_draft_v2_1',
                'snapshot_owner' => 'base PersonalityProfile revision',
                'publish_visibility' => 'not_public_until_separate_publish_gate',
                'search_visibility' => 'not_search_released_until_separate_search_gate',
            ];
        }

        return [
            'revision_note' => 'mbti64 pilot-v2.1 variant draft: '.$url,
            'snapshot_key' => 'mbti64_variant_content_package_v2_1',
            'snapshot_owner' => 'PersonalityProfileVariant revision',
            'runtime_type_code' => (string) ($identity['runtime_type_code'] ?? ''),
            'publish_visibility' => 'not_public_until_separate_publish_gate',
            'search_visibility' => 'not_search_released_until_separate_search_gate',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function firstClassDestinations(string $pageType): array
    {
        if ($pageType === 'comparison') {
            return [
                'identity' => 'personality_profiles lookup + personality_profile_revisions profile_id/revision_no/note',
                'body_and_seo' => 'stored as structured draft overlay in snapshot_json until a comparison-specific first-class model exists',
            ];
        }

        return [
            'identity' => 'personality_profile_variants lookup + personality_profile_variant_revisions personality_profile_variant_id/revision_no/note',
            'seo' => 'planned destination: personality_profile_variant_seo_meta after a separate approved promotion/write PR',
            'sections' => 'planned destination: personality_profile_variant_sections after a separate approved promotion/write PR',
            'draft_payload' => 'current PR stores the complete importable draft only in revision snapshot contract output, not in DB',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cmsRevisionDraftContract(): array
    {
        return [
            'variant_pages' => [
                'storage' => 'PersonalityProfileVariantRevision snapshot_json.mbti64_variant_content_package_v2_1',
                'draft_identity' => 'org_id=0 + MBTI + locale + runtime_type_code',
                'future_write_behavior' => 'create a draft revision only; do not promote live sections/SEO or publish in the same command',
            ],
            'comparison_pages' => [
                'storage' => 'PersonalityProfileRevision snapshot_json.mbti64_comparison_draft_v2_1',
                'draft_identity' => 'org_id=0 + MBTI + locale + canonical_type_code',
                'future_write_behavior' => 'create a base-profile draft overlay; do not create a standalone comparison route/model in this patch',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function comparisonStorageContract(): array
    {
        return [
            'current_runtime_truth' => 'comparison pages are not stored in a standalone CMS table in this patch',
            'storage_decision' => 'store each comparison row as a draft overlay under the base PersonalityProfile revision snapshot',
            'snapshot_key' => 'mbti64_comparison_draft_v2_1',
            'why' => 'preserves exact comparison content without inventing runtime rendering behavior or schema migrations in this contract PR',
            'promotion_requirement' => 'a later PR must add/confirm comparison read API/render contract before any publish/search release',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fieldMappingContract(): array
    {
        return [
            'first_class_fields_for_variant_promotion' => self::FIRST_CLASS_VARIANT_FIELDS,
            'structured_metadata_fields' => self::STRUCTURED_METADATA_FIELDS,
            'unsupported_field_policy' => [
                'decision' => 'structured_metadata_not_dropped',
                'storage' => [
                    'variant' => 'personality_profile_variant_revisions.snapshot_json.mbti64_variant_content_package_v2_1.structured_metadata',
                    'comparison' => 'personality_profile_revisions.snapshot_json.mbti64_comparison_draft_v2_1.structured_metadata',
                ],
                'first_class_promotion_rule' => 'promote only through a later migration/model/API PR; do not silently map unsupported fields to unrelated columns',
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
                'publish_attempted=false',
                'search_release_attempted=false',
                'sitemap_llms_release_attempted=false',
                'no published_at mutation',
                'no public/indexable/sitemap/llms flags enabled',
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
