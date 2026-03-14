<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\QualityResearchInsightsSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsQualityResearchScenario;
use Tests\TestCase;

final class QualityResearchInsightsSupportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQualityResearchScenario;

    public function test_support_selects_latest_psychometrics_active_norm_coverage_and_rollout_reference_rows(): void
    {
        $scenario = $this->seedQualityResearchScenario(611);

        $qualityRefresh = $this->app->make(\App\Services\Analytics\QualityInsightsDailyBuilder::class)->refresh(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [611],
        );
        $this->assertSame(4, (int) ($qualityRefresh['upserted_rows'] ?? 0));

        $support = app(QualityResearchInsightsSupport::class);

        $psychometrics = $support->psychometricsPayload([
            'from' => $scenario['from'],
            'to' => $scenario['to'],
            'scale_code' => 'all',
            'locale' => 'all',
            'region' => 'all',
            'content_package_version' => 'all',
            'scoring_spec_version' => 'all',
            'norm_version' => 'all',
        ]);
        $norms = $support->normsPayload(611, [
            'from' => $scenario['from'],
            'to' => $scenario['to'],
            'scale_code' => 'all',
            'locale' => 'all',
            'region' => 'all',
            'content_package_version' => 'all',
            'scoring_spec_version' => 'all',
            'norm_version' => 'all',
        ]);

        $this->assertTrue($psychometrics['has_data']);
        $this->assertCount(3, $psychometrics['rows']);
        $this->assertSame('big5_norm_2026_active', $psychometrics['rows'][0]['norm_version']);
        $this->assertSame(160, (int) $psychometrics['rows'][0]['sample_n']);
        $this->assertSame('Internal reference - below display threshold', $psychometrics['rows'][2]['reference_state']);

        $this->assertNotEmpty($norms['coverage_rows']);
        $this->assertSame('BIG5_OCEAN', $norms['coverage_rows'][0]['scale_code']);
        $this->assertSame('big5_norm_2026_active', $norms['coverage_rows'][0]['active_norm_version']);
        $this->assertNotEmpty($norms['rollout_rows']);
        $this->assertSame('ml_beta', $norms['rollout_rows'][0]['model_key']);
        $this->assertNotEmpty($norms['drift_rows']);
        $this->assertSame('big5_norm_2026_active', $norms['drift_rows'][0]['active_norm_version']);
    }
}
