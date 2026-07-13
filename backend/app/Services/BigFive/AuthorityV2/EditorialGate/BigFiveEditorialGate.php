<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\EditorialGate;

final class BigFiveEditorialGate
{
    /** @var list<string> */
    private const REQUIRED_SECTION_KINDS = ['scenario', 'counterexample', 'tradeoff', 'action'];

    private const PRIVATE_PATH_PATTERN =
        '~/(?:en/|zh/)?(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:/|[?"\s]|$)~i';

    private const PRIVATE_IDENTIFIER_PATTERN =
        '/\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\b/i';

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $sourceLedger
     * @return array<string,mixed>
     */
    public function validate(array $package, array $sourceLedger): array
    {
        $issues = [];
        $gateIssues = [
            'schema' => [],
            'source_claim_coverage' => [],
            'bilingual_parity_independence' => [],
            'duplicate_template_risk' => [],
            'private_result_leakage' => [],
            'framework_boundary' => [],
            'scenario_counterexample_tradeoff' => [],
            'manual_review_state' => [],
        ];

        $add = static function (string $gate, string $code, string $path, string $message) use (&$issues, &$gateIssues): void {
            $issue = compact('gate', 'code', 'path', 'message');
            $issues[] = $issue;
            $gateIssues[$gate][] = $issue;
        };

        $this->validateSchema($package, $add);

        $pages = is_array($package['pages'] ?? null) ? array_values($package['pages']) : [];
        $this->validateSourceCoverage($pages, $sourceLedger, $add);
        $this->validateBilingualParity($pages, $add);
        $this->validateTemplateRisk($pages, $add);
        $this->validatePrivateLeakage($package, $add);
        $this->validateFrameworkBoundary($pages, $add);
        $this->validateScenarioSpecificity($pages, $add);
        $this->validateManualReviewState($package, $add);

        $gates = [];
        foreach ($gateIssues as $gate => $found) {
            $gates[$gate] = [
                'status' => $found === [] ? 'pass' : 'fail',
                'issue_count' => count($found),
            ];
        }

        return [
            'artifact' => 'BIG5-AUTHORITY-V2-EDITORIAL-GATE-06',
            'candidate_artifact' => (string) ($package['artifact'] ?? ''),
            'candidate_stage' => (string) ($package['stage'] ?? ''),
            'status' => $issues === [] ? 'pass' : 'fail',
            'ok' => $issues === [],
            'automated_gate_passed' => $issues === [],
            'human_review_passed' => false,
            'publish_allowed' => false,
            'schema_eligible' => false,
            'ai_detector_used' => false,
            'gates' => $gates,
            'issues' => $issues,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'indexability_mutation_attempted' => false,
            'search_submission_attempted' => false,
            'deploy_attempted' => false,
        ];
    }

    /** @param callable(string,string,string,string):void $add */
    private function validateSchema(array $package, callable $add): void
    {
        foreach (['schema_version', 'artifact', 'stage', 'framework', 'pages', 'workflow', 'review_state'] as $field) {
            if (! array_key_exists($field, $package)) {
                $add('schema', 'required_field_missing', $field, 'Required package field is missing.');
            }
        }

        if (($package['schema_version'] ?? null) !== 'big5_authority_v2_editorial_candidate.v1') {
            $add('schema', 'schema_version_invalid', 'schema_version', 'Candidate must use the frozen editorial candidate schema.');
        }
        if (! in_array($package['stage'] ?? null, ['raw', 'repaired', 'final'], true)) {
            $add('schema', 'stage_invalid', 'stage', 'Stage must be raw, repaired, or final.');
        }
        if (($package['framework'] ?? null) !== 'big_five') {
            $add('schema', 'framework_invalid', 'framework', 'Package framework must be big_five.');
        }
        if (! is_array($package['pages'] ?? null) || $package['pages'] === []) {
            $add('schema', 'pages_missing', 'pages', 'Candidate must contain at least one page.');
        }
        if (! is_array($package['workflow'] ?? null)) {
            $add('schema', 'workflow_invalid', 'workflow', 'Workflow provenance must be an object.');
        }
        if (! is_array($package['review_state'] ?? null)) {
            $add('schema', 'review_state_invalid', 'review_state', 'Review state must be an object.');
        }

        foreach (is_array($package['pages'] ?? null) ? $package['pages'] : [] as $index => $page) {
            if (! is_array($page)) {
                $add('schema', 'page_invalid', "pages.{$index}", 'Each page must be an object.');

                continue;
            }
            foreach (['content_key', 'locale', 'page_family', 'framework', 'title', 'summary', 'authoring_mode', 'source_locale', 'sections', 'claims'] as $field) {
                if (! array_key_exists($field, $page)) {
                    $add('schema', 'page_field_missing', "pages.{$index}.{$field}", 'Required page field is missing.');
                }
            }
            if (! is_array($page['sections'] ?? null) || $page['sections'] === []) {
                $add('schema', 'sections_missing', "pages.{$index}.sections", 'Page sections must be a non-empty array.');
            }
            if (! is_array($page['claims'] ?? null)) {
                $add('schema', 'claims_invalid', "pages.{$index}.claims", 'Page claims must be an array.');
            }
        }
    }

