<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\TestMetricsDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RefreshTestMetricsDailyCommand extends Command
{
    private const MAX_WRITE_RANGE_DAYS = 31;

    protected $signature = 'analytics:refresh-test-metrics-daily
        {--from= : Inclusive start date (Y-m-d)}
        {--to= : Inclusive end date (Y-m-d)}
        {--scale=* : Limit refresh to one or more scale codes}
        {--org=* : Limit refresh to one or more org ids}
        {--scheduled-current-day : Scheduler-only mode that refreshes today for all orgs and all scales}
        {--dry-run : Preview the aggregation without writing rows}
        {--confirm-write= : Required exact confirmation token for non-dry-run refresh writes}';

    protected $description = 'Refresh the analytics_test_metrics_daily backend-authoritative test metrics read model.';

    public function __construct(
        private readonly TestMetricsDailyBuilder $builder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $scheduledCurrentDay = (bool) $this->option('scheduled-current-day');
        $today = now()->toDateString();
        $from = $scheduledCurrentDay
            ? CarbonImmutable::parse($today)->startOfDay()
            : $this->parseDateOption((string) $this->option('from'), $today);
        $to = $scheduledCurrentDay
            ? CarbonImmutable::parse($today)->startOfDay()
            : $this->parseDateOption((string) $this->option('to'), $today);

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before --to.');

            return self::FAILURE;
        }

        $scaleCodes = $scheduledCurrentDay ? [] : array_values(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $this->option('scale')
        ), static fn (string $value): bool => $value !== ''));

        $orgIds = $scheduledCurrentDay ? [] : array_values(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            (array) $this->option('org')
        ), static fn (int $value): bool => $value >= 0));

        $dryRun = $scheduledCurrentDay ? false : (bool) $this->option('dry-run');
        $beforeCount = $this->countExistingRows($from, $to, $orgIds, $scaleCodes);

        if (! $dryRun) {
            $guard = $this->validateWriteGuard($from, $to, $orgIds, $scaleCodes, $scheduledCurrentDay);

            if ($guard !== null) {
                $this->emitSummary($from, $to, $orgIds, $scaleCodes, $scheduledCurrentDay, [
                    'dry_run' => false,
                    'before_count' => $beforeCount,
                    'after_count' => $beforeCount,
                    'attempted_rows' => 0,
                    'deleted_rows' => 0,
                    'upserted_rows' => 0,
                    'write_guard' => 'blocked',
                ]);
                $this->line('write_guard_reason='.$guard);
                $this->line('expected_confirm_write='.$this->expectedWriteConfirmationToken($from, $to, $orgIds, $scaleCodes));
                $this->error('Non-dry-run test metrics refresh is blocked by the controlled write guard.');

                return self::FAILURE;
            }
        }

        $result = $this->builder->refresh($from, $to, $orgIds, $scaleCodes, $dryRun);
        $afterCount = $this->countExistingRows($from, $to, $orgIds, $scaleCodes);

        $this->emitSummary($from, $to, $orgIds, $scaleCodes, $scheduledCurrentDay, [
            'dry_run' => (bool) ($result['dry_run'] ?? false),
            'before_count' => $beforeCount,
            'after_count' => $afterCount,
            'attempted_rows' => (int) ($result['attempted_rows'] ?? 0),
            'deleted_rows' => (int) ($result['deleted_rows'] ?? 0),
            'upserted_rows' => (int) ($result['upserted_rows'] ?? 0),
            'write_guard' => $dryRun ? 'dry_run_no_write' : 'passed',
        ]);

        if (($result['attempted_rows'] ?? 0) === 0) {
            $this->warn('No test metrics rows were generated for the selected scope.');
        } else {
            $this->info($dryRun ? 'dry-run complete' : 'refresh complete');
        }

        return self::SUCCESS;
    }

    private function parseDateOption(string $value, string $fallback): CarbonImmutable
    {
        $candidate = trim($value) !== '' ? trim($value) : $fallback;

        return CarbonImmutable::createFromFormat('Y-m-d', $candidate)?->startOfDay()
            ?? CarbonImmutable::parse($candidate)->startOfDay();
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     */
    private function countExistingRows(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $orgIds,
        array $scaleCodes,
    ): int {
        $query = DB::table('analytics_test_metrics_daily')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        if ($scaleCodes !== []) {
            $query->where(function ($nested) use ($scaleCodes): void {
                $nested->whereIn(DB::raw('UPPER(scale_code)'), $scaleCodes)
                    ->orWhereIn(DB::raw('UPPER(scale_code_v2)'), $scaleCodes);
            });
        }

        return (int) $query->count();
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     */
    private function validateWriteGuard(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $orgIds,
        array $scaleCodes,
        bool $scheduledCurrentDay,
    ): ?string {
        if ($scheduledCurrentDay) {
            return $this->validateScheduledCurrentDayGuard();
        }

        if (trim((string) $this->option('from')) === '' || trim((string) $this->option('to')) === '') {
            return 'explicit_from_to_required';
        }

        if ($orgIds === []) {
            return 'explicit_org_scope_required';
        }

        if ($from->diffInDays($to) + 1 > self::MAX_WRITE_RANGE_DAYS) {
            return 'date_range_exceeds_'.self::MAX_WRITE_RANGE_DAYS.'_days';
        }

        $expected = $this->expectedWriteConfirmationToken($from, $to, $orgIds, $scaleCodes);
        $provided = trim((string) $this->option('confirm-write'));

        if (! hash_equals($expected, $provided)) {
            return 'confirm_write_token_mismatch';
        }

        return null;
    }

    private function validateScheduledCurrentDayGuard(): ?string
    {
        if (trim((string) $this->option('from')) !== '' || trim((string) $this->option('to')) !== '') {
            return 'scheduled_current_day_disallows_manual_dates';
        }

        if ((array) $this->option('org') !== [] || (array) $this->option('scale') !== []) {
            return 'scheduled_current_day_disallows_manual_scope';
        }

        if ((bool) $this->option('dry-run')) {
            return 'scheduled_current_day_disallows_dry_run';
        }

        if (trim((string) $this->option('confirm-write')) !== '') {
            return 'scheduled_current_day_disallows_confirm_write';
        }

        return null;
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     */
    private function expectedWriteConfirmationToken(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $orgIds,
        array $scaleCodes,
    ): string {
        return implode(':', [
            'analytics_test_metrics_daily',
            'write',
            $from->toDateString(),
            $to->toDateString(),
            'org='.($orgIds === [] ? 'all' : implode(',', $orgIds)),
            'scale='.($scaleCodes === [] ? 'all' : implode(',', $scaleCodes)),
        ]);
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  array{dry_run:bool,before_count:int,after_count:int,attempted_rows:int,deleted_rows:int,upserted_rows:int,write_guard:string}  $summary
     */
    private function emitSummary(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $orgIds,
        array $scaleCodes,
        bool $scheduledCurrentDay,
        array $summary,
    ): void {
        $this->line('from='.$from->toDateString());
        $this->line('to='.$to->toDateString());
        $this->line('org_scope='.($orgIds === [] ? '*' : implode(',', $orgIds)));
        $this->line('scale_scope='.($scaleCodes === [] ? '*' : implode(',', $scaleCodes)));
        $this->line('scheduled_current_day='.($scheduledCurrentDay ? '1' : '0'));
        $this->line('dry_run='.($summary['dry_run'] ? '1' : '0'));
        $this->line('before_count='.$summary['before_count']);
        $this->line('after_count='.$summary['after_count']);
        $this->line('attempted_rows='.$summary['attempted_rows']);
        $this->line('deleted_rows='.$summary['deleted_rows']);
        $this->line('upserted_rows='.$summary['upserted_rows']);
        $this->line('write_guard='.$summary['write_guard']);
    }
}
