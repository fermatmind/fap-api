<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\QualityInsightsDailyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\SeedsQualityResearchScenario;
use Tests\TestCase;

final class QualityInsightsDailyBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQualityResearchScenario;

    public function test_builder_aggregates_quality_rows_by_day_scale_locale_region_and_version_bundle(): void
    {
        $scenario = $this->seedQualityResearchScenario(501);

        $payload = app(QualityInsightsDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [501],
        );

        $this->assertSame(6, (int) ($payload['source_started_attempts'] ?? 0));
        $this->assertSame(5, (int) ($payload['source_completed_attempts'] ?? 0));
        $this->assertSame(5, (int) ($payload['source_results'] ?? 0));
        $this->assertSame(4, (int) ($payload['attempted_rows'] ?? 0));

        $rows = collect($payload['rows'])->keyBy(fn (array $row): string => implode('|', [
            (string) $row['day'],
            (string) $row['scale_code'],
            (string) $row['locale'],
            (string) $row['region'],
            (string) $row['content_package_version'],
            (string) $row['scoring_spec_version'],
            (string) $row['norm_version'],
        ]));

        $big5DayOne = $this->rowByKey($rows, '2026-01-03|BIG5_OCEAN|en|US|content_big5_2026_v1|big5_spec_beta|big5_norm_2026_active');
        $this->assertSame(3, (int) $big5DayOne['started_attempts']);
        $this->assertSame(2, (int) $big5DayOne['completed_attempts']);
        $this->assertSame(2, (int) $big5DayOne['results_count']);
        $this->assertSame(1, (int) $big5DayOne['valid_results_count']);
        $this->assertSame(1, (int) $big5DayOne['invalid_results_count']);
        $this->assertSame(1, (int) $big5DayOne['quality_a_count']);
        $this->assertSame(1, (int) $big5DayOne['quality_c_count']);
        $this->assertSame(1, (int) $big5DayOne['crisis_alert_count']);
        $this->assertSame(1, (int) $big5DayOne['longstring_count']);
        $this->assertSame(1, (int) $big5DayOne['warnings_count']);

        $eq60Row = $this->rowByKey($rows, '2026-01-03|EQ_60|zh-CN|CN_MAINLAND|content_eq60_2026_v1|eq60_spec_v1|eq60_norm_2026_active');
        $this->assertSame(1, (int) $eq60Row['quality_b_count']);
        $this->assertSame(1, (int) $eq60Row['straightlining_count']);
        $this->assertSame(1, (int) $eq60Row['extreme_count']);
        $this->assertSame(1, (int) $eq60Row['warnings_count']);

        $sdsRow = $this->rowByKey($rows, '2026-01-04|SDS_20|en|US|content_sds_2026_v1|sds_spec_v2|sds_norm_2026_active');
        $this->assertSame(1, (int) $sdsRow['quality_d_count']);
        $this->assertSame(1, (int) $sdsRow['crisis_alert_count']);
        $this->assertSame(1, (int) $sdsRow['inconsistency_count']);

        $big5Fallback = $this->rowByKey($rows, '2026-01-04|BIG5_OCEAN|fr-FR|FR|content_big5_2026_v2|big5_spec_default|big5_norm_2026_active');
        $this->assertSame(1, (int) $big5Fallback['results_count']);
        $this->assertSame(0, (int) $big5Fallback['quality_a_count']);
        $this->assertSame(0, (int) $big5Fallback['quality_b_count']);
        $this->assertSame(0, (int) $big5Fallback['quality_c_count']);
        $this->assertSame(0, (int) $big5Fallback['quality_d_count']);
        $this->assertSame(0, (int) $big5Fallback['valid_results_count']);
        $this->assertSame(1, (int) $big5Fallback['invalid_results_count']);
        $this->assertSame(1, (int) $big5Fallback['warnings_count']);
    }

    /**
     * @param  Collection<string,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function rowByKey(Collection $rows, string $key): array
    {
        $row = $rows->get($key);
        $this->assertIsArray($row, 'Missing aggregate row: '.$key);

        return $row;
    }
}
