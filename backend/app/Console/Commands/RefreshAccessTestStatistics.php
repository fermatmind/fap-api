<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AccessTestDailyBuilder;
use App\Services\Analytics\AccessTestIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RefreshAccessTestStatistics extends Command
{
    protected $signature = 'analytics:refresh-access-test-statistics
        {--from= : Inclusive reporting date in Asia/Shanghai}
        {--to= : Inclusive reporting date in Asia/Shanghai}
        {--org=* : Optional organization ids}
        {--scheduled-recent : Refresh yesterday and today without a write token}
        {--scheduled-history : Reconcile the rolling retention window without a write token}
        {--confirm-write= : Exact token required for manual writes}';

    protected $description = 'Refresh privacy-safe access and test statistics daily aggregates.';

    public function __construct(private readonly AccessTestDailyBuilder $builder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $recent = (bool) $this->option('scheduled-recent');
        $history = (bool) $this->option('scheduled-history');
        if ($recent && $history) {
            $this->error('Only one scheduled mode may be selected.');

            return self::FAILURE;
        }

        $today = CarbonImmutable::now(AccessTestIdentity::TIMEZONE)->startOfDay();
        if ($recent) {
            $from = $today->subDay();
            $to = $today;
            $orgIds = [];
        } elseif ($history) {
            $retentionDays = (int) config('analytics.access_test_statistics.retention_days', 90);
            $from = $today->subDays($retentionDays - 1);
            $to = $today->subDay();
            $orgIds = [];
        } else {
            $from = $this->dateOption('from', $today);
            $to = $this->dateOption('to', $today);
            $orgIds = array_values(array_unique(array_map('intval', (array) $this->option('org'))));
            $expected = sprintf(
                'analytics_access_test_daily:write:%s:%s:org=%s',
                $from->toDateString(),
                $to->toDateString(),
                $orgIds === [] ? 'all' : implode(',', $orgIds)
            );
            if (! hash_equals($expected, trim((string) $this->option('confirm-write')))) {
                $this->line('expected_confirm_write='.$expected);
                $this->error('Manual refresh requires the exact confirmation token.');

                return self::FAILURE;
            }
        }

        if ($from->gt($to)) {
            $this->error('The from date must not be after the to date.');

            return self::FAILURE;
        }

        $result = $this->builder->refresh($from, $to, $orgIds);
        $this->line('from='.$result['from']);
        $this->line('to='.$result['to']);
        $this->line('org_scope='.implode(',', $result['org_scope']));
        $this->line('rows='.$result['rows']);

        if ($history) {
            $this->pruneExpiredDigests($today);
        }

        $this->info('access/test statistics refresh complete');

        return self::SUCCESS;
    }

    private function dateOption(string $name, CarbonImmutable $fallback): CarbonImmutable
    {
        $value = trim((string) $this->option($name));

        return CarbonImmutable::parse($value !== '' ? $value : $fallback, AccessTestIdentity::TIMEZONE)->startOfDay();
    }

    private function pruneExpiredDigests(CarbonImmutable $today): void
    {
        $days = (int) config('analytics.access_test_statistics.retention_days', 90);
        $cutoff = $today->subDays($days)->utc();

        DB::table('events')
            ->where('occurred_at', '<', $cutoff)
            ->whereNotNull('analytics_ip_hash')
            ->update(['analytics_ip_hash' => null, 'analytics_ip_status' => 'expired']);

        DB::table('attempts')
            ->where('started_at', '<', $cutoff)
            ->whereNotNull('analytics_start_ip_hash')
            ->update(['analytics_start_ip_hash' => null, 'analytics_start_ip_status' => 'expired']);

        DB::table('attempts')
            ->where('submitted_at', '<', $cutoff)
            ->whereNotNull('analytics_submit_ip_hash')
            ->update(['analytics_submit_ip_hash' => null, 'analytics_submit_ip_status' => 'expired']);
    }
}
