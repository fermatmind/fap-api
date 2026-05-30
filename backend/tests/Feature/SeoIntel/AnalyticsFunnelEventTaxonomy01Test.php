<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Analytics\AnalyticsFunnelDailyBuilder;
use App\Services\Analytics\FunnelEventTaxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class AnalyticsFunnelEventTaxonomy01Test extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_taxonomy_artifacts_define_canonical_events_and_legacy_aliases(): void
    {
        $backendPath = dirname(__DIR__, 3);
        $docPath = $backendPath.'/docs/seo/analytics-funnel-event-taxonomy-01.md';
        $jsonPath = $backendPath.'/docs/seo/generated/analytics-funnel-event-taxonomy-01.v1.json';

        $this->assertFileExists($docPath);
        $this->assertFileExists($jsonPath);

        $payload = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ANALYTICS-FUNNEL-EVENT-TAXONOMY-01', $payload['task'] ?? null);
        $this->assertSame('analytics_funnel_event_taxonomy_completed_ready_for_ops_read_model_repair', $payload['final_decision'] ?? null);
        $this->assertTrue((bool) ($payload['no_ga_admin_change'] ?? false));
        $this->assertTrue((bool) ($payload['no_production_refresh'] ?? false));
        $this->assertContains(FunnelEventTaxonomy::RESULT_VIEW, $payload['canonical_events'] ?? []);
        $this->assertSame(FunnelEventTaxonomy::RESULT_VIEW, data_get($payload, 'legacy_aliases.view_result'));
        $this->assertSame(FunnelEventTaxonomy::PDF_DOWNLOAD, data_get($payload, 'legacy_aliases.report_pdf_view'));
        $this->assertSame(FunnelEventTaxonomy::REPORT_UNLOCK, data_get($payload, 'legacy_aliases.clinical_unlock_success'));
    }

    public function test_daily_builder_accepts_frontend_legacy_aliases_for_canonical_stages(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(123);
        $day = CarbonImmutable::parse($scenario['day'].' 08:00:00');

        $this->insertEvent(123, 'view_result', $scenario['attempt_c'], $day->addHours(4)->addMinutes(15));
        $this->insertEvent(123, 'pdf_download', $scenario['attempt_c'], $day->addHours(4)->addMinutes(20));

        $payload = app(AnalyticsFunnelDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['day']),
            new \DateTimeImmutable($scenario['day']),
            [123],
        );

        $rowsByLocale = collect($payload['rows'])->keyBy('locale');
        $en = $rowsByLocale->get('en');
        $zh = $rowsByLocale->get('zh-CN');

        $this->assertNotNull($en);
        $this->assertNotNull($zh);
        $this->assertSame(2, (int) ($en['first_view_attempts'] ?? 0), 'result_view and legacy view_result must both feed the canonical result_view stage.');
        $this->assertSame(2, (int) ($en['pdf_download_attempts'] ?? 0), 'report_pdf_view and pdf_download must both feed the canonical pdf_download stage.');
        $this->assertSame(1, (int) ($zh['first_view_attempts'] ?? 0), 'Existing result_view canonical events must remain supported.');
        $this->assertSame(1, (int) ($en['unlocked_attempts'] ?? 0), 'report_unlock remains backend fact driven by active benefit grants.');
    }

    public function test_taxonomy_helper_keeps_aliases_backward_compatible(): void
    {
        $this->assertSame(FunnelEventTaxonomy::TEST_START, FunnelEventTaxonomy::canonicalize('start_attempt'));
        $this->assertSame(FunnelEventTaxonomy::TEST_SUBMIT, FunnelEventTaxonomy::canonicalize('submit_attempt'));
        $this->assertSame(FunnelEventTaxonomy::RESULT_VIEW, FunnelEventTaxonomy::canonicalize('view_result'));
        $this->assertSame(FunnelEventTaxonomy::ORDER_CREATED, FunnelEventTaxonomy::canonicalize('create_order'));
        $this->assertSame(FunnelEventTaxonomy::PAYMENT_SUCCESS, FunnelEventTaxonomy::canonicalize('purchase_success'));
        $this->assertSame(FunnelEventTaxonomy::REPORT_UNLOCK, FunnelEventTaxonomy::canonicalize('unlock_success'));
        $this->assertSame(FunnelEventTaxonomy::HISTORICAL_REPORT_REVISIT, FunnelEventTaxonomy::canonicalize('revisit_result'));
        $this->assertTrue(FunnelEventTaxonomy::isCanonical(FunnelEventTaxonomy::CHECKOUT_START));
    }
}
