<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram;

use App\Services\Enneagram\EnneagramAnalyticsMetricCatalog;
use Tests\TestCase;

final class EnneagramAnalyticsMetricCatalogTest extends TestCase
{
    public function test_metric_catalog_includes_required_metrics_with_data_status(): void
    {
        $catalog = app(EnneagramAnalyticsMetricCatalog::class);
        $metrics = collect($catalog->definitions())->keyBy('metric_key');

        foreach ([
            'top_gap_distribution',
            'close_call_rate',
            'diffuse_rate',
            'low_quality_rate',
            'pair_frequency',
            'top1_resonance_rate',
            'top2_resonance_rate',
            'retake_consistency_index',
            'e105_fc144_agreement',
            'close_call_conversion',
            'misidentification_matrix',
            'observation_completion_rate',
            'day7_return_rate',
            'form_switch_rate',
            'low_quality_close_call_relation',
        ] as $metricKey) {
            $this->assertTrue($metrics->has($metricKey), 'missing metric '.$metricKey);
            $this->assertContains(
                data_get($metrics->get($metricKey), 'data_status'),
                ['operational', 'collecting', 'pending_sample', 'unavailable']
            );
        }
    }

    public function test_public_metric_catalog_maps_statuses_for_technical_note_consumers(): void
    {
        $catalog = app(EnneagramAnalyticsMetricCatalog::class);
        $metrics = collect($catalog->publicDefinitions())->keyBy('metric_key');

        $this->assertSame('currently_operational', data_get($metrics->get('close_call_rate'), 'data_status'));
        $this->assertSame('operational', data_get($metrics->get('close_call_rate'), 'data_status_source'));
        $this->assertSame('collecting_data', data_get($metrics->get('top1_resonance_rate'), 'data_status'));
        $this->assertSame('pending_sample', data_get($metrics->get('retake_consistency_index'), 'data_status'));
    }
}
