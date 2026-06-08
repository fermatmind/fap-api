<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class ScienceContentPagePreImportQaService
{
    private const EXPOSURE_FALSE_FIELDS = [
        'is_public',
        'is_indexable',
        'sitemap_eligible',
        'llms_eligible',
        'footer_eligible',
    ];

    private const ALLOWED_PUBLIC_ROUTE_PREFIXES = [
        '/about',
        '/common-misconceptions',
        '/data-privacy',
        '/evidence-measurement-error',
        '/item-design-notes',
        '/method-boundaries',
        '/privacy',
        '/reliability-validity',
        '/science',
        '/support',
        '/tests',
    ];

    /**
     * @return array<string, mixed>
     */
    public function check(string $packagePath): array
    {
        $root = rtrim($packagePath, DIRECTORY_SEPARATOR);
        if ($root === '' || ! is_dir($root)) {
            throw new \RuntimeException('Science ContentPage draft package directory does not exist: '.$packagePath);
        }

        $dryRun = app(ScienceContentPageDraftDryRunService::class)->dryRun($root);
        $operator = app(ScienceContentPageOperatorReviewReadinessService::class)->review($root);
        $manifest = $this->readJson($root.DIRECTORY_SEPARATOR.'manifest.json');

        $issues = [];
        $pages = [];

        $defaults = is_array($manifest['eligibility_defaults'] ?? null) ? $manifest['eligibility_defaults'] : [];
        foreach (self::EXPOSURE_FALSE_FIELDS as $field) {
            if (($defaults[$field] ?? null) !== false) {
                $issues[] = $this->issue('manifest', 'unsafe_default_'.$field, $field.' must remain false before real import.');
            }
        }

        foreach (($manifest['pages'] ?? []) as $index => $page) {
            if (! is_array($page)) {
                $issues[] = $this->issue('manifest.pages['.$index.']', 'invalid_page_entry', 'page entry must be an object.');

                continue;
            }

            $pageQa = $this->checkPage($root, $page, $index);
            $pages[] = $pageQa;
            foreach ($pageQa['issues'] as $issue) {
                $issues[] = $issue;
            }
        }

        $dryRunBlockers = (int) ($dryRun['pages_blocked'] ?? 0);
        $operatorPublishReady = ($operator['operator_publish_decision_ready'] ?? false) === true;
        $operatorDraftReady = ($operator['operator_review_ready_for_non_public_draft'] ?? false) === true;

        $contentQaPassed = $issues === [];
        $nonPublicDraftImportQaPassed = $contentQaPassed && $operatorDraftReady;
        $realImportAllowed = false;
        $publishAllowed = false;
        $guards = [
            'forbidden_claims_absent' => ! $this->hasIssueCode($issues, 'forbidden_claim_pattern_present'),
            'private_url_absent' => ! $this->hasIssueCode($issues, 'private_url_pattern_present'),
            'faq_visible_only' => ! $this->hasIssueCode($issues, 'hidden_faq_or_schema_pattern_present'),
            'cta_public_canonical_only' => ! $this->hasIssueCode($issues, 'unsafe_cta_or_internal_link_route'),
            'draft_exposure_disabled' => ! $this->hasIssueCodePrefix($issues, 'unsafe_'),
            'sitemap_llms_footer_disabled' => ! $this->hasAnyIssueCode($issues, [
                'unsafe_default_sitemap_eligible',
                'unsafe_default_llms_eligible',
                'unsafe_default_footer_eligible',
                'unsafe_sitemap_eligible',
                'unsafe_llms_eligible',
                'unsafe_footer_eligible',
            ]),
        ];

        $blockingReasons = [];
        if (! $contentQaPassed) {
            $blockingReasons[] = 'package_pre_import_qa_issues_present';
        }
        if ($dryRunBlockers > 0) {
            $blockingReasons[] = 'authority_reconciliation_required';
        }
        if (! $operatorPublishReady) {
            $blockingReasons[] = 'operator_publish_decision_not_ready';
        }
        $blockingReasons[] = 'real_import_requires_separate_operator_approval_and_import_command';

        $realImportContract = [
            'locked' => true,
            'dry_run_only' => true,
            'real_import_command_authorized' => false,
            'database_writes_allowed' => false,
            'cms_mutation_allowed' => false,
            'publish_authorized' => false,
            'requires_separate_operator_approval' => true,
            'requires_separate_import_command_pr' => true,
            'required_gates' => [
                'publish_safety_fields_present',
                'operator_publish_decision_ready',
                'claim_boundary_scan_passed',
                'visible_faq_schema_gate_passed',
                'public_canonical_routes_only',
                'private_url_absent',
                'sitemap_llms_footer_disabled',
            ],
            'gate_status' => [
                'publish_safety_fields_present' => ($operator['missing_first_class_publish_safety_fields'] ?? []) === [],
                'operator_publish_decision_ready' => $operatorPublishReady,
                'claim_boundary_scan_passed' => $guards['forbidden_claims_absent'],
                'visible_faq_schema_gate_passed' => $guards['faq_visible_only'],
                'public_canonical_routes_only' => $guards['cta_public_canonical_only'],
                'private_url_absent' => $guards['private_url_absent'],
                'sitemap_llms_footer_disabled' => $guards['sitemap_llms_footer_disabled'],
            ],
        ];

        return [
            'task' => 'SCIENCE-CONTENTPAGE-PRE-IMPORT-QA-01',
            'mode' => 'read_only_pre_real_import_qa_gate',
            'cms_mutation_performed' => false,
            'database_writes_allowed' => false,
            'content_import_performed' => false,
            'publish_performed' => false,
            'package_path' => $root,
            'decision' => $blockingReasons === [] ? 'CONDITIONAL' : 'NO-GO',
            'non_public_draft_import_qa_passed' => $nonPublicDraftImportQaPassed,
            'real_import_allowed' => $realImportAllowed,
            'publish_allowed' => $publishAllowed,
            'natural_distribution_allowed' => false,
            'real_import_contract' => $realImportContract,
            'package_pre_import_qa_issue_count' => count($issues),
            'blocking_reasons' => $blockingReasons,
            'dry_run' => [
                'status' => $dryRun['status'] ?? 'Unknown',
                'pages_seen' => $dryRun['pages_seen'] ?? 'Unknown',
                'pages_ready_for_non_public_draft_import' => $dryRun['pages_ready_for_non_public_draft_import'] ?? 'Unknown',
                'pages_reconciled_existing_authority' => $dryRun['pages_reconciled_existing_authority'] ?? 'Unknown',
                'pages_blocked' => $dryRunBlockers,
                'would_write' => $dryRun['would_write'] ?? false,
            ],
            'operator_review' => [
                'operator_review_ready_for_non_public_draft' => $operatorDraftReady,
                'operator_publish_decision_ready' => $operatorPublishReady,
                'missing_first_class_publish_safety_fields' => $operator['missing_first_class_publish_safety_fields'] ?? [],
            ],
            'guards' => $guards,
            'pages' => $pages,
            'issues' => $issues,
            'hard_no_go' => [
                'no_cms_mutation',
                'no_real_import',
                'no_publish',
                'no_private_url',
                'no_hidden_faq_schema',
                'no_unsupported_claims',
                'no_sitemap_llms_footer_exposure',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifestPage
     * @return array<string, mixed>
     */
    private function checkPage(string $root, array $manifestPage, int $index): array
    {
        $issues = [];
        $pageKey = (string) ($manifestPage['page_key'] ?? 'Unknown');
        $file = (string) ($manifestPage['file'] ?? '');
        $path = $file !== '' ? $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file) : '';

        $frontmatter = [];
        $body = '';
        $raw = '';
        if ($file === '' || ! is_file($path)) {
            $issues[] = $this->issue($pageKey, 'page_file_not_found', 'page file does not exist: '.$file);
        } else {
            $raw = (string) file_get_contents($path);
            [$frontmatter, $body] = $this->readFrontmatter($path);
        }

        foreach (self::EXPOSURE_FALSE_FIELDS as $field) {
            if (($manifestPage[$field] ?? false) !== false || ($frontmatter[$field] ?? false) !== false) {
                $issues[] = $this->issue($pageKey, 'unsafe_'.$field, $field.' must remain false before real import.');
            }
        }

        if ($this->hasForbiddenClaimPattern($this->claimScanText($body))) {
            $issues[] = $this->issue($pageKey, 'forbidden_claim_pattern_present', 'Diagnostic, guarantee, official endorsement, competitor attack, or proof-overclaim language is blocked.');
        }

        if ($this->hasPrivateUrlPattern($raw)) {
            $issues[] = $this->issue($pageKey, 'private_url_pattern_present', 'Private route or tokenized URL patterns may only appear in forbidden_routes metadata.');
        }

        if ($this->hasHiddenFaqOrSchemaPattern($raw)) {
            $issues[] = $this->issue($pageKey, 'hidden_faq_or_schema_pattern_present', 'FAQ/schema fields must come only from visible_faq_items before import.');
        }

        $routes = $this->routesFrom($manifestPage, $frontmatter);
        foreach ($routes as $route) {
            if (! $this->isAllowedPublicRoute($route)) {
                $issues[] = $this->issue($pageKey, 'unsafe_cta_or_internal_link_route', 'Route is not an allowed public canonical route: '.$route);
            }
        }

        return [
            'index' => $index,
            'page_key' => $pageKey,
            'file' => $file,
            'forbidden_claims_absent' => ! $this->hasIssueCode($issues, 'forbidden_claim_pattern_present'),
            'private_url_absent' => ! $this->hasIssueCode($issues, 'private_url_pattern_present'),
            'faq_visible_only' => ! $this->hasIssueCode($issues, 'hidden_faq_or_schema_pattern_present'),
            'routes_public_canonical_only' => ! $this->hasIssueCode($issues, 'unsafe_cta_or_internal_link_route'),
            'draft_exposure_disabled' => ! $this->hasIssueCodePrefix($issues, 'unsafe_'),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON file: '.$path);
        }

        return $decoded;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function readFrontmatter(string $path): array
    {
        return app(ScienceContentPageFrontmatterReader::class)->read($path);
    }

    private function hasForbiddenClaimPattern(string $body): bool
    {
        $patterns = [
            '/(?<!不)(?:诊断|确诊|治疗)(?!场景|工具|相关|判断)/u',
            '/(?:保证|承诺|确保).{0,12}(?:职业|录用|晋升|收入|成功|匹配|关系|结果)/u',
            '/(?:官方认证|官方背书|权威认证|临床认证|医学认证)/u',
            '/(?:最科学|最准确|唯一正确|完全准确|百分之百|100%)/iu',
            '/(?:竞品|123test|Truity).{0,24}(?:不准|错误|虚假|抄袭|垃圾|骗局)/iu',
            '/(?:证明|证实).{0,12}(?:命运|天赋|能力全貌|职业成功|心理疾病)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body) === 1) {
                return true;
            }
        }

        return false;
    }

    private function claimScanText(string $body): string
    {
        return preg_replace('/\Rclaim_boundary_notes:\s*.*\z/s', '', $body) ?? $body;
    }

    private function hasPrivateUrlPattern(string $raw): bool
    {
        $withoutForbiddenRoutes = preg_replace('/forbidden_routes:\s*(?:(?:\r?\n)\s*-\s*[^\r\n]+)+/m', '', $raw) ?? $raw;

        return preg_match('#/(?:results?/|orders?(?:/|\b)|pay(?:/|\b)|payment(?:/|\b)|checkout(?:/|\b)|share/|history(?:/|\b))#i', $withoutForbiddenRoutes) === 1
            || preg_match('/(?:token=|orderNo=|payment_intent=|client_secret=|recovery_token=)/i', $withoutForbiddenRoutes) === 1;
    }

    private function hasHiddenFaqOrSchemaPattern(string $raw): bool
    {
        $withoutVisibleFaq = preg_replace('/visible_faq_items:\s*(?:(?:\r?\n)\s{2,}-\s[^\r\n]+|(?:\r?\n)\s{4,}[^\r\n]+)*/m', '', $raw) ?? $raw;

        return preg_match('/(?<!visible_)faq_items\s*:/i', $withoutVisibleFaq) === 1
            || preg_match('/(?:faq_schema|schema_enabled|schema_eligible)\s*:\s*true/i', $withoutVisibleFaq) === 1;
    }

    /**
     * @param  array<string, mixed>  $manifestPage
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private function routesFrom(array $manifestPage, array $frontmatter): array
    {
        $routes = [];
        foreach (['primary_cta_route', 'secondary_cta_route', 'cta_route'] as $field) {
            foreach ([$manifestPage, $frontmatter] as $source) {
                if (is_string($source[$field] ?? null)) {
                    $routes[] = $source[$field];
                }
            }
        }

        foreach (['internal_links_allowed', 'cta_slots'] as $field) {
            foreach ([$manifestPage, $frontmatter] as $source) {
                $value = $source[$field] ?? null;
                if (is_array($value)) {
                    foreach ($value as $entry) {
                        if (is_string($entry)) {
                            $routes[] = $entry;
                        } elseif (is_array($entry) && is_string($entry['route'] ?? null)) {
                            $routes[] = $entry['route'];
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_map(static fn (string $route): string => trim($route), $routes)));
    }

    private function isAllowedPublicRoute(string $route): bool
    {
        if (! str_starts_with($route, '/') || str_starts_with($route, '//')) {
            return false;
        }
        if ($this->hasPrivateUrlPattern($route)) {
            return false;
        }

        foreach (self::ALLOWED_PUBLIC_ROUTE_PREFIXES as $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{scope: string, code: string, message: string}>  $issues
     */
    private function hasIssueCode(array $issues, string $code): bool
    {
        return $this->hasAnyIssueCode($issues, [$code]);
    }

    /**
     * @param  list<array{scope: string, code: string, message: string}>  $issues
     * @param  list<string>  $codes
     */
    private function hasAnyIssueCode(array $issues, array $codes): bool
    {
        foreach ($issues as $issue) {
            if (in_array($issue['code'], $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{scope: string, code: string, message: string}>  $issues
     */
    private function hasIssueCodePrefix(array $issues, string $prefix): bool
    {
        foreach ($issues as $issue) {
            if (str_starts_with($issue['code'], $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{scope: string, code: string, message: string}
     */
    private function issue(string $scope, string $code, string $message): array
    {
        return [
            'scope' => $scope,
            'code' => $code,
            'message' => $message,
        ];
    }
}
