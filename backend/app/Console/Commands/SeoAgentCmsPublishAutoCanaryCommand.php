<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgent\AutoApprovalPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentCmsPublishAutoCanaryCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-cms-publish-auto-canary.v1';

    private const PACKAGE_SCHEMA_VERSION = 'seo-agent-cms-draft-package-dry-run.v1';

    private const DRAFT_WRITE_SCHEMA_VERSION = 'seo-agent-controlled-cms-draft-write.v1';

    protected $signature = 'seo-agent:cms-publish-auto-canary
        {--package= : Path to a seo-agent-cms-draft-package-dry-run.v1 JSON artifact}
        {--draft-write-evidence= : Path to a seo-agent-controlled-cms-draft-write.v1 JSON artifact}
        {--limit=3 : Maximum low-risk ContentPage canaries to publish, 1..3}
        {--artifact-dir= : Directory for publish auto-canary evidence artifacts}
        {--auto-approve-low-risk : Required for execute mode}
        {--execute : Actually publish bounded low-risk ContentPage canaries}
        {--json : Emit JSON summary}';

    protected $description = 'Auto-publish up to three low-risk SEO Agent ContentPage canaries; never enqueues search or indexing.';

    public function handle(AutoApprovalPolicy $policy): int
    {
        $packagePath = $this->readablePath((string) $this->option('package'));
        $evidencePath = $this->readablePath((string) $this->option('draft-write-evidence'));
        if ($packagePath === null || $evidencePath === null) {
            return $this->finish($this->failureSummary('input_artifact_unreadable'));
        }

        $limit = $this->limit();
        if ($limit === null) {
            return $this->finish($this->failureSummary('limit_out_of_bounds'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $packageRaw = (string) file_get_contents($packagePath);
        $evidenceRaw = (string) file_get_contents($evidencePath);
        $forbidden = array_values(array_unique(array_merge(
            $this->forbiddenStringsPresent($packageRaw),
            $this->forbiddenStringsPresent($evidenceRaw),
        )));
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $package = json_decode($packageRaw, true);
        $draftWrite = json_decode($evidenceRaw, true);
        if (! is_array($package) || ! is_array($draftWrite)) {
            return $this->finish($this->failureSummary('input_artifact_json_invalid'));
        }

        $packageIssue = $this->validatePackage($package);
        if ($packageIssue !== null) {
            return $this->finish($this->failureSummary($packageIssue));
        }

        $packageSha = hash_file('sha256', $packagePath) ?: '';
        $draftIssue = $this->validateDraftWriteEvidence($draftWrite, $packageSha);
        if ($draftIssue !== null) {
            return $this->finish($this->failureSummary($draftIssue, [
                'package_sha256' => $packageSha,
            ]));
        }

        $proposals = $this->proposalItems($package);
        $policyResult = $policy->evaluateCandidates($proposals, count($proposals) > 0 ? count($proposals) : 1);
        $selected = $this->publishableContentPageProposals($proposals, $policyResult, $limit);
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');

        if ($selected === []) {
            $evidence = $this->evidence($packagePath, $evidencePath, $packageSha, $limit, 'success', [
                'publish_plan' => [
                    'status' => 'skipped_no_auto_approved_content_page_candidates',
                    'selected_count' => 0,
                    'planned_count' => 0,
                ],
                'policy_summary' => $this->policySummary($policyResult),
            ]);
            $artifact = $this->writeArtifact($artifactDir, 'seo-agent-cms-publish-auto-canary-'.$timestamp.'.json', $evidence);

            return $this->finish($this->successSummary('success', 0, 0, 0, $artifact));
        }

        $execute = (bool) $this->option('execute');
        if ($execute && ! (bool) $this->option('auto-approve-low-risk')) {
            return $this->finish($this->failureSummary('auto_approve_low_risk_required_for_execute', [
                'package_sha256' => $packageSha,
            ]));
        }

        $results = [];
        foreach ($selected as $proposal) {
            $result = $this->runPublishCanary($packagePath, $evidencePath, $packageSha, (string) ($proposal['subject_ref'] ?? ''), $execute);
            $results[] = $result;
            if (($result['ok'] ?? false) !== true) {
                break;
            }
        }

        $ok = count($results) === count($selected)
            && count(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) !== true)) === 0;
        $published = array_sum(array_map(static fn (array $result): int => (int) ($result['published_count'] ?? 0), $results));
        $skipped = array_sum(array_map(static fn (array $result): int => (int) ($result['rows_skipped_existing'] ?? 0), $results));
        $planned = array_sum(array_map(static fn (array $result): int => (int) ($result['planned_count'] ?? 0), $results));

        $evidence = $this->evidence($packagePath, $evidencePath, $packageSha, $limit, $ok ? 'success' : 'blocked', [
            'policy_summary' => $this->policySummary($policyResult),
            'selected_subject_refs' => array_map(static fn (array $proposal): string => (string) ($proposal['subject_ref'] ?? ''), $selected),
            'publish_results' => array_map(fn (array $result): array => $this->summaryForEvidence($result), $results),
            'publish_summary' => [
                'execute' => $execute,
                'selected_count' => count($selected),
                'planned_count' => $planned,
                'published_count' => $published,
                'rows_skipped_existing' => $skipped,
                'rows_failed' => array_values(array_filter(
                    array_map(static fn (array $result): array => [
                        'status' => (string) ($result['status'] ?? ''),
                        'issues' => array_values(array_map('strval', (array) ($result['issues'] ?? []))),
                    ], $results),
                    static fn (array $failure): bool => $failure['issues'] !== []
                )),
            ],
        ]);
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-cms-publish-auto-canary-'.$timestamp.'.json', $evidence);

        if (! $ok) {
            return $this->finish($this->failureSummary('cms_publish_auto_canary_failed', ['artifact' => $artifact]));
        }

        return $this->finish($this->successSummary(
            'success',
            count($selected),
            $execute ? $published : $planned,
            $skipped,
            $artifact
        ));
    }

    private function readablePath(string $path): ?string
    {
        $path = trim($path);
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
            $dir = storage_path('app/seo-agent/cms-publish-auto-canary');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function validatePackage(array $package): ?string
    {
        if (($package['schema_version'] ?? null) !== self::PACKAGE_SCHEMA_VERSION) {
            return 'package_schema_invalid';
        }
        if ((bool) ($package['dry_run'] ?? false) !== true) {
            return 'package_not_dry_run';
        }
        if ((bool) ($package['cms_write_allowed'] ?? true) !== false || (bool) ($package['execution_permission'] ?? true) !== false) {
            return 'package_execution_boundary_invalid';
        }
        if ((bool) ($package['claim_gate_required'] ?? false) !== true
            || (bool) ($package['human_approval_required'] ?? false) !== true) {
            return 'package_approval_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draftWrite
     */
    private function validateDraftWriteEvidence(array $draftWrite, string $packageSha): ?string
    {
        if (($draftWrite['schema_version'] ?? null) !== self::DRAFT_WRITE_SCHEMA_VERSION) {
            return 'draft_write_evidence_schema_invalid';
        }
        if (($draftWrite['status'] ?? null) !== 'success') {
            return 'draft_write_evidence_not_success';
        }
        if ((string) ($draftWrite['package_sha256'] ?? '') !== $packageSha) {
            return 'draft_write_package_sha_mismatch';
        }
        if ((bool) ($draftWrite['writes_attempted'] ?? false) !== true) {
            return 'draft_write_not_executed';
        }
        if ((bool) data_get($draftWrite, 'negative_guarantees.cms_publish', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.search_channel_submit', true) !== false
            || (bool) data_get($draftWrite, 'negative_guarantees.indexing_request', true) !== false) {
            return 'draft_write_boundary_invalid';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return list<array<string, mixed>>
     */
    private function proposalItems(array $package): array
    {
        $items = $package['proposal_items'] ?? $package['draft_briefs'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     * @param  array<string, mixed>  $policyResult
     * @return list<array<string, mixed>>
     */
    private function publishableContentPageProposals(array $proposals, array $policyResult, int $limit): array
    {
        $decisions = array_values(array_filter(
            (array) ($policyResult['candidate_decisions'] ?? []),
            static fn ($decision): bool => is_array($decision)
        ));

        $selected = [];
        foreach ($proposals as $index => $proposal) {
            $decision = $decisions[$index] ?? [];
            if (($decision['approval_decision'] ?? '') !== 'auto_approved') {
                continue;
            }
            if (($decision['target_model'] ?? '') !== 'content_page') {
                continue;
            }
            if (! in_array('cms_publish_auto_canary', (array) ($decision['allowed_next_actions'] ?? []), true)) {
                continue;
            }

            $selected[] = $proposal;
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    private function runPublishCanary(string $packagePath, string $evidencePath, string $packageSha, string $subjectRef, bool $execute): array
    {
        $command = $this->getApplication()?->find('seo-agent:cms-publish-canary');
        if ($command === null) {
            return $this->failureSummary('cms_publish_canary_command_missing');
        }

        $input = [
            'command' => 'seo-agent:cms-publish-canary',
            '--package' => $packagePath,
            '--draft-write-evidence' => $evidencePath,
            '--limit' => 1,
            '--subject-ref' => $subjectRef,
            '--json' => true,
        ];

        if ($execute) {
            $input['--confirm-package-sha256'] = $packageSha;
            $input['--auto-approve-low-risk'] = true;
            $input['--execute'] = true;
        }

        $buffer = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput($input), $buffer);
        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary('cms_publish_canary_summary_json_invalid', [
                'publish_canary_exit_code' => $exitCode,
                'subject_ref' => $subjectRef,
            ]);
        }

        $summary['publish_canary_exit_code'] = $exitCode;

        return $summary;
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

        return $this->artifactRef($path, (string) ($payload['schema_version'] ?? self::SCHEMA_VERSION));
    }

    /**
     * @return array<string, mixed>
     */
    private function artifactRef(string $path, string $schemaVersion): array
    {
        return [
            'path' => $path,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'schema_version' => $schemaVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $policyResult
     * @return array<string, mixed>
     */
    private function policySummary(array $policyResult): array
    {
        return [
            'schema_version' => (string) ($policyResult['schema_version'] ?? AutoApprovalPolicy::SCHEMA_VERSION),
            'candidate_count' => (int) ($policyResult['candidate_count'] ?? 0),
            'auto_approved_count' => (int) ($policyResult['auto_approved_count'] ?? 0),
            'blocked_count' => (int) ($policyResult['blocked_count'] ?? 0),
            'candidate_decisions' => array_values((array) ($policyResult['candidate_decisions'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function summaryForEvidence(array $summary): array
    {
        return [
            'schema_version' => (string) ($summary['schema_version'] ?? ''),
            'ok' => (bool) ($summary['ok'] ?? false),
            'status' => (string) ($summary['status'] ?? ''),
            'dry_run' => (bool) ($summary['dry_run'] ?? false),
            'execute' => (bool) ($summary['execute'] ?? false),
            'planned_count' => (int) ($summary['planned_count'] ?? 0),
            'published_count' => (int) ($summary['published_count'] ?? 0),
            'rows_skipped_existing' => (int) ($summary['rows_skipped_existing'] ?? 0),
            'writes_attempted' => (bool) ($summary['writes_attempted'] ?? false),
            'writes_committed' => (bool) ($summary['writes_committed'] ?? false),
            'affected_refs' => array_values((array) ($summary['affected_refs'] ?? [])),
            'rollback_evidence' => is_array($summary['rollback_evidence'] ?? null) ? (array) $summary['rollback_evidence'] : [],
            'issues' => array_values(array_map('strval', (array) ($summary['issues'] ?? []))),
            'boundaries' => is_array($summary['boundaries'] ?? null) ? (array) $summary['boundaries'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(string $packagePath, string $evidencePath, string $packageSha, int $limit, string $status, array $extra): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-CMS-PUBLISH-AUTO-CANARY3-01',
            'status' => $status,
            'run_mode' => 'low_risk_content_page_publish_canary3',
            'trigger' => 'manual_cli_or_l5_low_risk_scheduler',
            'command' => 'php artisan seo-agent:cms-publish-auto-canary',
            'delegated_command' => 'php artisan seo-agent:cms-publish-canary',
            'package_artifact' => $this->artifactRef($packagePath, self::PACKAGE_SCHEMA_VERSION),
            'draft_write_evidence' => $this->artifactRef($evidencePath, self::DRAFT_WRITE_SCHEMA_VERSION),
            'package_sha256' => $packageSha,
            'max_rows_per_execution' => 3,
            'requested_limit' => $limit,
            ...$extra,
            'allowed_actions' => [
                'auto_approval_policy_evaluation',
                'content_page_publish_canary',
                'evidence_artifact_write',
            ],
            'forbidden_actions' => $this->forbiddenActions(),
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
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'issues' => [$issue],
            ...$extra,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function successSummary(string $status, int $selectedCount, int $publishedOrPlannedCount, int $rowsSkipped, array $artifact): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $status,
            'selected_count' => $selectedCount,
            'published_or_planned_count' => $publishedOrPlannedCount,
            'rows_skipped_existing' => $rowsSkipped,
            'artifact' => $artifact,
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
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $raw): array
    {
        $matches = [];
        foreach ([
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
        ] as $needle) {
            if (str_contains($raw, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function forbiddenActions(): array
    {
        return [
            'article_auto_publish',
            'cms_bulk_publish',
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
            'article_publish' => false,
            'cms_bulk_publish' => false,
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
            'frontend_code_mutation' => false,
        ];
    }
}
