<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\MbtiDistributionDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RefreshMbtiDailyCommand extends Command
{
    protected $signature = 'analytics:refresh-mbti-daily
        {--from= : Inclusive start date (Y-m-d)}
        {--to= : Inclusive end date (Y-m-d)}
        {--locale=* : Limit refresh to one or more locales}
        {--org=* : Limit refresh to one or more org ids}
        {--dry-run : Preview the aggregation without writing rows}';

    protected $description = 'Refresh the MBTI type and axis daily aggregation read models.';

    public function __construct(
        private readonly MbtiDistributionDailyBuilder $builder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->parseDateOption((string) $this->option('from'), now()->subDays(13)->toDateString());
        $to = $this->parseDateOption((string) $this->option('to'), now()->toDateString());

        if ($from->greaterThan($to)) {
            $this->error('The --from date must be on or before --to.');

            return self::FAILURE;
        }

        $locales = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->option('locale')
        ), static fn (string $value): bool => $value !== ''));

        $orgIds = array_values(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            (array) $this->option('org')
        ), static fn (int $value): bool => $value > 0));

        $dryRun = (bool) $this->option('dry-run');

        $result = $this->builder->refresh($from, $to, $orgIds, $locales, $dryRun);

        $this->line('from='.$result['from']);
        $this->line('to='.$result['to']);
        $this->line('org_scope='.(empty($result['org_scope']) ? '*' : implode(',', $result['org_scope'])));
        $this->line('locale_scope='.(empty($result['locale_scope']) ? '*' : implode(',', $result['locale_scope'])));
        $this->line('scale_scope='.$result['scale_scope']['scale_code'].'/'.$result['scale_scope']['scale_code_v2']);
        $this->line('dry_run='.(($result['dry_run'] ?? false) ? '1' : '0'));
        $this->line('source_results='.(string) ($result['source_results'] ?? 0));
        $this->line('source_results_with_at='.(string) ($result['source_results_with_at'] ?? 0));
        $this->line('attempted_type_rows='.(string) ($result['attempted_type_rows'] ?? 0));
        $this->line('attempted_axis_rows='.(string) ($result['attempted_axis_rows'] ?? 0));
        $this->line('deleted_type_rows='.(string) ($result['deleted_type_rows'] ?? 0));
        $this->line('deleted_axis_rows='.(string) ($result['deleted_axis_rows'] ?? 0));
        $this->line('upserted_type_rows='.(string) ($result['upserted_type_rows'] ?? 0));
        $this->line('upserted_axis_rows='.(string) ($result['upserted_axis_rows'] ?? 0));
        $this->line('at_authority_complete='.(($result['at_authority_complete'] ?? false) ? '1' : '0'));

        if ((($result['attempted_type_rows'] ?? 0) === 0) && (($result['attempted_axis_rows'] ?? 0) === 0)) {
            $this->warn('No MBTI authority rows were generated for the selected scope.');
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
}
