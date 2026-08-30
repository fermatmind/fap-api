<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Measurement\SearchMeasurementMode;
use Tests\Feature\SeoIntel\Concerns\BuildsMeasurementV2Context;
use Tests\TestCase;

final class SeoPlatform11FSearchMeasurementTest extends TestCase
{
    use BuildsMeasurementV2Context;

    public function test_search_accepts_only_validated_v2_context_and_preserves_aggregate_boundaries(): void
    {
        $context = $this->measurementContext();
        $this->assertSame('READY', $context['status']);
        $output = app(SearchMeasurementMode::class)->review($context);

        $this->assertSame('READY', $output['status']);
        $this->assertSame([7, 28, 90], $context['windows']);
        $this->assertSame(400, $output['findings'][0]['aggregate_metrics']['windows'][1]['metrics']['impressions']);
        $encoded = json_encode($output, JSON_THROW_ON_ERROR);
        foreach (['raw_query', 'query_display_masked', 'canonical_url', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->assertFalse($output['execution_allowed']);
        $this->assertSame(0, $output['model_calls'] + $output['tool_calls'] + $output['external_calls'] + $output['write_count']);
    }

    public function test_direct_entry_pii_and_causal_overclaim_hold_with_no_trusted_evidence(): void
    {
        $direct = app(SearchMeasurementMode::class)->review(['execution_allowed' => false]);
        $this->assertSame('HOLD', $direct['status']);
        $this->assertSame([], $direct['findings'][0]['aggregate_metrics']);

        foreach ([
            ['unknowns' => ['owner@example.com']],
            ['hypotheses' => ['Call +8613812345678']],
            ['associations' => ['Bearer abc.def.ghi']],
        ] as $unsafeFacts) {
            $context = $this->measurementContext();
            $context['facts'] = [...$context['facts'], ...$unsafeFacts];
            $context['context_hash'] = app(SeoRegistryHasher::class)->hash(array_diff_key($context, ['context_hash' => true]));
            $output = app(SearchMeasurementMode::class)->review($context);
            $this->assertSame('HOLD', $output['status']);
            $this->assertSame([], $output['findings'][0]['aggregate_metrics']);
        }

        $context = $this->measurementContext(payload: ['verified_facts' => ['A release caused the decline.']]);
        $this->assertSame('READY', $context['status']);
        $output = app(SearchMeasurementMode::class)->review($context);
        $this->assertSame('HOLD', $output['status']);
        $this->assertSame([], $output['findings'][0]['verified_facts']);
        $this->assertContains('causal_or_attribution_claim_not_supported', $output['findings'][0]['unknowns']);
    }
}
