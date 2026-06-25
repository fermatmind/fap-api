<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class PersonalityTdkRuntimePromotionSearchGateReadinessCommand extends Command
{
    private const SCHEMA_VERSION = 'personality-tdk-runtime-promotion-search-gate-readiness.v1';

    private const EXPECTED_TARGETS = [
        '/zh/personality/intp-a',
        '/zh/personality/esfp-a',
        '/en/personality/enfj-a',
    ];

    private const FORBIDDEN_STRINGS = [
        'raw_url',
        'raw_query',
        'credential_path',
        'service_account_json',
        'client_email',
        'private_key',
        'Bearer ',
        'Cookie:',
        'Set-Cookie:',
        'content_md',
        'content_html',
        'cms_draft_body',
    ];

    protected $signature = 'personality:tdk-runtime-promotion-search-gate-readiness
        {--approval-draft-gate= : Path to personality TDK next-batch approval/draft gate evidence}
        {--draft-readback= : Optional path to post-draft runtime/readback QA evidence}
        {--promotion-dry-run= : Optional path to MBTI64 promotion dry-run evidence}
        {--artifact-dir= : Directory for sanitized gate readiness evidence}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only gate readiness for personality TDK runtime QA, promotion dry-run, and later search propagation gates.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $loaded = $this->loadInputs();
        if (($loaded['issue'] ?? null) !== null) {
            $summary = $this->failureSummary((string) $loaded['issue'], (array) ($loaded['extra'] ?? []));
            $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

            return $this->finish($summary);
        }

        $summary = $this->evidence($loaded);
        $summary['artifact'] = $this->writeArtifact($artifactDir, $summary);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => (bool) $summary['ok'],
            'status' => (string) $summary['status'],
            'target_count' => (int) data_get($summary, 'counts.target_count', 0),
            'runtime_readback_ready' => (bool) data_get($summary, 'gate_statuses.runtime_readback_ready', false),
            'promotion_dry_run_ready' => (bool) data_get($summary, 'gate_statuses.promotion_dry_run_ready', false),
            'promotion_execute_approval_ready' => (bool) data_get($summary, 'gate_statuses.promotion_execute_approval_ready', false),
            'artifact' => $summary['artifact'],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $loaded
     * @return array<string, mixed>
     */
    private function evidence(array $loaded): array
    {
        $approvalGate = (array) $loaded['approval_draft_gate']['payload'];
        $readback = $loaded['draft_readback']['payload'] ?? null;
        $promotion = $loaded['promotion_dry_run']['payload'] ?? null;
        $issues = [];

        if (($approvalGate['schema_version'] ?? null) !== 'personality-tdk-next-batch-approval-draft-gate.v1') {
            $issues[] = 'approval_draft_gate_schema_invalid';
        }
        if (($approvalGate['ok'] ?? false) !== true || ($approvalGate['status'] ?? null) !== 'planned') {
            $issues[] = 'approval_draft_gate_not_planned';
        }
        if ((bool) data_get($approvalGate, 'gate_statuses.approval_queue_dry_run_ready') !== true) {
            $issues[] = 'approval_queue_dry_run_not_ready';
        }
        if ((bool) data_get($approvalGate, 'gate_statuses.cms_projection_draft_dry_run_ready') !== true) {
            $issues[] = 'cms_projection_draft_dry_run_not_ready';
        }

        $targets = $this->targetsFromApprovalGate($approvalGate);
        if ($this->sortedPaths($targets) !== $this->sortedPaths(self::EXPECTED_TARGETS)) {
            $issues[] = 'expected_target_set_mismatch';
        }

        $readbackReady = false;
        $promotionReady = false;
        $readbackStatus = 'missing';
        $promotionStatus = 'missing';
        $reviewIssues = [];

        if (is_array($readback)) {
            $readbackStatus = $this->readbackReady($readback) ? 'pass' : 'blocked';
            $readbackReady = $readbackStatus === 'pass';
            if (! $readbackReady) {
                $reviewIssues[] = 'draft_readback_not_passing';
            }
        } else {
            $reviewIssues[] = 'draft_readback_evidence_missing';
        }

        if (is_array($promotion)) {
            $promotionStatus = $this->promotionDryRunReady($promotion) ? 'pass' : 'blocked';
            $promotionReady = $promotionStatus === 'pass';
            if (! $promotionReady) {
                $reviewIssues[] = 'promotion_dry_run_not_passing';
            }
        } else {
            $reviewIssues[] = 'promotion_dry_run_evidence_missing';
        }

        $blocked = $issues !== [];
        $ready = ! $blocked && $readbackReady && $promotionReady;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => ! $blocked,
            'status' => $blocked ? 'blocked' : ($ready ? 'ready_for_separate_promotion_approval' : 'review_required'),
            'dry_run' => true,
            'execute' => false,
            'generated_at' => Carbon::now('UTC')->toIso8601String(),
            'source_artifacts' => $this->sourceArtifacts($loaded),
            'counts' => [
                'target_count' => count($targets),
                'expected_target_count' => 3,
            ],
            'targets' => $targets,
            'evidence_statuses' => [
                'approval_draft_gate' => $blocked ? 'blocked' : 'pass',
                'draft_readback' => $readbackStatus,
                'promotion_dry_run' => $promotionStatus,
            ],
            'gate_statuses' => [
                'runtime_readback_ready' => $readbackReady,
                'promotion_dry_run_ready' => $promotionReady,
                'promotion_execute_approval_ready' => $ready,
                'post_promotion_search_gate_ready' => false,
                'sitemap_llms_gate_ready' => false,
                'search_channel_enqueue_ready' => false,
            ],
            'future_command_templates' => [
                'draft_readback_after_cms_draft' => [
                    'php artisan personality:tdk-runtime-promotion-search-gate-readiness',
                    '--approval-draft-gate=<approval-draft-gate-evidence>',
                    '--draft-readback=<draft-readback-qa-evidence>',
                    '--promotion-dry-run=<promotion-dry-run-evidence>',
                    '--json',
                ],
                'promotion_dry_run' => [
                    'php artisan personality:mbti64-cms-revision-promote',
                    '--package=<cms-draft-package-or-agent-package>',
                    '--dry-run',
                    '--fresh-query-backed-5',
                    '--json',
                ],
                'post_promotion_search_gate_readiness' => [
                    'Run only after separate promotion approval and post-promotion runtime QA pass.',
                    'Then evaluate sitemap/llms/search propagation with a separate gate and approval.',
                ],
            ],
            'separate_approval_templates' => $ready ? [
                'promotion_execute' => 'I explicitly approve MBTI64-BACKEND-PROMOTION-CONTRACT-01 promotion execute for the 3 personality TDK next-batch targets after passing readback and promotion dry-run evidence; no index, no sitemap, no llms, no search release.',
            ] : [],
            'issues' => array_values(array_unique($issues)),
            'review_required_issues' => array_values(array_unique($reviewIssues)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string, mixed>  $approvalGate
     * @return list<array<string, mixed>>
     */
    private function targetsFromApprovalGate(array $approvalGate): array
    {
        $targets = [];
        foreach ((array) ($approvalGate['targets'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }
            $path = (string) ($target['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $targets[] = [
                'path' => $path,
                'locale' => (string) ($target['locale'] ?? ''),
                'framework' => (string) ($target['framework'] ?? ''),
                'page_type' => (string) ($target['page_type'] ?? ''),
                'mbti_type' => (string) ($target['mbti_type'] ?? ''),
            ];
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $readback
     */
    private function readbackReady(array $readback): bool
    {
        if (($readback['ok'] ?? false) !== true) {
            return false;
        }
        if (! in_array((string) ($readback['status'] ?? ''), ['success', 'pass', 'ready'], true)) {
            return false;
        }
        $paths = $this->extractPaths($readback);
        if ($this->sortedPaths($paths) !== $this->sortedPaths(self::EXPECTED_TARGETS)) {
            return false;
        }

        foreach ($this->extractRows($readback) as $row) {
            if (($row['status'] ?? 'success') !== 'success' && ($row['decision'] ?? 'pass') !== 'pass') {
                return false;
            }
            if (($row['public_runtime_changed'] ?? false) === true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $promotion
     */
    private function promotionDryRunReady(array $promotion): bool
    {
        if (($promotion['ok'] ?? false) !== true) {
            return false;
        }
        if ((bool) ($promotion['dry_run'] ?? false) !== true || (bool) ($promotion['write'] ?? false) !== false) {
            return false;
        }
        if (! in_array((string) ($promotion['status'] ?? ''), ['planned', 'success', 'ready'], true)) {
            return false;
        }
        $paths = $this->extractPaths($promotion);
        if ($this->sortedPaths($paths) !== $this->sortedPaths(self::EXPECTED_TARGETS)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractPaths(array $payload): array
    {
        $paths = [];
        foreach ($this->extractRows($payload) as $row) {
            $path = (string) ($row['path'] ?? $row['safe_path'] ?? $row['canonical_path'] ?? '');
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        foreach (['targets', 'items', 'candidates', 'promotion_candidates', 'planned_items', 'results'] as $key) {
            $rows = array_values(array_filter((array) ($payload[$key] ?? []), 'is_array'));
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param  list<string>|list<array<string, mixed>>  $paths
     * @return list<string>
     */
    private function sortedPaths(array $paths): array
    {
        $values = [];
        foreach ($paths as $path) {
            $values[] = is_array($path) ? (string) ($path['path'] ?? '') : (string) $path;
        }
        $values = array_values(array_filter($values, static fn (string $path): bool => $path !== ''));
        sort($values);

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInputs(): array
    {
        $approval = $this->loadJsonInput((string) $this->option('approval-draft-gate'), 'approval_draft_gate', true);
        if (($approval['issue'] ?? null) !== null) {
            return $approval;
        }
        $readback = $this->loadJsonInput((string) $this->option('draft-readback'), 'draft_readback', false);
        if (($readback['issue'] ?? null) !== null) {
            return $readback;
        }
        $promotion = $this->loadJsonInput((string) $this->option('promotion-dry-run'), 'promotion_dry_run', false);
        if (($promotion['issue'] ?? null) !== null) {
            return $promotion;
        }

        return [
            'approval_draft_gate' => $approval,
            'draft_readback' => $readback,
            'promotion_dry_run' => $promotion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonInput(string $rawPath, string $kind, bool $required): array
    {
        $path = trim($rawPath);
        if ($path === '') {
            return $required ? ['issue' => $kind.'_required'] : ['path' => null, 'sha256' => null, 'payload' => null];
        }
        $path = $this->readablePath($path);
        if ($path === null) {
            return ['issue' => $kind.'_unreadable'];
        }
        $raw = (string) File::get($path);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return ['issue' => 'forbidden_input_field_present', 'extra' => ['kind' => $kind, 'forbidden_matches' => $forbidden]];
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return ['issue' => $kind.'_json_invalid'];
        }

        return [
            'path' => $path,
            'sha256' => hash('sha256', $raw),
            'payload' => $payload,
        ];
    }

    private function readablePath(string $rawPath): ?string
    {
        $path = trim($rawPath);
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return File::isFile($path) && is_readable($path) ? $path : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/personality/tdk-runtime-promotion-search-gate-readiness');
        }
        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);
        File::ensureDirectoryExists($dir);

        return is_dir($dir) && is_writable($dir) ? $dir : null;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStringsPresent(string $payload): array
    {
        $matches = [];
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            if (str_contains($payload, $needle)) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $loaded
     * @return array<string, mixed>
     */
    private function sourceArtifacts(array $loaded): array
    {
        $artifacts = [];
        foreach (['approval_draft_gate', 'draft_readback', 'promotion_dry_run'] as $key) {
            $artifacts[$key] = [
                'path' => $loaded[$key]['path'] ?? null,
                'sha256' => $loaded[$key]['sha256'] ?? null,
            ];
        }

        return $artifacts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function writeArtifact(string $dir, array $payload): array
    {
        $path = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'personality-tdk-runtime-promotion-search-gate-readiness-'.Carbon::now('UTC')->format('Ymd\THis\Z').'.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Failed to encode personality TDK runtime/promotion/search gate readiness artifact.');
        }
        File::put($path, $encoded.PHP_EOL);

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @return array<string, false>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'approval_queue_write' => false,
            'cms_draft_write' => false,
            'cms_promotion' => false,
            'cms_publish' => false,
            'indexability_change' => false,
            'sitemap_llms_mutation' => false,
            'search_channel_enqueue' => false,
            'live_submit' => false,
            'frontend_metadata_edit' => false,
            'deploy' => false,
            'revalidation' => false,
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
            'dry_run' => true,
            'execute' => false,
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
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
            $this->line('status='.(string) ($summary['status'] ?? ''));
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
