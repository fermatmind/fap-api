<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\GscReadModelControlledImportCanary;
use Illuminate\Console\Command;
use Throwable;

final class SeoIntelGscReadModelImportCanaryCommand extends Command
{
    protected $signature = 'seo-intel:gsc-readmodel-import-canary
        {--artifact= : Path to a sanitized GSC sidecar live-read artifact}
        {--limit=1 : Canary row limit; bounded 1..10}
        {--backfill : Use the bounded resumable backfill mode}
        {--cohort=query-page : Backfill cohort: page, query, or query-page}
        {--batch-size=100 : Backfill rows per batch; bounded 1..1000}
        {--hard-max-rows=10000 : Backfill hard total cap; bounded 1..10000}
        {--resume-key= : Stable operator-selected backfill resume key}
        {--cursor= : Opaque cursor emitted by the previous backfill batch}
        {--reset : Restart backfill at offset zero; cannot be combined with --cursor}
        {--execute : Execute the bounded canary write}
        {--confirm-artifact-sha256= : Required for --execute; must match artifact SHA256}
        {--confirm-write= : Required exact confirmation phrase for --execute}
        {--confirm-production-write : Additionally required for production backfill execution}
        {--json : Emit JSON summary}';

    protected $description = 'Plan or execute a controlled GSC read-model canary or bounded resumable backfill.';

    public function handle(GscReadModelControlledImportCanary $canary): int
    {
        $path = $this->artifactPath();
        if ($path === null) {
            return $this->finish($this->failureSummary('artifact_path_required'));
        }

        try {
            $raw = file_get_contents($path);
            if (! is_string($raw)) {
                return $this->finish($this->failureSummary('artifact_unreadable'));
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->finish($this->failureSummary('artifact_json_invalid'));
        }

        if (! is_array($decoded)) {
            return $this->finish($this->failureSummary('artifact_must_be_object'));
        }

        $sha256 = hash('sha256', $raw);
        if ((bool) $this->option('backfill')) {
            return $this->handleBackfill($canary, $decoded, $sha256);
        }

        $limit = $this->limit();
        if ($limit === null) {
            return $this->finish([
                ...$this->failureSummary('limit_must_be_between_1_and_10'),
                'artifact_sha256' => $sha256,
                'required_confirmation_phrase' => $canary->confirmationPhrase($sha256, 1),
            ]);
        }

        $summary = (bool) $this->option('execute')
            ? $canary->execute(
                $decoded,
                $sha256,
                $limit,
                $this->nullableString($this->option('confirm-artifact-sha256')),
                $this->nullableString($this->option('confirm-write')),
            )
            : $canary->plan($decoded, $sha256, $limit);

        return $this->finish($summary);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function handleBackfill(
        GscReadModelControlledImportCanary $canary,
        array $artifact,
        string $artifactSha256,
    ): int {
        $batchSize = $this->integerOption('batch-size');
        $hardMaxRows = $this->integerOption('hard-max-rows');
        if ($batchSize === null || $hardMaxRows === null) {
            return $this->finish($this->failureSummary('numeric_option_invalid'));
        }

        $cohort = trim((string) $this->option('cohort'));
        $resumeKey = trim((string) $this->option('resume-key'));
        $cursor = $this->nullableString($this->option('cursor'));
        $summary = (bool) $this->option('execute')
            ? $canary->executeBackfill(
                $artifact,
                $artifactSha256,
                $cohort,
                $batchSize,
                $hardMaxRows,
                $resumeKey,
                $cursor,
                (bool) $this->option('reset'),
                $this->nullableString($this->option('confirm-artifact-sha256')),
                $this->nullableString($this->option('confirm-write')),
                app()->environment('production'),
                (bool) $this->option('confirm-production-write'),
            )
            : $canary->planBackfill(
                $artifact,
                $artifactSha256,
                $cohort,
                $batchSize,
                $hardMaxRows,
                $resumeKey,
                $cursor,
                (bool) $this->option('reset'),
            );

        return $this->finish($summary);
    }

    private function artifactPath(): ?string
    {
        $path = trim((string) $this->option('artifact'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) ? $path : null;
    }

    private function limit(): ?int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $limit = (int) $raw;

        return $limit >= 1 && $limit <= 10 ? $limit : null;
    }

    private function integerOption(string $name): ?int
    {
        $raw = trim((string) $this->option($name));

        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue): array
    {
        $backfill = (bool) $this->option('backfill');

        return [
            'schema_version' => $backfill
                ? GscReadModelControlledImportCanary::BACKFILL_SCHEMA_VERSION
                : GscReadModelControlledImportCanary::SCHEMA_VERSION,
            'task' => $backfill
                ? GscReadModelControlledImportCanary::BACKFILL_TASK
                : GscReadModelControlledImportCanary::TASK,
            'status' => 'blocked',
            'mode' => $backfill ? 'backfill_preflight_blocked' : 'canary_preflight_blocked',
            'ok' => false,
            'dry_run' => ! (bool) $this->option('execute'),
            'execute' => (bool) $this->option('execute'),
            'would_write' => false,
            'writes_attempted' => false,
            'writes_committed' => false,
            'target_table' => GscReadModelControlledImportCanary::TARGET_TABLE,
            'rows_previewed' => 0,
            'rows_would_insert' => 0,
            'rows_inserted' => 0,
            'rows_skipped_existing' => 0,
            'issues' => [$issue],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'blocked'));
            $this->line('mode='.(string) ($summary['mode'] ?? 'unknown'));
            $this->line('dry_run='.((bool) ($summary['dry_run'] ?? true) ? 'true' : 'false'));
            $this->line('writes_committed='.((bool) ($summary['writes_committed'] ?? false) ? 'true' : 'false'));
            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($summary['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
