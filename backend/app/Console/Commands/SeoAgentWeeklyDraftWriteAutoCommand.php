<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgent\AutoApprovalPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class SeoAgentWeeklyDraftWriteAutoCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-weekly-draft-write-auto.v1';

    protected $signature = 'seo-agent:weekly-draft-write-auto
        {--sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap : Comma-separated readonly sources}
        {--limit=100 : Discovery candidate limit, bounded 1..250}
        {--draft-limit=10 : Maximum low-risk CMS draft revisions to write, 1..10}
        {--artifact-dir= : Directory for weekly draft-write evidence artifacts}
        {--json : Emit JSON summary}';

    protected $description = 'Run weekly SEO Agent discovery, auto-approve low-risk proposals, and write bounded CMS draft revisions without publish or search.';

    public function handle(AutoApprovalPolicy $policy): int
    {
        $sources = $this->sources();
        if ($sources === []) {
            return $this->finish($this->failureSummary('invalid_sources'));
        }

        $limit = $this->limit();
        $draftLimit = $this->draftLimit();
        if ($draftLimit === null) {
            return $this->finish($this->failureSummary('draft_limit_out_of_bounds'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $runDir = rtrim($artifactDir, '/').'/weekly-draft-write-auto-run-'.$timestamp;

        $weeklySummary = $this->runWeeklyReadonly($sources, $limit, $runDir);
        if (($weeklySummary['ok'] ?? false) !== true) {
            $evidence = $this->evidence($sources, $limit, $draftLimit, 'blocked', [
                'issues' => ['weekly_readonly_run_failed'],
                'weekly_summary' => $this->summaryForEvidence($weeklySummary),
            ]);
            $artifact = $this->writeArtifact($artifactDir, 'seo-agent-weekly-draft-write-auto-'.$timestamp.'.json', $evidence);

            return $this->finish($this->failureSummary('weekly_readonly_run_failed', ['artifact' => $artifact]));
        }

        $packagePath = $this->draftPackagePath($weeklySummary);
        if ($packagePath === null) {
            $evidence = $this->evidence($sources, $limit, $draftLimit, 'blocked', [
                'issues' => ['draft_package_artifact_missing'],
                'weekly_summary' => $this->summaryForEvidence($weeklySummary),
            ]);
            $artifact = $this->writeArtifact($artifactDir, 'seo-agent-weekly-draft-write-auto-'.$timestamp.'.json', $evidence);

            return $this->finish($this->failureSummary('draft_package_artifact_missing', ['artifact' => $artifact]));
        }

        $package = $this->readJson($packagePath);
        if (($package['schema_version'] ?? null) !== 'seo-agent-cms-draft-package-dry-run.v1') {
            return $this->finish($this->failureSummary('draft_package_schema_invalid'));
        }

        $proposals = $this->proposalItems($package);
        $policyResult = $policy->evaluateCandidates($proposals, $limit);
        $approvedProposals = $this->approvedProposals($proposals, $policyResult, $draftLimit);
        $filteredPackage = $this->filteredPackage($package, $approvedProposals, $policyResult);
        $filteredPackageRef = $this->writeArtifact($artifactDir, 'seo-agent-weekly-draft-write-auto-package-'.$timestamp.'.json', $filteredPackage);

        if ($approvedProposals === []) {
            $evidence = $this->evidence($sources, $limit, $draftLimit, 'success', [
                'weekly_summary' => $this->summaryForEvidence($weeklySummary),
                'source_package' => $this->artifactRef($packagePath, 'seo-agent-cms-draft-package-dry-run.v1'),
                'filtered_package' => $filteredPackageRef,
                'policy_summary' => $this->policySummary($policyResult),
                'draft_write' => [
                    'status' => 'skipped_no_auto_approved_proposals',
                    'writes_attempted' => false,
                    'rows_created' => 0,
                    'rows_skipped_existing' => 0,
                ],
            ]);
            $artifact = $this->writeArtifact($artifactDir, 'seo-agent-weekly-draft-write-auto-'.$timestamp.'.json', $evidence);

            return $this->finish($this->successSummary('success', $sources, 0, 0, 0, $artifact));
        }

        $writeSummary = $this->runDraftWriter((string) $filteredPackageRef['path'], $draftLimit);
        $writeOk = ($writeSummary['ok'] ?? false) === true && ($writeSummary['status'] ?? '') === 'success';
        $evidence = $this->evidence($sources, $limit, $draftLimit, $writeOk ? 'success' : 'blocked', [
            'weekly_summary' => $this->summaryForEvidence($weeklySummary),
            'source_package' => $this->artifactRef($packagePath, 'seo-agent-cms-draft-package-dry-run.v1'),
            'filtered_package' => $filteredPackageRef,
            'policy_summary' => $this->policySummary($policyResult),
            'draft_write' => $this->summaryForEvidence($writeSummary),
        ]);
        $artifact = $this->writeArtifact($artifactDir, 'seo-agent-weekly-draft-write-auto-'.$timestamp.'.json', $evidence);

        if (! $writeOk) {
            return $this->finish($this->failureSummary('cms_draft_write_failed', ['artifact' => $artifact]));
        }

        return $this->finish($this->successSummary(
            'success',
            $sources,
            count($approvedProposals),
            (int) ($writeSummary['rows_created'] ?? 0),
            (int) ($writeSummary['rows_skipped_existing'] ?? 0),
            $artifact
        ));
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

    private function draftLimit(): ?int
    {
        $limit = filter_var($this->option('draft-limit'), FILTER_VALIDATE_INT);

        return is_int($limit) && $limit >= 1 && $limit <= 10 ? $limit : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/weekly-draft-write-auto');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  list<string>  $sources
     * @return array<string, mixed>
     */
    private function runWeeklyReadonly(array $sources, int $limit, string $runDir): array
    {
        $command = $this->getApplication()?->find('seo-agent:weekly-readonly-runner');
        if ($command === null) {
            return $this->failureSummary('weekly_readonly_command_missing');
        }

        $buffer = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput([
            'command' => 'seo-agent:weekly-readonly-runner',
            '--sources' => implode(',', $sources),
            '--limit' => $limit,
            '--artifact-dir' => $runDir,
            '--json' => true,
        ]), $buffer);

        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary('weekly_readonly_summary_json_invalid', [
                'weekly_exit_code' => $exitCode,
            ]);
        }

        $summary['weekly_exit_code'] = $exitCode;

        return $summary;
    }

    private function draftPackagePath(array $weeklySummary): ?string
    {
        $weeklyArtifactPath = (string) data_get($weeklySummary, 'artifact.path', '');
        if ($weeklyArtifactPath === '' || ! is_file($weeklyArtifactPath)) {
            return null;
        }

        $weeklyArtifact = $this->readJson($weeklyArtifactPath);
        $runEvidencePath = (string) data_get($weeklyArtifact, 'run_artifact.path', '');
        if ($runEvidencePath === '' || ! is_file($runEvidencePath)) {
            return null;
        }

        $runEvidence = $this->readJson($runEvidencePath);
        $packagePath = (string) data_get($runEvidence, 'artifacts.cms_draft_package_dry_run.path', '');

        return $packagePath !== '' && is_file($packagePath) ? $packagePath : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('artifact_json_invalid');
        }

        return $decoded;
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
    private function approvedProposals(array $proposals, array $policyResult, int $draftLimit): array
    {
        $decisions = array_values(array_filter(
            (array) ($policyResult['candidate_decisions'] ?? []),
            static fn ($decision): bool => is_array($decision)
        ));

        $approved = [];
        foreach ($proposals as $index => $proposal) {
            $decision = $decisions[$index] ?? [];
            if (($decision['approval_decision'] ?? '') !== 'auto_approved') {
                continue;
            }
            if (! in_array('cms_draft_write_auto', (array) ($decision['allowed_next_actions'] ?? []), true)) {
                continue;
            }

            $approved[] = $proposal;
            if (count($approved) >= $draftLimit) {
                break;
            }
        }

        return $approved;
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  list<array<string, mixed>>  $approvedProposals
     * @param  array<string, mixed>  $policyResult
     * @return array<string, mixed>
     */
    private function filteredPackage(array $package, array $approvedProposals, array $policyResult): array
    {
        $filtered = $package;
        $filtered['draft_brief_count'] = count($approvedProposals);
        $filtered['draft_briefs'] = $approvedProposals;
        $filtered['proposal_count'] = count($approvedProposals);
        $filtered['proposal_items'] = $approvedProposals;
        $filtered['auto_approval_policy'] = [
            'schema_version' => AutoApprovalPolicy::SCHEMA_VERSION,
            'approval_mode' => 'low_risk_auto_approved',
            'auto_approved_count' => (int) ($policyResult['auto_approved_count'] ?? 0),
            'blocked_count' => (int) ($policyResult['blocked_count'] ?? 0),
            'selected_for_draft_write_count' => count($approvedProposals),
        ];

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    private function runDraftWriter(string $packagePath, int $draftLimit): array
    {
        $command = $this->getApplication()?->find('seo-agent:cms-draft-write');
        if ($command === null) {
            return $this->failureSummary('cms_draft_write_command_missing');
        }

        $buffer = new BufferedOutput();
        $exitCode = $command->run(new ArrayInput([
            'command' => 'seo-agent:cms-draft-write',
            '--package' => $packagePath,
            '--limit' => $draftLimit,
            '--auto-approve-low-risk' => true,
            '--execute' => true,
            '--json' => true,
        ]), $buffer);

        $summary = json_decode(trim($buffer->fetch()), true);
        if (! is_array($summary)) {
            return $this->failureSummary('cms_draft_write_summary_json_invalid', [
                'draft_write_exit_code' => $exitCode,
            ]);
        }

        $summary['draft_write_exit_code'] = $exitCode;

        return $summary;
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function evidence(array $sources, int $limit, int $draftLimit, string $status, array $extra): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-WEEKLY-DRAFT-WRITE-AUTO-BATCH10-01',
            'status' => $status,
            'run_mode' => 'weekly_discovery_to_low_risk_cms_draft_write',
            'trigger' => 'manual_cli_or_external_weekly_automation',
            'command' => 'php artisan seo-agent:weekly-draft-write-auto',
            'delegated_commands' => [
                'php artisan seo-agent:weekly-readonly-runner',
                'php artisan seo-agent:cms-draft-write --auto-approve-low-risk --execute',
            ],
            'sources' => $sources,
            'limit' => $limit,
            'draft_limit' => $draftLimit,
            ...$extra,
            'allowed_actions' => [
                'readonly_scan',
                'evidence_artifact_write',
                'auto_approval_policy_evaluation',
                'cms_draft_revision_write',
            ],
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees(),
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

        return $this->artifactRef($path, (string) ($payload['schema_version'] ?? 'unknown'));
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
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function summaryForEvidence(array $summary): array
    {
        return [
            'schema_version' => (string) ($summary['schema_version'] ?? ''),
            'ok' => (bool) ($summary['ok'] ?? false),
            'status' => (string) ($summary['status'] ?? ''),
            'candidate_count' => (int) ($summary['candidate_count'] ?? 0),
            'draft_brief_count' => (int) ($summary['draft_brief_count'] ?? 0),
            'planned_count' => (int) ($summary['planned_count'] ?? 0),
            'rows_created' => (int) ($summary['rows_created'] ?? 0),
            'rows_skipped_existing' => (int) ($summary['rows_skipped_existing'] ?? 0),
            'rows_failed' => array_values((array) ($summary['rows_failed'] ?? [])),
            'writes_attempted' => (bool) ($summary['writes_attempted'] ?? false),
            'writes_committed' => (bool) ($summary['writes_committed'] ?? false),
            'artifact' => is_array($summary['artifact'] ?? null) ? (array) $summary['artifact'] : [],
            'issues' => array_values(array_map('strval', (array) ($summary['issues'] ?? []))),
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
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function successSummary(string $status, array $sources, int $approvedCount, int $rowsCreated, int $rowsSkipped, array $artifact): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => $status,
            'sources' => $sources,
            'auto_approved_count' => $approvedCount,
            'rows_created' => $rowsCreated,
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
    private function forbiddenActions(): array
    {
        return [
            'cms_publish',
            'live_published_content_mutation',
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
            'cms_publish' => false,
            'published_revision_mutation' => false,
            'live_published_content_mutation' => false,
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
