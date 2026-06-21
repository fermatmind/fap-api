<?php

declare(strict_types=1);

namespace App\Services\SeoAgent;

final class AutoApprovalPolicy
{
    public const SCHEMA_VERSION = 'seo-agent-auto-approval-policy.v1';

    private const ALLOWED_SOURCE_FAMILIES = ['cms_tdk_gap', 'cms_faq_gap'];

    private const ALLOWED_TARGET_MODELS = ['article', 'content_page'];

    private const ALLOWED_SEVERITIES = ['p1', 'p2'];

    private const FIELD_ALIASES = [
        'canonical_url_or_path' => 'canonical_path',
        'is_indexable_or_robots' => 'is_indexable',
        'faq_schema_eligible' => 'schema_enabled',
    ];

    private const ALLOWED_TARGET_FIELDS = [
        'seo_title',
        'seo_description',
        'canonical_path',
        'is_indexable',
        'faq_items',
        'schema_enabled',
    ];

    private const FORBIDDEN_KEYS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'cms_draft_body',
        'payload',
        'metadata_json',
    ];

    private const FORBIDDEN_CLAIM_PATTERNS = [
        '/\bdiagnos(e|is|tic)\b/i',
        '/\bcure(s|d)?\b/i',
        '/\bguarantee(d|s)?\b/i',
        '/\bofficial\s+(partner|partnership|endorsement)\b/i',
        '/\bclinically\s+proven\b/i',
        '/\bhiring\s+fit\b/i',
        '/\bmedical\s+advice\b/i',
        '/\btreatment\b/i',
        '/\bperfect\s+match\b/i',
        '/\bideal\s+job\b/i',
        '/\bjob\s+fit\b/i',
        '/\bcareer\s+match\b/i',
        '/\bbest\s+career\s+for\s+you\b/i',
        '/\bdetermin(e|es|ed|ing)?\s+your\s+career\b/i',
        '/为你匹配最适合的职业/u',
        '/最适合你的职业/u',
        '/决定你的职业/u',
    ];

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function evaluateCandidates(array $candidates, int $limit = 100): array
    {
        $limit = max(1, min($limit, 250));
        $evaluations = array_map(
            fn (array $candidate): array => $this->evaluateCandidate($candidate),
            array_slice($candidates, 0, $limit)
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'success',
            'policy_mode' => 'l5_low_risk_auto_approval',
            'candidate_count' => count($evaluations),
            'auto_approved_count' => count(array_filter(
                $evaluations,
                static fn (array $evaluation): bool => ($evaluation['approval_decision'] ?? '') === 'auto_approved'
            )),
            'blocked_count' => count(array_filter(
                $evaluations,
                static fn (array $evaluation): bool => ($evaluation['approval_decision'] ?? '') === 'blocked'
            )),
            'candidate_decisions' => $evaluations,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function evaluateCandidate(array $candidate): array
    {
        $sourceFamily = (string) ($candidate['source_family'] ?? '');
        $targetModel = (string) ($candidate['target_model'] ?? $candidate['subject_type'] ?? '');
        $severity = (string) ($candidate['severity'] ?? '');
        $targetFields = $this->targetFields($candidate);
        $reasonCodes = [];

        if (! in_array($sourceFamily, self::ALLOWED_SOURCE_FAMILIES, true)) {
            $reasonCodes[] = match ($sourceFamily) {
                'runtime_seo_qa' => 'runtime_seo_qa_requires_technical_review',
                'gsc_performance' => 'gsc_performance_requires_manual_review_for_l5_auto_approval',
                default => 'source_family_not_allowed',
            };
        }

        if (! in_array($targetModel, self::ALLOWED_TARGET_MODELS, true)) {
            $reasonCodes[] = 'target_model_not_allowed';
        }

        if (! in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            $reasonCodes[] = 'severity_not_allowed';
        }

        if ($targetFields === []) {
            $reasonCodes[] = 'target_fields_missing';
        }

        foreach ($targetFields as $field) {
            if (! in_array($field, self::ALLOWED_TARGET_FIELDS, true)) {
                $reasonCodes[] = 'target_field_not_allowed';
                break;
            }
        }

        if ((bool) ($candidate['claim_gate_required'] ?? false) !== true
            || (bool) ($candidate['human_approval_required'] ?? false) !== true
            || (bool) ($candidate['execution_permission'] ?? true) !== false) {
            $reasonCodes[] = 'approval_boundary_invalid';
        }

        if (($forbidden = $this->forbiddenKeysPresent($candidate)) !== []) {
            foreach ($forbidden as $key) {
                $reasonCodes[] = 'forbidden_field_present:'.$key;
            }
        }

        if ($this->containsFullUrl($candidate)) {
            $reasonCodes[] = 'full_url_present';
        }

        if ($this->forbiddenClaimDetected($candidate)) {
            $reasonCodes[] = 'forbidden_claim_detected';
        }

        $blockedActions = [
            'cms_body_generation',
            'article_auto_publish',
            'google_indexing_live_api_call',
            'frontend_direct_push',
            'frontend_auto_deploy',
        ];
        $allowedActions = [];

        if ($reasonCodes === []) {
            $allowedActions[] = 'cms_draft_write_auto';

            if ($targetModel === 'content_page') {
                $allowedActions[] = 'cms_publish_auto_canary';
                $allowedActions[] = 'post_publish_indexnow_auto';
            } else {
                $blockedActions[] = 'cms_publish_auto_canary';
                $blockedActions[] = 'post_publish_indexnow_auto';
            }
        } else {
            $blockedActions[] = 'cms_draft_write_auto';
            $blockedActions[] = 'cms_publish_auto_canary';
            $blockedActions[] = 'post_publish_indexnow_auto';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate_ref' => (string) ($candidate['subject_ref'] ?? $candidate['source_id'] ?? ''),
            'source_family' => $sourceFamily,
            'target_model' => $targetModel,
            'severity' => $severity,
            'normalized_target_fields' => $targetFields,
            'approval_decision' => $reasonCodes === [] ? 'auto_approved' : 'blocked',
            'risk_tier' => $reasonCodes === [] ? 'low' : 'blocked',
            'allowed_next_actions' => $allowedActions,
            'blocked_actions' => array_values(array_unique($blockedActions)),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'policy_notes' => [
                'article_publish_auto_allowed' => false,
                'content_page_publish_auto_limit' => 3,
                'draft_write_auto_limit' => 10,
                'scheduler_activation_allowed' => false,
                'queue_worker_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function targetFields(array $candidate): array
    {
        $fields = array_map('strval', (array) ($candidate['target_fields'] ?? []));
        $normalized = array_map(
            static fn (string $field): string => self::FIELD_ALIASES[$field] ?? $field,
            $fields
        );

        return array_values(array_unique(array_filter(
            $normalized,
            static fn (string $field): bool => $field !== ''
        )));
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function forbiddenKeysPresent(mixed $value): array
    {
        $matches = [];

        if (! is_array($value)) {
            return $matches;
        }

        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);
            foreach (self::FORBIDDEN_KEYS as $forbiddenKey) {
                if ($normalizedKey === $forbiddenKey || str_contains($normalizedKey, $forbiddenKey)) {
                    $matches[] = $forbiddenKey;
                }
            }

            foreach ($this->forbiddenKeysPresent($child) as $match) {
                $matches[] = $match;
            }
        }

        return array_values(array_unique($matches));
    }

    private function containsFullUrl(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/https?:\/\//i', $value) === 1;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $child) {
            if ($this->containsFullUrl($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function forbiddenClaimDetected(array $candidate): bool
    {
        $haystack = json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($haystack)) {
            return true;
        }

        foreach (self::FORBIDDEN_CLAIM_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'google_indexing_live_api_call' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'frontend_direct_push' => false,
            'frontend_auto_deploy' => false,
            'external_model_api_call' => false,
        ];
    }
}
