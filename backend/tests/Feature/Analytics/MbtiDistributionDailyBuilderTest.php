<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\MbtiDistributionDailyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsMbtiInsightsScenario;
use Tests\TestCase;

final class MbtiDistributionDailyBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMbtiInsightsScenario;

    public function test_builder_aggregates_mbti_only_authority_rows_and_preserves_attempt_context_dimensions(): void
    {
        $scenario = $this->seedMbtiInsightsAuthorityScenario(501);

        $payload = app(MbtiDistributionDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [501],
        );

        $this->assertSame('MBTI', (string) ($payload['scale_scope']['scale_code'] ?? ''));
        $this->assertSame(5, (int) ($payload['source_results'] ?? 0), 'invalid, orphan, fallback-only, and non-MBTI rows must stay out');
        $this->assertSame(5, (int) collect($payload['type_rows'])->sum('results_count'));
        $this->assertSame(5, (int) collect($payload['type_rows'])->sum('distinct_attempts_with_results'));

        $rowsByKey = collect($payload['type_rows'])->keyBy(static fn (array $row): string => implode('|', [
            (string) $row['day'],
            (string) $row['locale'],
            (string) $row['content_package_version'],
            (string) $row['scoring_spec_version'],
            (string) $row['norm_version'],
            (string) $row['type_code'],
        ]));

        $this->assertArrayHasKey('2026-01-03|en|content_2026_01|scoring_2026_01|norm_2026_01|INTJ', $rowsByKey->all());
        $this->assertArrayHasKey('2026-01-03|en|content_2026_01|scoring_2026_01|norm_2026_01|ENFP', $rowsByKey->all());
        $this->assertArrayHasKey('2026-01-03|zh-CN|content_2026_01|scoring_2026_02|norm_2026_02|INFJ', $rowsByKey->all());
        $this->assertArrayHasKey('2026-01-04|en|content_2026_02|scoring_2026_02|norm_2026_02|INTJ', $rowsByKey->all());
        $this->assertArrayHasKey('2026-01-04|fr-FR|content_2026_02|scoring_2026_02|norm_2026_02|ESTP', $rowsByKey->all());

        $this->assertSame(2, (int) collect($payload['type_rows'])->where('type_code', 'INTJ')->sum('results_count'));
        $this->assertSame(1, (int) collect($payload['type_rows'])->where('locale', 'zh-CN')->sum('results_count'));
        $this->assertSame(2, (int) collect($payload['type_rows'])->where('content_package_version', 'content_2026_02')->sum('results_count'));
    }
}
