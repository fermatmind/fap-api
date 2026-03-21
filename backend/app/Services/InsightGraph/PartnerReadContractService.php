<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

final class PartnerReadContractService
{
    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $publicSurface
     * @return array<string,mixed>
     */
    public function buildForPublicShare(array $graph, array $publicSurface): array
    {
        return $this->build(
            graph: $graph,
            allowedKinds: [
                'result_summary',
                'narrative',
                'comparative',
                'working_life',
                'continue_reading',
            ],
            graphScope: 'public_share_safe',
            readScope: 'partner_public_read',
            subjectScope: 'public_summary_only',
            attributionScope: $this->normalizeText($publicSurface['attribution_scope'] ?? null) ?? 'share_public_surface'
        );
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $workspaceSurface
     * @return array<string,mixed>
     */
    public function buildForTenantWorkspace(array $graph, array $workspaceSurface): array
    {
        return $this->build(
            graph: $graph,
            allowedKinds: [
                'result_summary',
                'team_dynamics',
                'workspace_surface',
                'member_progress',
                'continue_reading',
            ],
            graphScope: 'tenant_protected',
            readScope: 'partner_tenant_read',
            subjectScope: 'tenant_aggregate_only',
            attributionScope: $this->normalizeText($workspaceSurface['attribution_scope'] ?? null) ?? 'workspace_partner_surface'
        );
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  list<string>  $allowedKinds
     * @return array<string,mixed>
     */
    private function build(
        array $graph,
        array $allowedKinds,
        string $graphScope,
        string $readScope,
        string $subjectScope,
        string $attributionScope
    ): array {
        if ($graph === []) {
            return [];
        }

        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        $kindAllowlist = array_fill_keys($allowedKinds, true);
        $allowedNodeIds = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $kind = $this->normalizeText($node['kind'] ?? null);
            $id = $this->normalizeText($node['id'] ?? null);
            if ($kind === null || $id === null) {
                continue;
            }

            if (! isset($kindAllowlist[$kind])) {
                continue;
            }

            $allowedNodeIds[$id] = true;
        }

        $allowedEdgeTypes = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = $this->normalizeText($edge['from'] ?? null);
            $to = $this->normalizeText($edge['to'] ?? null);
            $relation = $this->normalizeText($edge['relation'] ?? null);
            if ($from === null || $to === null || $relation === null) {
                continue;
            }

            if (! isset($allowedNodeIds[$from]) || ! isset($allowedNodeIds[$to])) {
                continue;
            }

            $allowedEdgeTypes[$relation] = true;
        }

        return [
            'version' => 'partner.read.v1',
            'graph_scope' => $graphScope,
            'graph_contract_version' => $this->normalizeText($graph['graph_contract_version'] ?? null) ?? 'insight.graph.v1',
            'graph_fingerprint' => $this->normalizeText($graph['graph_fingerprint'] ?? null) ?? '',
            'supporting_scales' => $this->normalizeStringList($graph['supporting_scales'] ?? []),
            'allowed_node_ids' => array_keys($allowedNodeIds),
            'allowed_edge_types' => array_keys($allowedEdgeTypes),
            'read_scope' => $readScope,
            'subject_scope' => $subjectScope,
            'attribution_scope' => $attributionScope,
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $item = $this->normalizeText($value);
            if ($item === null) {
                continue;
            }

            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }
}
