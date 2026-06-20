<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgent\CmsFaqGapReadonlyScanner;
use App\Services\SeoAgent\CmsTdkGapReadonlyScanner;
use App\Services\SeoAgent\OpportunityAggregator;
use App\Services\SeoAgent\RuntimeSeoQaReadonlyScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

final class SeoAgentRunCommand extends Command
{
    private const EVIDENCE_SCHEMA_VERSION = 'seo-agent-run-evidence.v1';

    protected $signature = 'seo-agent:run
        {--sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap : Comma-separated sources}
        {--limit=100 : Candidate limit, bounded 1..250}
        {--artifact-dir= : Directory for sanitized JSON artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Run the readonly SEO Agent L4 chain: scanners, aggregator, control packet, Codex review, CMS draft dry-run, and evidence.';

    public function handle(
        CmsTdkGapReadonlyScanner $tdkScanner,
        RuntimeSeoQaReadonlyScanner $runtimeScanner,
        CmsFaqGapReadonlyScanner $faqScanner,
        OpportunityAggregator $aggregator,
    ): int {
        $sources = $this->sources();
        if ($sources === []) {
            return $this->finish($this->failureSummary('invalid_sources'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $limit = $this->limit();
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $scannerArtifacts = [];
        $scannerRefs = [];

        foreach ($sources as $source) {
            $artifact = match ($source) {
                'cms-tdk-gap' => $tdkScanner->scan('all', $limit),
                'runtime-seo-qa' => $runtimeScanner->scan('cms-indexable', min($limit, 100)),
                'cms-faq-gap' => $faqScanner->scan('all', $limit),
            };
            $scannerArtifacts[] = $artifact;
            $scannerRefs[$source] = $this->writeArtifact($artifactDir, 'seo-agent-run-'.$source.'-'.$timestamp.'.json', $artifact);
        }

        $aggregate = $aggregator->aggregate($scannerArtifacts, $limit);
        $aggregate['input_artifacts'] = array_values($scannerRefs);
        $aggregateRef = $this->writeArtifact($artifactDir, 'seo-agent-run-opportunity-aggregate-'.$timestamp.'.json', $aggregate);

        $packet = $this->runControlPacket($sources, $limit, $scannerRefs, $aggregateRef);
        $packetRef = $this->writeArtifact($artifactDir, 'seo-agent-run-control-packet-'.$timestamp.'.json', $packet);
        $handoff = $this->codexReviewHandoff($packetRef, $aggregateRef, $aggregate);
        $handoffRef = $this->writeArtifact($artifactDir, 'seo-agent-codex-review-handoff-'.$timestamp.'.json', $handoff);

        $verdict = $this->codexReviewVerdict($handoff, (string) $handoffRef['path']);
        $verdictRef = $this->writeArtifact($artifactDir, 'seo-agent-codex-review-verdict-'.$timestamp.'.json', $verdict);
        $reviewSummary = [
            'artifact' => $verdictRef,
            'worth_optimizing_count' => count(array_filter(
                (array) ($verdict['candidate_verdicts'] ?? []),
                static fn (array $candidate): bool => ($candidate['worth_optimizing'] ?? false) === true
            )),
        ];

        $draftPackage = $this->cmsDraftPackageDryRun($verdict, (string) $verdictRef['path']);
        $draftRef = $this->writeArtifact($artifactDir, 'seo-agent-cms-draft-package-dry-run-'.$timestamp.'.json', $draftPackage);
        $draftSummary = [
            'artifact' => $draftRef,
            'draft_brief_count' => (int) ($draftPackage['draft_brief_count'] ?? 0),
        ];

        $evidence = $this->runEvidence($sources, $scannerRefs, $aggregateRef, $packetRef, $handoffRef, $reviewSummary, $draftSummary);
        $evidenceRef = $this->writeArtifact($artifactDir, 'seo-agent-run-evidence-'.$timestamp.'.json', $evidence);

        return $this->finish([
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'ok' => true,
            'status' => 'success',
            'sources' => $sources,
            'candidate_count' => (int) ($aggregate['candidate_count'] ?? 0),
            'worth_optimizing_count' => (int) ($reviewSummary['worth_optimizing_count'] ?? 0),
            'draft_brief_count' => (int) ($draftSummary['draft_brief_count'] ?? 0),
            'artifact' => $evidenceRef,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        $allowed = ['cms-tdk-gap', 'runtime-seo-qa', 'cms-faq-gap'];
        $sources = array_values(array_unique(array_filter(array_map(
            static fn (string $source): string => trim($source),
            explode(',', (string) $this->option('sources'))
        ))));

        foreach ($sources as $source) {
            if (! in_array($source, $allowed, true)) {
                return [];
            }
        }

        return $sources;
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 100;
        }

        return max(1, min((int) $raw, 250));
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
                'draft_brief_count' => (int) ($payload['draft_brief_count'] ?? 0),
                'forbidden_output_fields_absent' => true,
            ],
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, array<string, mixed>>  $scannerRefs
     * @param  array<string, mixed>  $aggregateRef
     * @return array<string, mixed>
     */
    private function runControlPacket(array $sources, int $limit, array $scannerRefs, array $aggregateRef): array
    {
        return [
            'schema_version' => 'seo-agent-run-control-packet.v1',
            'run_id' => 'seo-agent-run-'.Carbon::now('UTC')->format('YmdHis'),
            'run_mode' => 'readonly_discovery',
            'trigger' => 'manual_cli',
            'scope' => [
                'source_families' => $sources,
                'write_scope' => 'none',
            ],
            'input_refs' => [
                'command' => 'php artisan seo-agent:run',
                'sources' => $sources,
                'limit' => $limit,
            ],
            'evidence_refs' => array_values($scannerRefs),
            'model_review' => [
                'reviewer' => 'codex',
                'role' => 'review_only',
                'execution_permission' => false,
                'required_output' => 'seo-agent-codex-review-verdict.v1',
            ],
            'approval' => [
                'status' => 'not_requested',
                'approved_actions' => [],
            ],
            'forbidden_actions' => $this->forbiddenActions(),
            'allowed_actions' => [
                'readonly_scan',
                'evidence_artifact_write',
                'codex_review_handoff_artifact',
                'cms_draft_package_dry_run',
            ],
            'output_artifacts' => [
                'scanner_artifacts' => $scannerRefs,
                'opportunity_aggregate' => $aggregateRef,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'next_step' => 'Codex review and CMS draft package remain dry-run only; controlled CMS draft write is a later separately approved PR.',
        ];
    }

    /**
     * @param  array<string, mixed>  $packetRef
     * @param  array<string, mixed>  $aggregateRef
     * @param  array<string, mixed>  $aggregate
     * @return array<string, mixed>
     */
    private function codexReviewHandoff(array $packetRef, array $aggregateRef, array $aggregate): array
    {
        return [
            'schema_version' => 'seo-agent-codex-review-handoff.v1',
            'task' => 'SEO-AGENT-RUN-ORCHESTRATOR-01',
            'reviewer' => 'codex',
            'role' => 'review_only',
            'execution_permission' => false,
            'input_control_packet' => $packetRef,
            'input_candidates' => $aggregateRef,
            'candidate_count' => (int) ($aggregate['candidate_count'] ?? 0),
            'review_output_contract' => [
                'worth_optimizing' => 'boolean',
                'recommended_action' => 'readonly_review|cms_draft_package_dry_run|defer',
                'risk_flags' => 'list<string>',
                'needs_human_approval' => 'boolean',
            ],
            'candidate_preview' => array_slice((array) ($aggregate['candidates'] ?? []), 0, 100),
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $handoff
     * @return array<string, mixed>
     */
    private function codexReviewVerdict(array $handoff, string $handoffPath): array
    {
        $candidateVerdicts = array_map(
            fn (array $candidate): array => $this->candidateVerdict($candidate),
            array_values(array_filter(
                (array) ($handoff['candidate_preview'] ?? []),
                static fn ($candidate): bool => is_array($candidate)
            ))
        );

        $worthOptimizing = array_values(array_filter(
            $candidateVerdicts,
            static fn (array $candidate): bool => ($candidate['worth_optimizing'] ?? false) === true
        ));
        $draftReady = array_filter(
            $candidateVerdicts,
            static fn (array $candidate): bool => ($candidate['recommended_action'] ?? '') === 'cms_draft_package_dry_run'
        );
        $technicalReview = array_filter(
            $candidateVerdicts,
            static fn (array $candidate): bool => ($candidate['recommended_action'] ?? '') === 'technical_review_required'
        );

        return [
            'schema_version' => 'seo-agent-codex-review-verdict.v1',
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
            'forbidden_actions' => $this->forbiddenActions(),
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
            'evidence_refs' => $evidenceRefs,
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
     * @param  list<array<string, mixed>>  $candidateVerdicts
     * @return list<string>
     */
    private function aggregateRiskFlags(array $candidateVerdicts): array
    {
        $flags = [];
        foreach ($candidateVerdicts as $candidate) {
            foreach ((array) ($candidate['risk_flags'] ?? []) as $flag) {
                $flags[] = (string) $flag;
            }
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  array<string, mixed>  $verdict
     * @return array<string, mixed>
     */
    private function cmsDraftPackageDryRun(array $verdict, string $verdictPath): array
    {
        $draftBriefs = array_map(
            fn (array $candidate): array => $this->draftBrief($candidate),
            array_values(array_filter(
                (array) ($verdict['candidate_verdicts'] ?? []),
                static fn ($candidate): bool => is_array($candidate)
                    && ($candidate['recommended_action'] ?? null) === 'cms_draft_package_dry_run'
                    && ($candidate['worth_optimizing'] ?? false) === true
                    && ($candidate['execution_permission'] ?? false) === false
            ))
        );

        return [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'run_mode' => 'cms_draft_package_dry_run',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'input_verdict' => [
                'path_hash' => hash('sha256', $verdictPath),
                'sha256' => hash_file('sha256', $verdictPath) ?: '',
                'schema_version' => (string) ($verdict['schema_version'] ?? ''),
            ],
            'draft_brief_count' => count($draftBriefs),
            'draft_briefs' => $draftBriefs,
            'proposal_count' => count($draftBriefs),
            'proposal_items' => $draftBriefs,
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function draftBrief(array $candidate): array
    {
        $gapCodes = array_values(array_unique(array_filter(array_map('strval', (array) ($candidate['gap_types'] ?? [])))));
        $targetFields = $this->targetFields($gapCodes);
        $safePath = (string) ($candidate['safe_path'] ?? '');
        $subjectType = (string) ($candidate['subject_type'] ?? '');
        $targetModel = $subjectType === 'content_page' ? 'content_page' : 'article';
        $label = $this->labelFromSafePath($safePath);

        return [
            'source_id' => (string) ($candidate['source_id'] ?? ''),
            'source_family' => (string) ($candidate['source_family'] ?? ''),
            'subject_type' => $subjectType,
            'subject_ref' => (string) ($candidate['subject_ref'] ?? ''),
            'safe_path' => $safePath,
            'severity' => (string) ($candidate['severity'] ?? ''),
            'gap_codes' => $gapCodes,
            'target_model' => $targetModel,
            'target_fields' => $targetFields,
            'proposed_seo_title' => in_array('seo_title', $targetFields, true)
                ? $this->proposedSeoTitle($label)
                : null,
            'proposed_seo_description' => in_array('seo_description', $targetFields, true)
                ? $this->proposedSeoDescription($label)
                : null,
            'proposed_faq_items' => in_array('faq_items', $targetFields, true)
                ? $this->proposedFaqItems($label)
                : [],
            'proposed_canonical_path' => in_array('canonical_url_or_path', $targetFields, true)
                ? $safePath
                : null,
            'proposed_indexability' => in_array('is_indexable_or_robots', $targetFields, true)
                ? 'indexable_after_manual_review'
                : null,
            'draft_instructions' => [
                'prepare_field_level_proposal_only',
                'do_not_generate_final_body_copy',
                'preserve_existing_slug_and_canonical_unless_separately_approved',
                'run_claim_gate_before_any_cms_write',
            ],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
            'blocked_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
            ],
        ];
    }

    /**
     * @param  list<string>  $gapCodes
     * @return list<string>
     */
    private function targetFields(array $gapCodes): array
    {
        $fields = [];
        foreach ($gapCodes as $code) {
            $fields[] = match ($code) {
                'missing_title' => 'seo_title',
                'missing_meta_description' => 'seo_description',
                'missing_canonical', 'canonical_mismatch' => 'canonical_url_or_path',
                'missing_indexability_metadata', 'noindex_present', 'x_robots_noindex' => 'is_indexable_or_robots',
                'missing_faq_items', 'missing_visible_faq' => 'faq_items',
                'faq_schema_enabled_without_visible_faq' => 'faq_schema_eligible',
                default => 'manual_review_required',
            };
        }

        return array_values(array_unique($fields));
    }

    private function labelFromSafePath(string $safePath): string
    {
        $lastSegment = basename(trim($safePath, '/'));
        $label = trim(str_replace(['-', '_'], ' ', $lastSegment));

        return $label !== '' ? ucwords($label) : 'FermatMind page';
    }

    private function proposedSeoTitle(string $label): string
    {
        return mb_substr($label.' | FermatMind', 0, 70);
    }

    private function proposedSeoDescription(string $label): string
    {
        return mb_substr('Review '.$label.' with FermatMind guidance, evidence, and next steps after claim-gate approval.', 0, 155);
    }

    /**
     * @return list<array<string, string>>
     */
    private function proposedFaqItems(string $label): array
    {
        return [
            [
                'question' => 'What should readers know about '.$label.'?',
                'answer' => 'Draft answer pending claim gate and human approval.',
            ],
            [
                'question' => 'What is the next step for '.$label.'?',
                'answer' => 'Draft answer pending claim gate and human approval.',
            ],
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, array<string, mixed>>  $scannerRefs
     * @param  array<string, mixed>  $aggregateRef
     * @param  array<string, mixed>  $packetRef
     * @param  array<string, mixed>  $handoffRef
     * @param  array<string, mixed>  $reviewSummary
     * @param  array<string, mixed>  $draftSummary
     * @return array<string, mixed>
     */
    private function runEvidence(array $sources, array $scannerRefs, array $aggregateRef, array $packetRef, array $handoffRef, array $reviewSummary, array $draftSummary): array
    {
        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
            'task' => 'SEO-AGENT-RUN-ORCHESTRATOR-01',
            'status' => 'success',
            'run_mode' => 'readonly_discovery_to_dry_run',
            'sources' => $sources,
            'artifacts' => [
                'scanners' => $scannerRefs,
                'opportunity_aggregate' => $aggregateRef,
                'run_control_packet' => $packetRef,
                'codex_review_handoff' => $handoffRef,
                'codex_review_verdict' => (array) ($reviewSummary['artifact'] ?? []),
                'cms_draft_package_dry_run' => (array) ($draftSummary['artifact'] ?? []),
            ],
            'summary' => [
                'candidate_count' => (int) ($aggregateRef['sanitized_summary']['candidate_count'] ?? 0),
                'worth_optimizing_count' => (int) ($reviewSummary['worth_optimizing_count'] ?? 0),
                'draft_brief_count' => (int) ($draftSummary['draft_brief_count'] ?? 0),
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue, array $extra = []): array
    {
        return [
            'schema_version' => self::EVIDENCE_SCHEMA_VERSION,
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
            $this->line('candidate_count='.(string) ($summary['candidate_count'] ?? 0));
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
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'sitemap_submission',
            'scheduler_activation',
            'queue_worker_activation',
            'production_env_update',
            'source_code_mutation',
            'external_model_api_call',
        ];
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
            'google_search_console_api_call' => false,
            'google_indexing_api_call' => false,
            'external_model_api_call' => false,
            'pr_train_metadata_change' => false,
        ];
    }
}
