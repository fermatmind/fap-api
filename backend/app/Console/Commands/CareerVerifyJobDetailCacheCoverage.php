<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\CareerJobDetailCacheCoverageService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CareerVerifyJobDetailCacheCoverage extends Command
{
    protected $signature = 'career:verify-job-detail-cache-coverage
        {--verify-only : Explicitly select the default read-only verification mode}
        {--repair-missing : Queue only missing or broken published targets}
        {--repair-missing-sync : Synchronize only missing or broken targets in a non-production deploy gate}
        {--locales=en,zh-CN : Comma-separated public locales}
        {--minimum-targets=0 : Fail when the dynamic eligible target count is below this rollout floor}
        {--batch-size=250 : Maximum stable target rows inspected per repair invocation}
        {--maximum-sync-repairs=250 : Refuse synchronous repair before writes when more targets are missing or broken}
        {--resume-key=default : Durable repair cursor namespace}
        {--reset : Reset the repair cursor before queueing}
        {--confirm-production-write : Confirm production cache cursor writes and queue dispatch}
        {--json : Emit the stable JSON contract}';

    protected $description = 'Verify published Career detail cache coverage and optionally queue bounded missing/broken repairs.';

    public function __construct(
        private readonly CareerJobDetailCacheCoverageService $coverageService,
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $queuedRepair = (bool) $this->option('repair-missing');
        $syncRepair = (bool) $this->option('repair-missing-sync');
        $repair = $queuedRepair || $syncRepair;
        if ($queuedRepair && $syncRepair) {
            return $this->failCommand('--repair-missing and --repair-missing-sync are mutually exclusive.');
        }
        if ($repair && (bool) $this->option('verify-only')) {
            return $this->failCommand('--verify-only cannot be combined with a repair mode.');
        }
        if (! $repair && (bool) $this->option('reset')) {
            return $this->failCommand('--reset is available only with --repair-missing.');
        }

        $locales = $this->locales((string) $this->option('locales'));
        if ($locales === []) {
            return $this->failCommand('--locales must contain only en or zh-CN and include at least one locale.');
        }

        $minimumTargetsRaw = trim((string) $this->option('minimum-targets'));
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $minimumTargetsRaw) !== 1) {
            return $this->failCommand('--minimum-targets must be a non-negative base-10 integer.');
        }
        $minimumTargets = (int) $minimumTargetsRaw;
        if ($minimumTargets > 100000) {
            return $this->failCommand('--minimum-targets must not exceed 100000.');
        }

        $inspection = $this->coverageService->inspect($locales);
        $report = $inspection['report'];
        $report['minimum_target_count'] = $minimumTargets;
        $report['minimum_target_count_met'] = (int) $report['eligible_target_count'] >= $minimumTargets;
        if (! $report['minimum_target_count_met']) {
            $report['status'] = 'incomplete';
        }
        if (! $repair) {
            $this->emit($report);

            return $report['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
        }

        if (! $report['minimum_target_count_met']) {
            $this->emit($report);

            return self::FAILURE;
        }

        if ($syncRepair) {
            return $this->repairSynchronously($inspection, $locales);
        }

        if (app()->environment('production') && ! (bool) $this->option('confirm-production-write')) {
            return $this->failCommand('Production repair requires --confirm-production-write; verification remains read-only by default.');
        }
        if (config('queue.default') === 'sync') {
            return $this->failCommand('Repair requires an asynchronous queue connection; queue.default=sync is not allowed.');
        }

        $resumeKey = $this->resumeCacheKey((string) $this->option('resume-key'));
        if ((bool) $this->option('reset')) {
            Cache::forget($resumeKey);
        }

        $rows = $inspection['rows'];
        $storedCursor = max(0, (int) Cache::get($resumeKey, 0));
        $cursorWrapped = $rows !== [] && $storedCursor >= count($rows);
        $cursorBefore = $cursorWrapped ? 0 : min(count($rows), $storedCursor);
        $batchSize = min(1000, max(1, (int) $this->option('batch-size')));
        $batch = array_slice($rows, $cursorBefore, $batchSize);
        $queued = 0;
        foreach ($batch as $row) {
            if (! $row['repairable']) {
                continue;
            }

            WarmCareerJobDetailProjection::dispatch($row['slug'], $row['locale']);
            $queued++;
        }

        $cursorAfter = $cursorBefore + count($batch);
        Cache::forever($resumeKey, $cursorAfter);
        $coverageStatus = $report['status'];
        $report['coverage_status'] = $coverageStatus;
        $report['status'] = $cursorAfter >= count($rows) ? 'repair_queued_complete' : 'repair_queued_partial';
        $report['repair'] = [
            'resume_key' => $resumeKey,
            'batch_size' => $batchSize,
            'cursor_wrapped' => $cursorWrapped,
            'cursor_before' => $cursorBefore,
            'cursor_after' => $cursorAfter,
            'remaining_targets' => count($rows) - $cursorAfter,
            'inspected_targets' => count($batch),
            'queued_jobs' => $queued,
        ];
        $this->emit($report);

        return self::SUCCESS;
    }

    /**
     * @param  array{report: array<string, mixed>, rows: list<array{slug: string, locale: string, classification: string, repairable: bool}>}  $inspection
     * @param  list<string>  $locales
     */
    private function repairSynchronously(array $inspection, array $locales): int
    {
        if (app()->environment('production')) {
            return $this->failCommand('Synchronous cache coverage repair is forbidden in production.');
        }

        $maximumRaw = trim((string) $this->option('maximum-sync-repairs'));
        if (preg_match('/^[1-9][0-9]*$/D', $maximumRaw) !== 1 || (int) $maximumRaw > 1000) {
            return $this->failCommand('--maximum-sync-repairs must be an integer between 1 and 1000.');
        }

        $targets = array_values(array_filter(
            $inspection['rows'],
            static fn (array $row): bool => $row['repairable'],
        ));
        $maximum = (int) $maximumRaw;
        if (count($targets) > $maximum) {
            $report = $inspection['report'];
            $report['status'] = 'sync_repair_refused_over_limit';
            $report['repair'] = [
                'mode' => 'sync',
                'write_executed' => false,
                'repairable_target_count' => count($targets),
                'maximum_sync_repairs' => $maximum,
            ];
            $this->emit($report);

            return self::FAILURE;
        }

        $entries = [];
        foreach ($targets as $target) {
            $entries[] = $this->responseCache->warmJobDetailPayload(
                $target['slug'],
                $target['locale'],
                false,
            );
        }

        $postRepair = $this->coverageService->inspect($locales)['report'];
        $postRepair['minimum_target_count'] = (int) ($inspection['report']['minimum_target_count'] ?? 0);
        $postRepair['minimum_target_count_met'] = (bool) ($inspection['report']['minimum_target_count_met'] ?? false);
        $postRepair['coverage_status'] = $postRepair['status'];
        $postRepair['status'] = $postRepair['coverage_status'] === 'ready'
            ? 'sync_repair_completed'
            : 'sync_repair_incomplete';
        $postRepair['repair'] = [
            'mode' => 'sync',
            'write_executed' => $targets !== [],
            'repairable_target_count' => count($targets),
            'maximum_sync_repairs' => $maximum,
            'cached_target_count' => count(array_filter(
                $entries,
                static fn (array $entry): bool => ($entry['status'] ?? null) === 'cached',
            )),
            'failed_target_count' => count(array_filter(
                $entries,
                static fn (array $entry): bool => ($entry['status'] ?? null) !== 'cached',
            )),
        ];
        $this->emit($postRepair);

        return $postRepair['coverage_status'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function emit(array $report): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }

        $this->line(sprintf(
            'status=%s published_slugs=%d targets=%d ready_active=%d ready_lkg=%d legacy=%d missing=%d broken=%d excluded=%d coverage_ratio=%.6f',
            (string) $report['status'],
            (int) $report['published_slug_count'],
            (int) $report['expected_target_count'],
            (int) $report['ready_active_count'],
            (int) $report['ready_lkg_count'],
            (int) $report['legacy_migratable_count'],
            (int) $report['missing_count'],
            (int) $report['broken_count'],
            (int) $report['excluded_count'],
            (float) $report['coverage_ratio'],
        ));
    }

    /** @return list<string> */
    private function locales(string $value): array
    {
        $requested = array_values(array_unique(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', $value),
        ))));
        if ($requested === [] || array_diff($requested, ['en', 'zh-CN']) !== []) {
            return [];
        }

        return array_values(array_intersect(['en', 'zh-CN'], $requested));
    }

    private function resumeCacheKey(string $value): string
    {
        $namespace = trim((string) preg_replace('/[^a-z0-9._-]+/i', '-', trim($value)), '-');

        return 'career:detail-cache-coverage-repair:v1:'.($namespace !== '' ? $namespace : 'default');
    }
}
