<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshAnalyticsFunnelDailyCommand extends Command
{
    private const MAX_WRITE_RANGE_DAYS = 31;

    protected $signature = 'analytics:refresh-funnel-daily
        {--from= : Inclusive start date (Y-m-d)}
        {--to= : Inclusive end date (Y-m-d)}
        {--scale=* : Limit refresh to one or more scale codes}
        {--org=* : Limit refresh to one or more org ids}
        {--dry-run : Preview the aggregation without writing rows}
        {--confirm-write= : Required exact confirmation token for non-dry-run refresh writes}';

    protected $description = 'Refresh the analytics_funnel_daily attempt-led commerce funnel read model.';

    public function __construct(
        private readonly AnalyticsFunnelDailyBuilder $builder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->parseDateOption((string) $this->option('from'), now()->subDays(6)->toDateString());
        $to = $this->parseDateOption((string) $this->option('to'), now()->toDateString());

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before --to.');

            return self::FAILURE;
        }

        $scaleCodes = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $this->option('scale')
        ), static fn (string $value): bool => $value !== ''));

        $orgIds = array_values(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            (array) $this->option('org')
        ), static fn (int $value): bool => $value >= 0));

        $dryRun = (bool) $this->option('dry-run');
        $beforeCount = $this->countExistingRows($from, $to, $orgIds, $scaleCodes);

        if (! $dryRun) {
            $guard = $this->validateWriteGuard($from, $to, $orgIds, $scaleCodes);

            if ($guard !== null) {
                $this->line('from='.$from->toDateString());
                $this->line('to='.$to->toDateString());
                $this->line('org_scope='.(empty($orgIds) ? '*' : implode(',', $orgIds)));
                $this->line('scale_scope='.(empty($scaleCodes) ? '*' : implode(',', $scaleCodes)));
                $this->line('dry_run=0');
                $this->line('before_count='.$beforeCount);
                $this->line('after_count='.$beforeCount);
                $this->line('write_guard=blocked');
                $this->line('write_guard_reason='.$guard);
                $this->line('expected_confirm_write='.$this->expectedWriteConfirmationToken($from, $to, $orgIds, $scaleCodes));
                $this->error('Non-dry-run analytics funnel refresh is blocked by the controlled write guard.');

                return self::FAILURE;
            }
        }

        $result = $this->builder->refresh($from, $to, $orgIds, $scaleCodes, $dryRun);
        $afterCount = $this->countExistingRows($from, $to, $orgIds, $scaleCodes);

        $this->line('from='.$result['from']);
        $this->line('to='.$result['to']);
        $this->line('org_scope='.(empty($result['org_scope']) ? '*' : implode(',', $result['org_scope'])));
        $this->line('scale_scope='.(empty($result['scale_scope']) ? '*' : implode(',', $result['scale_scope'])));
        $this->line('dry_run='.(($result['dry_run'] ?? false) ? '1' : '0'));
        $this->line('before_count='.$beforeCount);
        $this->line('after_count='.$afterCount);
        $this->line('attempted_rows='.(string) ($result['attempted_rows'] ?? 0));
        $this->line('deleted_rows='.(string) ($result['deleted_rows'] ?? 0));
        $this->line('upserted_rows='.(string) ($result['upserted_rows'] ?? 0));
        $this->line('write_guard='.($dryRun ? 'dry_run_no_write' : 'passed'));

        if (($result['attempted_rows'] ?? 0) === 0) {
            $this->warn('No funnel rows were generated for the selected scope.');
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
        $query = DB::table('analytics_funnel_daily')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }

        if ($scaleCodes !== []) {
            $query->whereIn('scale_code', $scaleCodes);
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
    ): ?string {
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
            'analytics_funnel_daily',
            'write',
            $from->toDateString(),
            $to->toDateString(),
            'org='.($orgIds === [] ? 'all' : implode(',', $orgIds)),
            'scale='.($scaleCodes === [] ? 'all' : implode(',', $scaleCodes)),
        ]);
    }
}
