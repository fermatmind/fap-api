<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Services\InsightGraph\WidgetSurfaceContractService;
use Tests\TestCase;

final class WidgetSurfaceContractServiceTest extends TestCase
{
    public function test_it_builds_a_public_share_safe_widget_surface_contract(): void
    {
        $service = new WidgetSurfaceContractService;

        $contract = $service->buildForPublicShare([
            'surface_key' => 'mbti_share_embed_card',
            'graph_scope' => 'public_share_safe',
            'entry_surface' => 'mbti_share_landing',
            'title' => 'Campaigner',
            'summary' => 'A public-safe widget summary.',
            'primary_cta_label' => 'Start MBTI test',
            'primary_cta_path' => '/en/tests/mbti-personality-test-16-personality-types/take',
            'continue_target' => 'career.next_step',
            'allowed_node_ids' => ['result_summary', 'narrative'],
            'embed_fingerprint' => 'embed-fingerprint-123',
            'render_mode' => 'card',
        ], [
            'graph_scope' => 'public_share_safe',
            'graph_fingerprint' => 'graph-fingerprint-123',
            'allowed_node_ids' => ['result_summary', 'narrative', 'comparative', 'working_life', 'continue_reading'],
            'allowed_edge_types' => ['enriches', 'supports', 'recommended_next', 'continues_to'],
            'attribution_scope' => 'share_public_surface',
        ], [
            'entry_surface' => 'mbti_share_landing',
            'attribution_scope' => 'share_public_surface',
        ]);

        $this->assertSame('widget.surface.v1', $contract['version']);
        $this->assertSame('widget.surface.v1', $contract['widget_contract_version']);
        $this->assertSame('public_share_safe', $contract['widget_scope']);
        $this->assertSame('card', $contract['host_mode']);
        $this->assertSame('public_share_primary', $contract['slot_key']);
        $this->assertSame('summary_card', $contract['size_preset']);
        $this->assertSame('mbti_share_embed_card', $contract['surface_key']);
        $this->assertSame('share_public_surface', $contract['attribution_scope']);
        $this->assertSame(['result_summary', 'narrative', 'comparative', 'working_life', 'continue_reading'], $contract['allowed_node_ids']);
        $this->assertSame(['enriches', 'supports', 'recommended_next', 'continues_to'], $contract['allowed_edge_types']);
    }
}
