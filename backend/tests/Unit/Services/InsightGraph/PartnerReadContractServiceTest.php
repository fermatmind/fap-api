<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InsightGraph;

use App\Services\InsightGraph\PartnerReadContractService;
use Tests\TestCase;

final class PartnerReadContractServiceTest extends TestCase
{
    public function test_it_builds_public_share_safe_partner_read_contract(): void
    {
        $service = new PartnerReadContractService;

        $contract = $service->buildForPublicShare([
            'graph_contract_version' => 'insight.graph.v1',
            'graph_fingerprint' => 'graph-fingerprint-123',
            'graph_scope' => 'public_share_safe',
            'supporting_scales' => ['MBTI', 'BIG5_OCEAN'],
            'nodes' => [
                ['id' => 'result_summary', 'kind' => 'result_summary'],
                ['id' => 'narrative', 'kind' => 'narrative'],
                ['id' => 'comparative', 'kind' => 'comparative'],
                ['id' => 'working_life', 'kind' => 'working_life'],
                ['id' => 'continue_reading', 'kind' => 'continue_reading'],
                ['id' => 'team_dynamics', 'kind' => 'team_dynamics'],
            ],
            'edges' => [
                ['from' => 'narrative', 'to' => 'result_summary', 'relation' => 'enriches'],
                ['from' => 'working_life', 'to' => 'continue_reading', 'relation' => 'recommended_next'],
                ['from' => 'team_dynamics', 'to' => 'result_summary', 'relation' => 'enriches'],
            ],
        ], [
            'attribution_scope' => 'share_public_surface',
        ]);

        $this->assertSame('partner.read.v1', $contract['version']);
        $this->assertSame('public_share_safe', $contract['graph_scope']);
        $this->assertSame('partner_public_read', $contract['read_scope']);
        $this->assertSame('public_summary_only', $contract['subject_scope']);
        $this->assertSame('share_public_surface', $contract['attribution_scope']);
        $this->assertSame(
            ['result_summary', 'narrative', 'comparative', 'working_life', 'continue_reading'],
            $contract['allowed_node_ids']
        );
        $this->assertSame(['enriches', 'recommended_next'], $contract['allowed_edge_types']);
    }

    public function test_it_builds_tenant_protected_partner_read_contract(): void
    {
        $service = new PartnerReadContractService;

        $contract = $service->buildForTenantWorkspace([
            'graph_contract_version' => 'insight.graph.v1',
            'graph_fingerprint' => 'workspace-graph-fingerprint-123',
            'graph_scope' => 'tenant_protected',
            'supporting_scales' => ['MBTI'],
            'nodes' => [
                ['id' => 'result_summary', 'kind' => 'result_summary'],
                ['id' => 'team_dynamics', 'kind' => 'team_dynamics'],
                ['id' => 'workspace_surface', 'kind' => 'workspace_surface'],
                ['id' => 'member_progress', 'kind' => 'member_progress'],
                ['id' => 'continue_reading', 'kind' => 'continue_reading'],
            ],
            'edges' => [
                ['from' => 'team_dynamics', 'to' => 'result_summary', 'relation' => 'enriches'],
                ['from' => 'workspace_surface', 'to' => 'continue_reading', 'relation' => 'recommended_next'],
                ['from' => 'result_summary', 'to' => 'member_progress', 'relation' => 'continues_to'],
            ],
        ], []);

        $this->assertSame('tenant_protected', $contract['graph_scope']);
        $this->assertSame('partner_tenant_read', $contract['read_scope']);
        $this->assertSame('tenant_aggregate_only', $contract['subject_scope']);
        $this->assertSame('workspace_partner_surface', $contract['attribution_scope']);
        $this->assertSame(
            ['result_summary', 'team_dynamics', 'workspace_surface', 'member_progress', 'continue_reading'],
            $contract['allowed_node_ids']
        );
        $this->assertSame(['enriches', 'recommended_next', 'continues_to'], $contract['allowed_edge_types']);
    }
}
