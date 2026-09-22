<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\GscDataQualityGate;
use App\Services\SeoIntel\SeoIntelCollectorManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscDataQualityGateTest extends TestCase
{
    #[Test]
    public function gsc_foundation_fixture_data_is_blocked_from_opportunity_queue(): void
    {
        $result = (new SeoIntelCollectorManager)->collect('gsc_foundation', ['dry_run' => true]);
        $gate = $result->metadata['data_quality_gate'] ?? null;

        $this->assertIsArray($gate);
        $this->assertSame('blocked', $gate['status'] ?? null);
        $this->assertFalse((bool) ($gate['opportunity_queue_eligible'] ?? true));
        $this->assertContains('fixture_or_mock_source', $gate['reasons'] ?? []);
        $this->assertContains('untrusted_data_origin', $gate['reasons'] ?? []);
        $this->assertSame('fixture', $result->metadata['data_origin'] ?? null);
        $this->assertFalse((bool) ($result->metadata['opportunity_queue_eligible'] ?? true));
        $this->assertFalse($result->externalCallsAttempted);
        $this->assertFalse($result->writesAttempted);
    }

    #[Test]
    public function gate_rejects_stale_or_too_fresh_live_gsc_rows(): void
    {
        $gate = new GscDataQualityGate;
        $now = CarbonImmutable::parse('2026-06-20 12:00:00');

        $stale = $gate->evaluate([
            $this->liveRow('2026-06-01'),
        ], $now);
        $tooFresh = $gate->evaluate([
            $this->liveRow('2026-06-19'),
        ], $now);

        $this->assertSame('blocked', $stale['status']);
        $this->assertContains('stale_gsc_report_date', $stale['reasons']);
        $this->assertFalse((bool) $stale['opportunity_queue_eligible']);

        $this->assertSame('blocked', $tooFresh['status']);
        $this->assertContains('gsc_finalization_lag_not_met', $tooFresh['reasons']);
        $this->assertFalse((bool) $tooFresh['opportunity_queue_eligible']);
    }

    #[Test]
    public function gate_passes_fresh_final_live_gsc_rows_with_required_metrics(): void
    {
        $gate = new GscDataQualityGate;
        $result = $gate->evaluate([
            $this->liveRow('2026-06-17'),
        ], CarbonImmutable::parse('2026-06-20 12:00:00'));

        $this->assertSame('pass', $result['status']);
        $this->assertSame([], $result['reasons']);
        $this->assertTrue((bool) $result['opportunity_queue_eligible']);
        $this->assertSame(['live_gsc_api'], $result['data_origins']);
        $this->assertSame('2026-06-17', $result['freshness']['max_report_date'] ?? null);
    }

    #[Test]
    public function dry_run_command_exposes_quality_gate_without_live_calls_or_writes(): void
    {
        $exitCode = Artisan::call('seo-intel:collect', [
            '--collector' => 'gsc_foundation',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('blocked', data_get($decoded, 'metadata.data_quality_gate.status'));
        $this->assertFalse((bool) data_get($decoded, 'metadata.data_quality_gate.opportunity_queue_eligible', true));
        $this->assertFalse((bool) data_get($decoded, 'metadata.opportunity_queue_eligible', true));
        $this->assertFalse((bool) ($decoded['external_calls_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_attempted'] ?? true));
        $this->assertFalse((bool) ($decoded['writes_committed'] ?? true));
    }

    #[Test]
    public function single_pass_input_preserves_reason_order_and_date_extremes(): void
    {
        config(['seo_intel.gsc_data_quality.min_rows' => 5]);
        $rows = [
            [...$this->liveRow('2026-06-16'), 'metadata_json' => ['data_origin' => 'fixture']],
            [...$this->liveRow('invalid-date'), 'source_engine' => 'bing', 'query_hash' => null],
            $this->liveRow('2026-06-17'),
            $this->liveRow('2026-06-01'),
        ];
        $visited = 0;
        $input = (function () use ($rows, &$visited): \Generator {
            foreach ($rows as $row) {
                $visited++;
                yield $row;
            }
        })();
        $actual = (new GscDataQualityGate)->evaluate($input, CarbonImmutable::parse('2026-06-20'));

        $this->assertSame(4, $visited);
        $this->assertSame(4, $actual['rows_checked']);
        $this->assertSame([
            'insufficient_rows', 'fixture_or_mock_source', 'untrusted_data_origin',
            'missing_report_date', 'non_google_source_engine', 'missing_required_metric_fields',
        ], $actual['reasons']);
        $this->assertSame('2026-06-01', $actual['freshness']['min_report_date']);
        $this->assertSame('2026-06-17', $actual['freshness']['max_report_date']);
        $this->assertSame(['fixture', 'live_gsc_api'], $actual['data_origins']);
    }

    #[Test]
    public function large_stream_does_not_retain_row_dates_or_repeated_failures(): void
    {
        $row = [...$this->liveRow('2026-06-17'), 'metadata_json' => ['data_origin' => 'fixture']];
        $baseline = memory_get_usage();
        $max = $baseline;
        $rows = (function () use ($row, &$max): \Generator {
            for ($i = 0; $i < 100000; $i++) {
                $max = max($max, memory_get_usage());
                yield $row;
            }
        })();
        $result = (new GscDataQualityGate)->evaluate($rows, CarbonImmutable::parse('2026-06-20'));

        $this->assertSame(100000, $result['rows_checked']);
        $this->assertSame(['fixture_or_mock_source', 'untrusted_data_origin'], $result['reasons']);
        $this->assertLessThan(8 * 1024 * 1024, $max - $baseline);
    }

    /**
     * @return array<string, mixed>
     */
    private function liveRow(string $reportDate): array
    {
        return [
            'report_date' => $reportDate,
            'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types'),
            'query_hash' => hash('sha256', '人格测试'),
            'source_engine' => 'google',
            'clicks' => 2,
            'impressions' => 80,
            'metadata_json' => [
                'data_origin' => 'live_gsc_api',
                'row_source' => 'live_gsc_api',
                'purchase_attribution_allowed' => false,
            ],
        ];
    }
}
