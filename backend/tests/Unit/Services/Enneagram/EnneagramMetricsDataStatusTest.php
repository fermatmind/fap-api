<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram;

use App\Services\Enneagram\EnneagramAnalyticsMetricCatalog;
use Tests\TestCase;

final class EnneagramMetricsDataStatusTest extends TestCase
{
    public function test_pending_and_collecting_metrics_do_not_expose_fake_numeric_values(): void
    {
        $catalog = app(EnneagramAnalyticsMetricCatalog::class);

        foreach ($catalog->definitions() as $definition) {
            $status = (string) ($definition['data_status'] ?? 'unavailable');
            if (! in_array($status, ['collecting', 'pending_sample', 'unavailable'], true)) {
                continue;
            }

            $this->assertArrayNotHasKey('value', $definition);
            $this->assertArrayNotHasKey('sample_size', $definition);
        }
    }

    public function test_operational_and_pending_status_summary_is_stable(): void
    {
        $catalog = app(EnneagramAnalyticsMetricCatalog::class);
        $summary = $catalog->dataStatusSummary();

        $this->assertContains('close_call_rate', $summary['operational']);
        $this->assertContains('observation_completion_rate', $summary['operational']);
        $this->assertContains('retake_consistency_index', $summary['pending_sample']);
        $this->assertContains('e105_fc144_agreement', $summary['collecting']);
    }
}
