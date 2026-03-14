<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MbtiDistributionDailyBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMbtiInsightsScenario;
use Tests\TestCase;

final class AnalyticsAxisDailyBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMbtiInsightsScenario;

    public function test_axis_builder_counts_side_distribution_and_keeps_at_out_when_a_row_lacks_at_resolution(): void
    {
        $scenario = $this->seedMbtiInsightsAuthorityScenario(601);

        $attemptGap = $this->insertAnalyticsAttempt([
            'org_id' => 601,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => CarbonImmutable::parse('2026-01-04 14:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-04 14:05:00'),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $attemptGap,
            'org_id' => 601,
            'scale_code' => 'MBTI',
            'type_code' => 'ISTJ',
            'scores_pct' => ['EI' => 45, 'SN' => 55, 'TF' => 55, 'JP' => 55],
            'computed_at' => CarbonImmutable::parse('2026-01-04 14:06:00'),
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        $payload = app(MbtiDistributionDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [601],
        );

        $this->assertSame(6, (int) ($payload['source_results'] ?? 0));
        $this->assertSame(5, (int) ($payload['source_results_with_at'] ?? 0));
        $this->assertFalse((bool) ($payload['at_authority_complete'] ?? true));

        $axisTotals = collect($payload['axis_rows'])
            ->groupBy('axis_code')
            ->map(function ($group): array {
                return $group
                    ->groupBy('side_code')
                    ->map(static fn ($rows): int => (int) collect($rows)->sum('results_count'))
                    ->all();
            });

        $this->assertEquals(['E' => 2, 'I' => 4], $axisTotals->get('EI'));
        $this->assertEquals(['N' => 4, 'S' => 2], $axisTotals->get('SN'));
        $this->assertEquals(['T' => 4, 'F' => 2], $axisTotals->get('TF'));
        $this->assertEquals(['J' => 4, 'P' => 2], $axisTotals->get('JP'));
        $this->assertEquals(['A' => 3, 'T' => 2], $axisTotals->get('AT'));
    }
}
