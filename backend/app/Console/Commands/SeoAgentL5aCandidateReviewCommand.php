<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentL5aCandidateReviewCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-l5a-candidate-review.v1';

    private const PREFLIGHT_SCHEMA_VERSION = 'seo-agent-priority-queue-scheduler.v1';

    private const WEEKLY_SCHEMA_VERSION = 'seo-agent-weekly-readonly-runner.v1';

    private const RUN_SCHEMA_VERSION = 'seo-agent-run-evidence.v1';

    private const DRAFT_PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const ALLOWED_SOURCE_FAMILIES = ['cms_tdk_gap', 'cms_faq_gap'];

    private const ALLOWED_TARGET_FIELDS = [
        'seo_title',
        'seo_description',
        'canonical_url_or_path',
        'canonical_path',
        'is_indexable_or_robots',
        'is_indexable',
        'faq_items',
        'faq_schema_eligible',
        'schema_enabled',
    ];

    private const FORBIDDEN_KEYS = [
        'raw_url',
        'raw_query',
        'full_url',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'access_token',
        'bearer',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'raw_html',
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

    protected $signature = 'seo-agent:l5a-candidate-review
        {--preflight-summary= : Path to a seo-agent:priority-queue-scheduler --preflight-only summary JSON}
        {--limit=1 : Number of low-risk content_page candidates to select, 1..3}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Review L5-A preflight artifacts and select the safest readonly content_page candidate for canary progression.';

    public function handle(): int
    {
        $preflightPath = $this->preflightSummaryPath();
        if ($preflightPath === null) {
            return $this->finish($this->failureSummary('preflight_summary_unreadable'));
        }

        $limit = $this->limit();
        if ($limit === null) {
            return $this->finish($this->failureSummary('limit_out_of_bounds'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $preflight = $this->readJson($preflightPath);
        if (! is_array($preflight)) {
            return $this->finish($this->failureSummary('preflight_summary_json_invalid'));
        }

        $preflightIssue = $this->validatePreflightSummary($preflight);
        if ($preflightIssue !== null) {
            return $this->finish($this->failureSummary($preflightIssue));
        }

        $weeklyPath = $this->safeArtifactPath((string) data_get($preflight, 'steps.weekly_readonly_runner.artifact.path'));
        if ($weeklyPath === null) {
            return $this->finish($this->failureSummary('weekly_readonly_artifact_missing'));
        }

        $weekly = $this->readJson($weeklyPath);
        if (! is_array($weekly) || ($weekly['schema_version'] ?? null) !== self::WEEKLY_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('weekly_readonly_artifact_invalid'));
        }

        $runPath = $this->safeArtifactPath((string) data_get($weekly, 'run_artifact.path'));
        if ($runPath === null) {
            return $this->finish($this->failureSummary('run_evidence_artifact_missing'));
        }

        $runEvidence = $this->readJson($runPath);
        if (! is_array($runEvidence) || ($runEvidence['schema_version'] ?? null) !== self::RUN_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('run_evidence_artifact_invalid'));
        }

        $draftPackagePath = $this->safeArtifactPath((string) data_get($runEvidence, 'artifacts.cms_draft_package_dry_run.path'));
        if ($draftPackagePath === null) {
            return $this->finish($this->failureSummary('draft_package_artifact_missing'));
        }

        $draftPackage = $this->readJson($draftPackagePath);
        if (! is_array($draftPackage) || ($draftPackage['schema_version'] ?? null) !== self::DRAFT_PACKAGE_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('draft_package_artifact_invalid'));
        }

        $items = array_values(array_filter(
            (array) ($draftPackage['draft_briefs'] ?? $draftPackage['proposal_items'] ?? []),
            static fn ($item): bool => is_array($item)
        ));
        $reviewed = array_map(fn (array $item): array => $this->reviewCandidate($item), $items);
        $eligible = array_values(array_filter(
            $reviewed,
            static fn (array $item): bool => ($item['eligible'] ?? false) === true
        ));
        usort($eligible, fn (array $a, array $b): int => ($a['sort_score'] <=> $b['sort_score'])
            ?: strcmp((string) data_get($a, 'candidate.safe_path'), (string) data_get($b, 'candidate.safe_path')));

        $selected = array_slice(array_map(
            static fn (array $item): array => $item['candidate'],
            $eligible
        ), 0, $limit);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-L5A-PREFLIGHT-CANDIDATE-REVIEW-01',
            'status' => count($selected) === $limit ? 'success' : 'blocked',
            'run_mode' => 'readonly_l5a_preflight_candidate_review',
            'limit' => $limit,
            'selected_count' => count($selected),
            'selected_candidates' => $selected,
            'selected_candidate' => $selected[0] ?? null,
            'source_counts' => [
                'draft_brief_count' => count($items),
                'eligible_count' => count($eligible),
                'rejected_count' => count($items) - count($eligible),
            ],
            'rejected_reason_counts' => $this->rejectedReasonCounts($reviewed),
            'risk_flags' => $this->riskFlags($reviewed),
            'input_artifacts' => [
                'preflight_summary' => $this->artifactRef($preflightPath, self::PREFLIGHT_SCHEMA_VERSION),
                'weekly_readonly_runner' => $this->artifactRef($weeklyPath, self::WEEKLY_SCHEMA_VERSION),
                'run_evidence' => $this->artifactRef($runPath, self::RUN_SCHEMA_VERSION),
                'cms_draft_package_dry_run' => $this->artifactRef($draftPackagePath, self::DRAFT_PACKAGE_SCHEMA_VERSION),
            ],
            'blocked_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_queue_write',
                'search_channel_submit',
                'indexnow_live_submit',
                'google_indexing_api_call',
                'scheduler_activation',
                'queue_worker_activation',
                'frontend_code_mutation',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-l5a-candidate-review-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json', $payload);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => count($selected) === $limit,
            'status' => count($selected) === $limit ? 'success' : 'blocked',
            'selected_count' => count($selected),
            'artifact' => $artifact,
            'selected_candidate' => $selected[0] ?? null,
            'rejected_reason_counts' => $payload['rejected_reason_counts'],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function preflightSummaryPath(): ?string
    {
        $path = trim((string) $this->option('preflight-summary'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function limit(): ?int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        return is_int($limit) && $limit >= 1 && $limit <= 3 ? $limit : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/l5a-candidate-review');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    private function validatePreflightSummary(array $preflight): ?string
    {
        if (($preflight['schema_version'] ?? null) !== self::PREFLIGHT_SCHEMA_VERSION) {
            return 'preflight_schema_invalid';
        }
        if (($preflight['status'] ?? null) !== 'success' || ($preflight['ok'] ?? false) !== true) {
            return 'preflight_not_success';
        }
        if (($preflight['preflight_only'] ?? false) !== true) {
            return 'preflight_not_readonly';
        }
        if ((string) data_get($preflight, 'steps.weekly_readonly_runner.status') !== 'success') {
            return 'weekly_readonly_step_not_success';
        }
        if ((string) data_get($preflight, 'steps.rollback_preflight.status') !== 'pass') {
            return 'rollback_preflight_not_pass';
        }

        return null;
    }

    private function safeArtifactPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(?string $path): ?array
    {
        $path = (string) $path;
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{eligible: bool, candidate: array<string, mixed>, reason_codes: list<string>, sort_score: int}
     */
    private function reviewCandidate(array $candidate): array
    {
        $reasonCodes = [];
        $sourceFamily = (string) ($candidate['source_family'] ?? '');
        $targetModel = (string) ($candidate['target_model'] ?? $candidate['subject_type'] ?? '');
        $subjectType = (string) ($candidate['subject_type'] ?? $targetModel);
        $severity = (string) ($candidate['severity'] ?? '');
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $targetFields = array_values(array_filter(array_map('strval', (array) ($candidate['target_fields'] ?? []))));
        $gapCodes = array_values(array_filter(array_map('strval', (array) ($candidate['gap_codes'] ?? $candidate['gap_types'] ?? []))));

        if ($targetModel !== 'content_page' || $subjectType !== 'content_page') {
            $reasonCodes[] = 'target_not_content_page';
        }
        if (! in_array($sourceFamily, self::ALLOWED_SOURCE_FAMILIES, true)) {
            $reasonCodes[] = 'source_family_not_low_risk';
        }
        if (! in_array($severity, ['p1', 'p2'], true)) {
            $reasonCodes[] = 'severity_not_low_risk';
        }
        if ($safePath === '' || str_starts_with($safePath, 'http://') || str_starts_with($safePath, 'https://')) {
            $reasonCodes[] = 'safe_path_invalid';
        }
        if ($targetFields === []) {
            $reasonCodes[] = 'target_fields_missing';
        }
        foreach ($targetFields as $field) {
            if (! in_array($field, self::ALLOWED_TARGET_FIELDS, true)) {
                $reasonCodes[] = 'target_field_not_low_risk';
                break;
            }
        }
        if (in_array('manual_review_required', $targetFields, true)) {
            $reasonCodes[] = 'manual_review_required';
        }
        if ($this->hasRuntimeRisk($sourceFamily, $gapCodes)) {
            $reasonCodes[] = 'runtime_seo_qa_risk';
        }
        if ($this->forbiddenKeysPresent($candidate) !== []) {
            $reasonCodes[] = 'forbidden_field_present';
        }
        if ($this->containsFullUrl($candidate)) {
            $reasonCodes[] = 'full_url_present';
        }
        if ($this->forbiddenClaimDetected($candidate)) {
            $reasonCodes[] = 'forbidden_claim_detected';
        }
        foreach ((array) ($candidate['draft_instructions'] ?? []) as $instruction) {
            $normalizedInstruction = strtolower((string) $instruction);
            if (str_contains($normalizedInstruction, 'generate_final_body_copy')
                && ! str_contains($normalizedInstruction, 'do_not_generate_final_body_copy')) {
                $reasonCodes[] = 'body_rewrite_instruction_present';
                break;
            }
        }

        return [
            'eligible' => $reasonCodes === [],
            'candidate' => [
                'source_id' => (string) ($candidate['source_id'] ?? ''),
                'source_family' => $sourceFamily,
                'subject_type' => $subjectType,
                'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
                'target_model' => $targetModel,
                'safe_path' => $safePath,
                'severity' => $severity,
                'gap_codes' => $gapCodes,
                'target_fields' => $targetFields,
                'recommended_next_step' => 'cms_draft_write_canary_limit_1',
                'risk_flags' => [],
                'blocked_actions' => [
                    'cms_publish',
                    'search_channel_queue_write',
                    'search_channel_submit',
                    'indexnow_live_submit',
                    'google_indexing_api_call',
                ],
            ],
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'sort_score' => $this->sortScore($sourceFamily, $severity, $targetFields),
        ];
    }

    /**
     * @param  list<string>  $gapCodes
     */
    private function hasRuntimeRisk(string $sourceFamily, array $gapCodes): bool
    {
        if ($sourceFamily === 'runtime_seo_qa') {
            return true;
        }

        foreach ($gapCodes as $gapCode) {
            foreach (['runtime', 'redirect', 'status', 'robots', 'noindex', 'x_robots'] as $needle) {
                if (str_contains($gapCode, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $targetFields
     */
    private function sortScore(string $sourceFamily, string $severity, array $targetFields): int
    {
        $score = $severity === 'p1' ? 0 : 100;
        $score += $sourceFamily === 'cms_tdk_gap' ? 0 : 20;

        foreach ($targetFields as $field) {
            $score += match ($field) {
                'seo_title', 'seo_description' => 1,
                'faq_items', 'faq_schema_eligible', 'schema_enabled' => 12,
                'canonical_url_or_path', 'canonical_path', 'is_indexable_or_robots', 'is_indexable' => 20,
                default => 50,
            };
        }

        return $score + count($targetFields);
    }

    /**
     * @param  list<array<string, mixed>>  $reviewed
     * @return array<string, int>
     */
    private function rejectedReasonCounts(array $reviewed): array
    {
        $counts = [];
        foreach ($reviewed as $item) {
            if (($item['eligible'] ?? false) === true) {
                continue;
            }
            foreach ((array) ($item['reason_codes'] ?? []) as $reason) {
                $counts[(string) $reason] = ($counts[(string) $reason] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $reviewed
     * @return list<string>
     */
    private function riskFlags(array $reviewed): array
    {
        $flags = [];
        foreach ($reviewed as $item) {
            foreach ((array) ($item['reason_codes'] ?? []) as $reason) {
                if (in_array($reason, ['forbidden_claim_detected', 'forbidden_field_present', 'full_url_present', 'runtime_seo_qa_risk'], true)) {
                    $flags[] = (string) $reason;
                }
            }
        }

        return array_values(array_unique($flags));
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
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->containsFullUrl($child)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && preg_match('#https?://#i', $value) === 1;
    }

    private function forbiddenClaimDetected(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if ($this->forbiddenClaimDetected($child)) {
                    return true;
                }
            }

            return false;
        }
        if (! is_string($value)) {
            return false;
        }

        foreach (self::FORBIDDEN_CLAIM_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'path_hash' => hash('sha256', $path),
            'sha256' => hash_file('sha256', $path) ?: '',
            'size' => filesize($path) ?: 0,
            'schema_version' => $schemaVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, string $filename, array $payload): array
    {
        $path = rtrim($artifactDir, '/').'/'.$filename;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('artifact_write_failed');
        }

        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => (string) ($payload['schema_version'] ?? 'unknown'),
            'sanitized_summary' => [
                'selected_count' => (int) ($payload['selected_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            ...$extra,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            $this->line('selected_count='.(string) ($summary['selected_count'] ?? 0));
            if (is_array($summary['artifact'] ?? null)) {
                $this->line('artifact_path='.(string) ($summary['artifact']['path'] ?? ''));
                $this->line('artifact_size='.(string) ($summary['artifact']['size'] ?? 0));
                $this->line('artifact_sha256='.(string) ($summary['artifact']['sha256'] ?? ''));
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
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
            'search_channel_queue_write' => false,
            'search_channel_submit' => false,
            'indexnow_live_submit' => false,
            'google_indexing_api_call' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'frontend_code_mutation' => false,
            'external_model_api_call' => false,
        ];
    }
}