    /**
     * @param  list<mixed>  $pages
     * @param  array<string,mixed>  $sourceLedger
     * @param  callable(string,string,string,string):void  $add
     */
    private function validateSourceCoverage(array $pages, array $sourceLedger, callable $add): void
    {
        $sources = [];
        foreach (is_array($sourceLedger['sources'] ?? null) ? $sourceLedger['sources'] : [] as $source) {
            if (is_array($source) && is_string($source['id'] ?? null)) {
                $sources[$source['id']] = $source;
            }
        }
        $claims = [];
        foreach (is_array($sourceLedger['claims'] ?? null) ? $sourceLedger['claims'] : [] as $claim) {
            if (is_array($claim) && is_string($claim['id'] ?? null)) {
                $claims[$claim['id']] = $claim;
            }
        }

        foreach ($pages as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }
            $pageClaims = is_array($page['claims'] ?? null) ? $page['claims'] : [];
            if ($pageClaims === []) {
                $add('source_claim_coverage', 'claims_missing', "pages.{$pageIndex}.claims", 'Every page requires at least one mapped claim.');
            }
            foreach ($pageClaims as $claimIndex => $mapping) {
                $path = "pages.{$pageIndex}.claims.{$claimIndex}";
                if (! is_array($mapping)) {
                    $add('source_claim_coverage', 'claim_mapping_invalid', $path, 'Claim mapping must be an object.');

                    continue;
                }
                $claimId = (string) ($mapping['claim_id'] ?? '');
                $sourceIds = is_array($mapping['source_ids'] ?? null) ? array_values($mapping['source_ids']) : [];
                $authority = $claims[$claimId] ?? null;
                if ($authority === null) {
                    $add('source_claim_coverage', 'claim_unknown', $path.'.claim_id', 'Claim is absent from the shared source ledger.');

                    continue;
                }
                if ($sourceIds === []) {
                    $add('source_claim_coverage', 'claim_sources_missing', $path.'.source_ids', 'Claim mapping requires at least one source.');

                    continue;
                }
                $allowed = array_values(is_array($authority['source_ids'] ?? null) ? $authority['source_ids'] : []);
                foreach ($sourceIds as $sourceId) {
                    if (! is_string($sourceId) || ! isset($sources[$sourceId]) || ! in_array($sourceId, $allowed, true)) {
                        $add('source_claim_coverage', 'claim_source_not_authorized', $path.'.source_ids', 'Source is missing or is not authorized for this claim.');
                    }
                }
                if (($authority['classification'] ?? null) === 'core_scientific') {
                    $hasPrimaryAcademic = false;
                    foreach ($sourceIds as $sourceId) {
                        if (
                            in_array($sourceId, (array) ($authority['primary_source_ids'] ?? []), true)
                            && ($sources[$sourceId]['evidence_category'] ?? null) === 'academic_evidence'
                            && ($sources[$sourceId]['core_scientific_evidence_eligible'] ?? false) === true
                        ) {
                            $hasPrimaryAcademic = true;
                        }
                    }
                    if (! $hasPrimaryAcademic) {
                        $add('source_claim_coverage', 'primary_academic_source_missing', $path, 'Core scientific claims require an authorized primary academic source.');
                    }
                }
            }
        }
    }

    /** @param list<mixed> $pages @param callable(string,string,string,string):void $add */
    private function validateBilingualParity(array $pages, callable $add): void
    {
        $groups = [];
        foreach ($pages as $index => $page) {
            if (is_array($page)) {
                $groups[(string) ($page['content_key'] ?? '')][(string) ($page['locale'] ?? '')] = [$index, $page];
            }
        }

        foreach ($groups as $contentKey => $locales) {
            if (! isset($locales['en'], $locales['zh-CN'])) {
                $add('bilingual_parity_independence', 'locale_pair_incomplete', "content_key.{$contentKey}", 'Every content key requires independently edited en and zh-CN pages.');

                continue;
            }
            [, $en] = $locales['en'];
            [, $zh] = $locales['zh-CN'];
            $enKinds = $this->sectionKinds($en);
            $zhKinds = $this->sectionKinds($zh);
            sort($enKinds);
            sort($zhKinds);
            if ($enKinds !== $zhKinds) {
                $add('bilingual_parity_independence', 'section_intent_parity_failed', "content_key.{$contentKey}", 'Locale pair must cover the same section intents.');
            }
            foreach ([['en', $en], ['zh-CN', $zh]] as [$locale, $page]) {
                if (
                    ($page['authoring_mode'] ?? null) !== 'independent_editorial'
                    || ! array_key_exists('source_locale', $page)
                    || $page['source_locale'] !== null
                ) {
                    $add('bilingual_parity_independence', 'locale_not_independently_authored', "content_key.{$contentKey}.{$locale}", 'Each locale must declare independent editorial authorship and no source locale.');
                }
            }
            if ($this->normalizedPageText($en) === $this->normalizedPageText($zh)) {
                $add('bilingual_parity_independence', 'locale_copy_identical', "content_key.{$contentKey}", 'Locale bodies must not be duplicated.');
            }
        }
    }

    /** @param list<mixed> $pages @param callable(string,string,string,string):void $add */
    private function validateTemplateRisk(array $pages, callable $add): void
    {
        foreach ($pages as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }
            $seen = [];
            foreach (is_array($page['sections'] ?? null) ? $page['sections'] : [] as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }
                $body = trim((string) ($section['body'] ?? ''));
                $normalized = $this->normalize($body);
                if ($normalized !== '' && isset($seen[$normalized])) {
                    $add('duplicate_template_risk', 'duplicate_section_body', "pages.{$pageIndex}.sections.{$sectionIndex}", 'Section body duplicates another section in the same page.');
                }
                $seen[$normalized] = true;
                if (preg_match('/\{\{[^}]+\}\}|\b(?:lorem ipsum|insert example|trait name here)\b|探索真实的自己|unlock your true potential/iu', $body) === 1) {
                    $add('duplicate_template_risk', 'template_or_cliche_detected', "pages.{$pageIndex}.sections.{$sectionIndex}", 'Template placeholder or blocked generic slogan detected.');
                }
            }
        }
    }

    /** @param callable(string,string,string,string):void $add */
    private function validatePrivateLeakage(array $package, callable $add): void
    {
        $serialized = (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (preg_match(self::PRIVATE_PATH_PATTERN, $serialized) === 1) {
            $add('private_result_leakage', 'private_route_detected', 'package', 'Public editorial candidate contains a private result, report, order, payment, account, or attempt route.');
        }
        if (preg_match(self::PRIVATE_IDENTIFIER_PATTERN, $serialized) === 1) {
            $add('private_result_leakage', 'private_identifier_detected', 'package', 'Public editorial candidate contains a private-flow identifier name.');
        }
    }

    /** @param list<mixed> $pages @param callable(string,string,string,string):void $add */
    private function validateFrameworkBoundary(array $pages, callable $add): void
    {
        foreach ($pages as $index => $page) {
            if (! is_array($page)) {
                continue;
            }
            if (($page['framework'] ?? null) !== 'big_five') {
                $add('framework_boundary', 'page_framework_invalid', "pages.{$index}.framework", 'Every page in this gate must remain Big Five.');
            }
            $text = $this->pageText($page);
            if (preg_match('/\b(?:MBTI|Enneagram|RIASEC|Holland Code)\b/iu', $text) === 1) {
                $add('framework_boundary', 'cross_framework_leakage', "pages.{$index}", 'A non-Big-Five framework appears without a dedicated comparison authority contract.');
            }
        }
    }

    /** @param list<mixed> $pages @param callable(string,string,string,string):void $add */
    private function validateScenarioSpecificity(array $pages, callable $add): void
    {
        foreach ($pages as $pageIndex => $page) {
            if (! is_array($page)) {
                continue;
            }
            $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
            $byKind = [];
            foreach ($sections as $section) {
                if (is_array($section)) {
                    $byKind[(string) ($section['kind'] ?? '')][] = trim((string) ($section['body'] ?? ''));
                }
            }
            foreach (self::REQUIRED_SECTION_KINDS as $kind) {
                $bodies = $byKind[$kind] ?? [];
                if ($bodies === []) {
                    $add('scenario_counterexample_tradeoff', 'required_editorial_intent_missing', "pages.{$pageIndex}.sections", "Required {$kind} section is missing.");

                    continue;
                }
                if (max(array_map(static fn (string $body): int => mb_strlen($body), $bodies)) < 45) {
                    $add('scenario_counterexample_tradeoff', 'editorial_intent_not_specific', "pages.{$pageIndex}.sections.{$kind}", "The {$kind} section is too generic to be reviewable.");
                }
            }
        }
    }

    /** @param callable(string,string,string,string):void $add */
    private function validateManualReviewState(array $package, callable $add): void
    {
        $review = is_array($package['review_state'] ?? null) ? $package['review_state'] : [];
        $workflow = is_array($package['workflow'] ?? null) ? $package['workflow'] : [];
        $expected = [
            'status' => 'pending_human_review',
            'reviewer' => null,
            'approved_at' => null,
            'publish_allowed' => false,
            'schema_eligible' => false,
        ];
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $review) || $review[$field] !== $value) {
                $add('manual_review_state', 'review_state_fail_closed', "review_state.{$field}", 'Automated editorial QA must leave human approval, publication, and schema gates closed.');
            }
        }
        if (($workflow['raw_failures_preserved'] ?? null) !== true) {
            $add('manual_review_state', 'raw_failures_not_preserved', 'workflow.raw_failures_preserved', 'Repair workflow must preserve the raw draft failures.');
        }
        if (($workflow['ai_detector_used'] ?? null) !== false) {
            $add('manual_review_state', 'ai_detector_forbidden', 'workflow.ai_detector_used', 'AI detectors must not be used as factual editorial gates.');
        }
        foreach (['raw_artifact', 'skeptical_review_artifact', 'repaired_artifact'] as $field) {
            if (trim((string) ($workflow[$field] ?? '')) === '') {
                $add('manual_review_state', 'workflow_artifact_missing', "workflow.{$field}", 'Raw draft, skeptical review, and repaired draft must remain separately addressable.');
            }
        }
    }

    /** @param array<string,mixed> $page @return list<string> */
    private function sectionKinds(array $page): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($section): string => is_array($section) ? (string) ($section['kind'] ?? '') : '',
            is_array($page['sections'] ?? null) ? $page['sections'] : []
        ))));
    }

    /** @param array<string,mixed> $page */
    private function normalizedPageText(array $page): string
    {
        return $this->normalize($this->pageText($page));
    }

    /** @param array<string,mixed> $page */
    private function pageText(array $page): string
    {
        $parts = [(string) ($page['title'] ?? ''), (string) ($page['summary'] ?? '')];
        foreach (is_array($page['sections'] ?? null) ? $page['sections'] : [] as $section) {
            if (is_array($section)) {
                $parts[] = (string) ($section['body'] ?? '');
            }
        }

        return implode("\n", $parts);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }
}
