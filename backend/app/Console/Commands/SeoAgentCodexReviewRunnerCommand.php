<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentCodexReviewRunnerCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-codex-review-verdict.v1';

    private const HANDOFF_SCHEMA_VERSION = 'seo-agent-codex-review-handoff.v1';

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'token',
        'cookie',
        'session',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'seo-agent:codex-review-runner
        {--handoff= : Path to a seo-agent-codex-review-handoff.v1 JSON artifact}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Deterministic Codex review runner for SEO Agent handoff artifacts; writes verdict JSON only.';

    public function handle(): int
    {
        $handoffPath = $this->handoffPath();
        if ($handoffPath === null) {
            return $this->finish($this->failureSummary('handoff_unreadable'));
        }

        $raw = (string) file_get_contents($handoffPath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $handoff = json_decode($raw, true);
        if (! is_array($handoff)) {
            return $this->finish($this->failureSummary('handoff_json_invalid'));
        }

        if (($handoff['schema_version'] ?? null) !== self::HANDOFF_SCHEMA_VERSION) {
            return $this->finish($this->failureSummary('handoff_schema_invalid'));
        }

        if (($handoff['reviewer'] ?? null) !== 'codex' || (bool) ($handoff['execution_permission'] ?? true)) {
            return $this->finish($this->failureSummary('handoff_review_boundary_invalid'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $verdict = $this->verdict($handoff, $handoffPath);
        $artifactRef = $this->writeArtifact($artifactDir, 'seo-agent-codex-review-verdict-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json', $verdict);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'candidate_count' => count($verdict['candidate_verdicts']),
            'worth_optimizing_count' => count(array_filter(
                $verdict['candidate_verdicts'],
                static fn (array $candidate): bool => ($candidate['worth_optimizing'] ?? false) === true
            )),
            'artifact' => $artifactRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    private function handoffPath(): ?string
    {
        $path = trim((string) $this->option('handoff'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $handoff
     * @return array<string, mixed>
     */
    private function verdict(array $handoff, string $handoffPath): array
    {
        $candidates = array_values(array_filter(
            (array) ($handoff['candidate_preview'] ?? []),
            static fn ($candidate): bool => is_array($candidate)
        ));

        $candidateVerdicts = array_map(
            fn (array $candidate): array => $this->candidateVerdict($candidate),
            $candidates
        );

        $worthOptimizing = array_values(array_filter(
            $candidateVerdicts,
            static fn (array $verdict): bool => ($verdict['worth_optimizing'] ?? false) === true
        ));
        $draftReady = array_filter(
            $candidateVerdicts,
            static fn (array $verdict): bool => ($verdict['recommended_action'] ?? '') === 'cms_draft_package_dry_run'
        );
        $technicalReview = array_filter(
            $candidateVerdicts,
            static fn (array $verdict): bool => ($verdict['recommended_action'] ?? '') === 'technical_review_required'
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'reviewer' => 'codex',
            'review_mode' => 'deterministic_rules',
            'role' => 'review_only',
            'execution_permission' => false,
            'input_handoff' => [
                'path_hash' => hash('sha256', $handoffPath),
                'sha256' => hash_file('sha256', $handoffPath) ?: '',
                'schema_version' => (string) ($handoff['schema_version'] ?? ''),
            ],
            'candidate_count' => count($candidateVerdicts),
            'candidate_verdicts' => $candidateVerdicts,
            'worth_optimizing' => $worthOptimizing !== [],
            'recommended_action' => $draftReady !== []
                ? 'cms_draft_package_dry_run'
                : ($technicalReview !== [] ? 'technical_review_required' : 'defer'),
            'risk_flags' => $this->aggregateRiskFlags($candidateVerdicts),
            'needs_human_approval' => true,
            'forbidden_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
                'scheduler_activation',
                'queue_worker_activation',
                'external_model_api_call',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function candidateVerdict(array $candidate): array
    {
        $severity = (string) ($candidate['severity'] ?? '');
        $sourceFamily = (string) ($candidate['source_family'] ?? '');
        $sourceId = (string) ($candidate['source_id'] ?? '');
        $subjectType = (string) ($candidate['subject_type'] ?? '');
        $subjectRef = (string) ($candidate['subject_ref'] ?? '');
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $evidenceRefs = (array) ($candidate['evidence_refs'] ?? []);
        $gapTypes = array_values(array_filter(
            array_map('strval', (array) ($candidate['gap_types'] ?? [])),
            static fn (string $gap): bool => $gap !== ''
        ));
        $riskFlags = [];

        if ($sourceId === '' || $subjectRef === '' || $safePath === '') {
            $riskFlags[] = 'candidate_incomplete';
        }
        if ($evidenceRefs === []) {
            $riskFlags[] = 'evidence_missing';
        }
        if (! in_array($severity, ['p1', 'p2', 'p3'], true)) {
            $riskFlags[] = 'unsupported_severity';
        }

        [$recommendedAction, $reviewReason, $sourceRiskFlags] = $this->reviewDecision(
            $sourceFamily,
            $subjectType,
            $severity,
            $gapTypes,
            $riskFlags
        );
        $riskFlags = array_values(array_unique(array_merge($riskFlags, $sourceRiskFlags)));
        $worthOptimizing = in_array($recommendedAction, ['cms_draft_package_dry_run', 'technical_review_required'], true);

        return [
            'source_id' => $sourceId !== '' ? $sourceId : hash('sha256', $subjectRef.$safePath.json_encode($candidate)),
            'source_family' => $sourceFamily,
            'subject_type' => $subjectType,
            'subject_ref' => $subjectRef,
            'safe_path' => $safePath,
            'severity' => $severity,
            'gap_types' => $gapTypes,
            'evidence_refs' => $this->sanitizedEvidenceRefs($evidenceRefs),
            'worth_optimizing' => $worthOptimizing,
            'recommended_action' => $recommendedAction,
            'review_reason' => $reviewReason,
            'risk_flags' => $riskFlags,
            'needs_human_approval' => true,
            'execution_permission' => false,
        ];
    }

    /**
     * @param  list<string>  $gapTypes
     * @param  list<string>  $existingRiskFlags
     * @return array{0: string, 1: string, 2: list<string>}
     */
    private function reviewDecision(
        string $sourceFamily,
        string $subjectType,
        string $severity,
        array $gapTypes,
        array $existingRiskFlags
    ): array {
        if ($existingRiskFlags !== [] || ! in_array($severity, ['p1', 'p2'], true)) {
            return ['defer', 'p3_or_incomplete_or_unsupported_candidate', []];
        }

        if ($this->isGscOnlyWithoutCmsTarget($sourceFamily, $subjectType)) {
            return ['defer', 'gsc_candidate_without_cms_target', ['cms_target_missing']];
        }

        if ($sourceFamily === 'runtime_seo_qa' && $this->hasTechnicalRuntimeGap($gapTypes)) {
            return ['technical_review_required', 'runtime_seo_qa_requires_technical_review', ['technical_surface_requires_human_review']];
        }

        if (in_array($sourceFamily, ['cms_tdk_gap', 'cms_faq_gap'], true)) {
            return ['cms_draft_package_dry_run', $sourceFamily.'_ready_for_draft_dry_run', []];
        }

        return ['cms_draft_package_dry_run', 'complete_p1_p2_candidate_ready_for_draft_dry_run', []];
    }

    private function isGscOnlyWithoutCmsTarget(string $sourceFamily, string $subjectType): bool
    {
        return str_contains($sourceFamily, 'gsc')
            && ! in_array($subjectType, ['article', 'content_page'], true);
    }

    /**
     * @param  list<string>  $gapTypes
     */
    private function hasTechnicalRuntimeGap(array $gapTypes): bool
    {
        foreach ($gapTypes as $gapType) {
            if (str_contains($gapType, 'canonical')
                || str_contains($gapType, 'noindex')
                || str_contains($gapType, 'robots')
                || str_contains($gapType, 'redirect')
                || str_contains($gapType, 'status')
            ) {
                return true;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $evidenceRefs
     * @return list<array<string, string>>
     */
    private function sanitizedEvidenceRefs(array $evidenceRefs): array
    {
        $refs = [];
        foreach ($evidenceRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $refs[] = [
                'code' => (string) ($ref['code'] ?? ''),
                'field_status' => (string) ($ref['field_status'] ?? ''),
            ];
        }

        return array_values(array_filter(
            $refs,
            static fn (array $ref): bool => $ref['code'] !== '' || $ref['field_status'] !== ''
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $candidateVerdicts
     * @return list<string>
     */
    private function aggregateRiskFlags(array $candidateVerdicts): array
    {
        $flags = [];
        foreach ($candidateVerdicts as $verdict) {
            foreach ((array) ($verdict['risk_flags'] ?? []) as $flag) {
                $flags[] = (string) $flag;
            }
        }

        return array_values(array_unique($flags));
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
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
                'candidate_count' => (int) ($payload['candidate_count'] ?? 0),
                'worth_optimizing' => (bool) ($payload['worth_optimizing'] ?? false),
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
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
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
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'indexing_request' => false,
            'sitemap_submission' => false,
            'scheduler_activation' => false,
            'queue_worker_started' => false,
            'production_env_change' => false,
            'external_model_api_call' => false,
        ];
    }
}
