<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\QualityInsightsDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class RefreshQualityDailyCommand extends Command
{
    protected $signature = 'analytics:refresh-quality-daily
        {--from= : Inclusive start date (Y-m-d)}
        {--to= : Inclusive end date (Y-m-d)}
        {--scale=* : Limit refresh to one or more scale codes}
        {--locale=* : Limit refresh to one or more locales}
        {--org=* : Limit refresh to one or more org ids}
        {--dry-run : Preview the aggregation without writing rows}';

    protected $description = 'Refresh the scale quality daily aggregation read model.';

    public function __construct(
        private readonly QualityInsightsDailyBuilder $builder,
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

        $scaleCodes = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $this->option('scale')
        ), static fn (string $value): bool => $value !== ''));

        $locales = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->option('locale')
        ), static fn (string $value): bool => $value !== ''));

        $orgIds = array_values(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            (array) $this->option('org')
        ), static fn (int $value): bool => $value > 0));

        $dryRun = (bool) $this->option('dry-run');

        $result = $this->builder->refresh($from, $to, $orgIds, $scaleCodes, $locales, $dryRun);

        $this->line('from='.$result['from']);
        $this->line('to='.$result['to']);
        $this->line('org_scope='.(empty($result['org_scope']) ? '*' : implode(',', $result['org_scope'])));
        $this->line('scale_scope='.(empty($result['scale_scope']) ? '*' : implode(',', $result['scale_scope'])));
        $this->line('locale_scope='.(empty($result['locale_scope']) ? '*' : implode(',', $result['locale_scope'])));
        $this->line('dry_run='.(($result['dry_run'] ?? false) ? '1' : '0'));
        $this->line('source_started_attempts='.(string) ($result['source_started_attempts'] ?? 0));
        $this->line('source_completed_attempts='.(string) ($result['source_completed_attempts'] ?? 0));
        $this->line('source_results='.(string) ($result['source_results'] ?? 0));
        $this->line('attempted_rows='.(string) ($result['attempted_rows'] ?? 0));
        $this->line('deleted_rows='.(string) ($result['deleted_rows'] ?? 0));
        $this->line('upserted_rows='.(string) ($result['upserted_rows'] ?? 0));

        if (($result['attempted_rows'] ?? 0) === 0) {
            $this->warn('No quality aggregation rows were generated for the selected scope.');
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
