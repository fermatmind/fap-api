<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\CareerJobDetailCacheCoverageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class CareerVerifyJobDetailCacheCoverage extends Command
{
    protected $signature = 'career:verify-job-detail-cache-coverage
        {--verify-only : Explicitly select the default read-only verification mode}
        {--repair-missing : Queue only missing or broken published targets}
        {--locales=en,zh-CN : Comma-separated public locales}
        {--minimum-targets=0 : Fail when the dynamic eligible target count is below this rollout floor}
        {--batch-size=250 : Maximum stable target rows inspected per repair invocation}
        {--resume-key=default : Durable repair cursor namespace}
        {--reset : Reset the repair cursor before queueing}
        {--confirm-production-write : Confirm production cache cursor writes and queue dispatch}
        {--json : Emit the stable JSON contract}';

    protected $description = 'Verify published Career detail cache coverage and optionally queue bounded missing/broken repairs.';

    public function __construct(
        private readonly CareerJobDetailCacheCoverageService $coverageService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $repair = (bool) $this->option('repair-missing');
        if ($repair && (bool) $this->option('verify-only')) {
            return $this->failCommand('--verify-only and --repair-missing are mutually exclusive.');
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
