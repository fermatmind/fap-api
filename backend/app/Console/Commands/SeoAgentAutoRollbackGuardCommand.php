<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SeoAgentAutoRollbackGuardCommand extends Command
{
    private const SCHEMA_VERSION = 'seo-agent-auto-rollback-guard.v1';

    private const SINGLE_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-canary.v1';

    private const AUTO_PUBLISH_SCHEMA_VERSION = 'seo-agent-cms-publish-auto-canary.v1';

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

    protected $signature = 'seo-agent:auto-rollback-guard
        {--run-evidence= : Path to SEO Agent run/publish evidence JSON}
        {--mode=preflight : Guard mode: preflight or post-publish}
        {--artifact-dir= : Directory for rollback guard evidence artifacts}
        {--execute : Execute at most one eligible ContentPage rollback canary}
        {--json : Emit JSON summary}';

    protected $description = 'L5-A stop-the-line guard for SEO Agent runs with bounded ContentPage rollback canary support.';

    public function handle(): int
    {
        $evidencePath = $this->readablePath((string) $this->option('run-evidence'));
        if ($evidencePath === null) {
            return $this->finish($this->failureSummary('run_evidence_unreadable'));
        }

        $mode = $this->mode();
        if ($mode === null) {
            return $this->finish($this->failureSummary('mode_invalid'));
        }

        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary('artifact_dir_unwritable'));
        }

        $raw = (string) file_get_contents($evidencePath);
        $forbidden = $this->forbiddenStringsPresent($raw);
        if ($forbidden !== []) {
            return $this->finish($this->failureSummary('forbidden_input_field_present', [
                'forbidden_matches' => $forbidden,
            ]));
        }

        $evidence = json_decode($raw, true);
        if (! is_array($evidence)) {
            return $this->finish($this->failureSummary('run_evidence_json_invalid'));
        }

        $execute = (bool) $this->option('execute');
        $result = $mode === 'preflight'
            ? $this->preflight($evidence)
            : $this->postPublish($evidence, $execute);

        $artifact = $this->writeArtifact($artifactDir, $evidencePath, $mode, $execute, $result);

        return $this->finish([
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => (string) ($result['status'] ?? 'pass'),
            'mode' => $mode,
            'execute' => $execute,
            'stop_the_line' => (bool) ($result['stop_the_line'] ?? false),
            'rollback_executed_count' => (int) ($result['rollback_executed_count'] ?? 0),
            'artifact' => $artifact,
            'negative_guarantees' => $this->negativeGuarantees((int) ($result['rollback_executed_count'] ?? 0) > 0),
        ]);
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

    private function mode(): ?string
    {
        $mode = trim((string) $this->option('mode'));

        return in_array($mode, ['preflight', 'post-publish'], true) ? $mode : null;
    }

    private function artifactDir(): ?string
    {
        $dir = trim((string) $this->option('artifact-dir'));
        if ($dir === '' || str_contains($dir, "\0")) {
            $dir = storage_path('app/seo-agent/auto-rollback-guard');
        }

        $dir = str_starts_with($dir, '/') ? $dir : base_path($dir);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function preflight(array $evidence): array
    {
        $issues = [];
        if (($evidence['status'] ?? 'success') === 'blocked') {
            $issues[] = 'upstream_evidence_blocked';
        }
        if ((bool) data_get($evidence, 'negative_guarantees.cms_bulk_publish', false) === true
            || (bool) data_get($evidence, 'negative_guarantees.google_indexing_api_call', false) === true
            || (bool) data_get($evidence, 'negative_guarantees.scheduler_activation', false) === true) {
            $issues[] = 'negative_guarantee_violation';
        }

        return [
            'status' => $issues === [] ? 'pass' : 'blocked',
            'stop_the_line' => $issues !== [],
            'issues' => array_values(array_unique($issues)),
            'guard_actions' => $issues === [] ? ['allow_next_step'] : ['pause_publish', 'pause_indexnow'],
            'rollback_plan' => [
                'available' => false,
                'reason' => 'preflight_mode_does_not_rollback',
            ],
            'rollback_executed_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function postPublish(array $evidence, bool $execute): array
    {
        $schema = (string) ($evidence['schema_version'] ?? '');
        if (! in_array($schema, [self::SINGLE_PUBLISH_SCHEMA_VERSION, self::AUTO_PUBLISH_SCHEMA_VERSION], true)) {
            return [
                'status' => 'blocked',
                'stop_the_line' => true,
                'issues' => ['unsupported_post_publish_evidence_schema'],
                'guard_actions' => ['pause_publish', 'pause_indexnow'],
                'rollback_plan' => ['available' => false],
                'rollback_executed_count' => 0,
            ];
        }

        $targets = $this->rollbackTargets($evidence);
        $issues = [];
        foreach ($targets as $target) {
            if (($target['rollback_available'] ?? false) !== true) {
                $issues[] = 'rollback_evidence_missing';
            }
            if (($target['current_state_ok'] ?? false) !== true) {
                $issues[] = 'post_publish_current_state_not_safe';
            }
        }
        if ($targets === []) {
            $issues[] = 'published_content_page_target_missing';
        }

        $rollbackResult = [
            'attempted' => false,
            'executed_count' => 0,
            'skipped_count' => 0,
            'issues' => [],
        ];

        if ($execute) {
            $eligible = array_values(array_filter($targets, static fn (array $target): bool => ($target['rollback_available'] ?? false) === true));
            if (count($eligible) !== 1) {
                $issues[] = 'execute_requires_exactly_one_rollback_target';
            } elseif ($issues === []) {
                $rollbackResult = $this->executeRollback($eligible[0]);
                if (($rollbackResult['executed_count'] ?? 0) !== 1) {
                    $issues[] = 'rollback_execute_failed';
                }
            }
        }

        $issues = array_values(array_unique(array_merge($issues, array_map('strval', (array) ($rollbackResult['issues'] ?? [])))));

        return [
            'status' => $issues === [] ? 'pass' : 'blocked',
            'stop_the_line' => $issues !== [],
            'issues' => $issues,
            'guard_actions' => $issues === [] ? ['allow_next_step'] : ['pause_publish', 'pause_indexnow'],
            'targets' => $targets,
            'rollback_plan' => [
                'available' => count(array_filter($targets, static fn (array $target): bool => ($target['rollback_available'] ?? false) === true)) === 1,
                'max_automatic_rollback_count' => 1,
                'execute_requested' => $execute,
            ],
            'rollback_result' => $rollbackResult,
            'rollback_executed_count' => (int) ($rollbackResult['executed_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array<string, mixed>>
     */
    private function rollbackTargets(array $evidence): array
    {
        $schema = (string) ($evidence['schema_version'] ?? '');
        $containers = $schema === self::AUTO_PUBLISH_SCHEMA_VERSION
            ? (array) ($evidence['publish_results'] ?? [])
            : [$evidence];
        $targets = [];

        foreach ($containers as $container) {
            if (! is_array($container)) {
                continue;
            }
            foreach ((array) ($container['affected_refs'] ?? []) as $affected) {
                if (! is_array($affected) || ($affected['target_model'] ?? '') !== 'content_page') {
                    continue;
                }
                if (! in_array((string) ($affected['status'] ?? ''), ['published', 'skipped_existing'], true)) {
                    continue;
                }
                $targets[] = $this->targetState($affected, is_array($container['rollback_evidence'] ?? null) ? (array) $container['rollback_evidence'] : []);
            }
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $affected
     * @param  array<string, mixed>  $rollback
     * @return array<string, mixed>
     */
    private function targetState(array $affected, array $rollback): array
    {
        $pageId = $this->contentPageIdFromRef((string) ($affected['subject_ref'] ?? ''));
        $page = $pageId ? ContentPage::query()->withoutGlobalScopes()->find($pageId) : null;
        $candidateRevisionId = (int) ($rollback['candidate_revision_id'] ?? $affected['revision_id'] ?? 0);
        $previousRevisionId = (int) ($rollback['previous_revision_id'] ?? 0);
        $currentStateOk = $page instanceof ContentPage
            && (string) $page->status === ContentPage::STATUS_PUBLISHED
            && (bool) $page->is_public
            && (bool) $page->is_indexable
            && $candidateRevisionId > 0
            && (int) $page->published_revision_id === $candidateRevisionId;

        return [
            'subject_ref' => (string) ($affected['subject_ref'] ?? ''),
            'target_model' => 'content_page',
            'safe_path' => $this->safePath((string) ($affected['safe_path'] ?? '')),
            'current_state_ok' => $currentStateOk,
            'rollback_available' => $page instanceof ContentPage
                && (bool) ($rollback['available'] ?? false)
                && $candidateRevisionId > 0
                && $previousRevisionId > 0,
            'candidate_revision_id' => $candidateRevisionId,
            'previous_revision_id' => $previousRevisionId > 0 ? $previousRevisionId : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function executeRollback(array $target): array
    {
        return DB::transaction(function () use ($target): array {
            $pageId = $this->contentPageIdFromRef((string) ($target['subject_ref'] ?? ''));
            $page = $pageId ? ContentPage::query()->withoutGlobalScopes()->lockForUpdate()->find($pageId) : null;
            if (! $page instanceof ContentPage) {
                return ['attempted' => true, 'executed_count' => 0, 'skipped_count' => 0, 'issues' => ['content_page_not_found']];
            }

            $candidateRevisionId = (int) ($target['candidate_revision_id'] ?? 0);
            $previousRevisionId = (int) ($target['previous_revision_id'] ?? 0);
            if ($candidateRevisionId < 1 || $previousRevisionId < 1 || (int) $page->published_revision_id !== $candidateRevisionId) {
                return ['attempted' => true, 'executed_count' => 0, 'skipped_count' => 0, 'issues' => ['rollback_state_mismatch']];
            }

            $previous = CmsTranslationRevision::query()
                ->where('content_type', 'content_page')
                ->where('content_id', (int) $page->id)
                ->whereKey($previousRevisionId)
                ->first();
            $candidate = CmsTranslationRevision::query()
                ->where('content_type', 'content_page')
                ->where('content_id', (int) $page->id)
                ->whereKey($candidateRevisionId)
                ->first();
            if (! $previous instanceof CmsTranslationRevision || ! $candidate instanceof CmsTranslationRevision) {
                return ['attempted' => true, 'executed_count' => 0, 'skipped_count' => 0, 'issues' => ['rollback_revision_missing']];
            }

            $now = Carbon::now('UTC');
            $page->forceFill([
                'published_revision_id' => (int) $previous->id,
                'working_revision_id' => (int) $previous->id,
                'reviewer' => 'seo_agent_auto_rollback_guard',
                'last_reviewed_at' => $now,
            ])->save();
            $candidate->forceFill([
                'revision_status' => CmsTranslationRevision::STATUS_ARCHIVED,
                'archived_at' => $candidate->archived_at ?? $now,
            ])->save();
            $previous->forceFill([
                'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
                'published_at' => $previous->published_at ?? $now,
            ])->save();

            return [
                'attempted' => true,
                'executed_count' => 1,
                'skipped_count' => 0,
                'rolled_back_ref' => (string) ($target['subject_ref'] ?? ''),
            ];
        });
    }

    private function contentPageIdFromRef(string $subjectRef): ?int
    {
        $parts = explode(':', $subjectRef);
        if (($parts[0] ?? '') !== 'content_page' || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return (int) $parts[1];
    }

    private function safePath(string $path): string
    {
        $parts = parse_url(trim($path));
        if (is_array($parts) && isset($parts['path'])) {
            $path = (string) $parts['path'];
        }

        return preg_replace('#/+#', '/', '/'.ltrim($path, '/')) ?: '/';
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactDir, string $runEvidencePath, string $mode, bool $execute, array $result): array
    {
        $timestamp = Carbon::now('UTC')->format('Ymd\THis\Z');
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-AGENT-AUTO-ROLLBACK-GUARD-01',
            'status' => (string) ($result['status'] ?? 'pass'),
            'mode' => $mode,
            'execute' => $execute,
            'stop_the_line' => (bool) ($result['stop_the_line'] ?? false),
            'run_evidence' => $this->artifactRef($runEvidencePath, 'seo-agent-run-evidence'),
            'result' => $result,
            'allowed_actions' => [
                'read_run_evidence',
                'read_content_page_publish_state',
                'evidence_artifact_write',
                'single_content_page_rollback_when_execute_and_evidence_complete',
            ],
            'forbidden_actions' => $this->forbiddenActions(),
            'negative_guarantees' => $this->negativeGuarantees((int) ($result['rollback_executed_count'] ?? 0) > 0),
        ];

        $path = rtrim($artifactDir, '/').'/seo-agent-auto-rollback-guard-'.$timestamp.'.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded."\n") === false) {
            throw new RuntimeException('rollback_guard_artifact_write_failed');
        }

        return $this->artifactRef($path, self::SCHEMA_VERSION);
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
            'negative_guarantees' => $this->negativeGuarantees(false),
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
        foreach (self::FORBIDDEN_STRINGS as $needle) {
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
            'cms_bulk_publish',
            'article_auto_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'google_indexing_api_call',
            'scheduler_activation',
            'queue_worker_activation',
            'frontend_mutation',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(bool $rollbackExecuted): array
    {
        return [
            'cms_bulk_publish' => false,
            'article_auto_publish' => false,
            'content_page_rollback_beyond_one' => false,
            'search_channel_enqueue' => false,
            'search_channel_submit' => false,
            'google_indexing_api_call' => false,
            'scheduler_activation' => false,
            'queue_worker_activation' => false,
            'frontend_mutation' => false,
        ];
    }
}
