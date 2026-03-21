<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

final class InsightGraphContractService
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $publicSurface
     * @return array<string,mixed>
     */
    public function buildForShare(array $payload, array $publicSurface): array
    {
        $scaleCode = strtoupper(trim((string) ($payload['scale_code'] ?? '')));
        if ($scaleCode === '') {
            return [];
        }

        $nodes = [];
        $edges = [];

        $this->pushNode(
            $nodes,
            'result_summary',
            'result_summary',
            $this->normalizeText($payload['title'] ?? null, $payload['type_name'] ?? null, $payload['type_code'] ?? null) ?? $scaleCode,
            $this->normalizeText($payload['summary'] ?? null, $payload['subtitle'] ?? null) ?? '',
            'share_summary'
        );

        $narrativeSummary = $this->normalizeText(data_get($payload, 'controlled_narrative_v1.narrative_summary'));
        if ($narrativeSummary !== null) {
            $this->pushNode(
                $nodes,
                'narrative',
                'narrative',
                'Public narrative',
                $narrativeSummary,
                'controlled_narrative_v1'
            );
            $edges[] = ['from' => 'narrative', 'to' => 'result_summary', 'relation' => 'enriches'];
        }

        $comparativeSummary = $this->normalizeText(
            data_get($payload, 'comparative_v1.cohort_relative_position.summary'),
            data_get($payload, 'comparative_v1.same_type_contrast.summary')
        );
        if ($comparativeSummary !== null) {
            $this->pushNode(
                $nodes,
                'comparative',
                'comparative',
                $this->normalizeText(
                    data_get($payload, 'comparative_v1.cohort_relative_position.label'),
                    data_get($payload, 'comparative_v1.same_type_contrast.label'),
                    'Relative position'
                ) ?? 'Relative position',
                $comparativeSummary,
                'comparative_v1'
            );
            $edges[] = ['from' => 'comparative', 'to' => 'result_summary', 'relation' => 'supports'];
        }

        $workingLifeSummary = $this->normalizeText(
            data_get($payload, 'cultural_calibration_v1.working_life_summary')
        );
        if ($workingLifeSummary === null) {
            $careerFocusKey = $this->normalizeText(data_get($payload, 'working_life_v1.career_focus_key'));
            if ($careerFocusKey !== null) {
                $workingLifeSummary = 'Current focus: '.$careerFocusKey;
            }
        }

        if ($workingLifeSummary !== null) {
            $this->pushNode(
                $nodes,
                'working_life',
                'working_life',
                'Working-life cue',
                $workingLifeSummary,
                'working_life_v1'
            );
            $edges[] = ['from' => 'working_life', 'to' => 'result_summary', 'relation' => 'recommended_next'];
        }

        $continueReadingKeys = $this->normalizeStringList($publicSurface['continue_reading_keys'] ?? []);
        $continueSummary = $continueReadingKeys !== []
            ? implode(' -> ', array_slice($continueReadingKeys, 0, 3))
            : $this->normalizeText($payload['primary_cta_label'] ?? null, $payload['primary_cta_path'] ?? null);
        if ($continueSummary !== null) {
            $this->pushNode(
                $nodes,
                'continue_reading',
                'continue_reading',
                'Continue path',
                $continueSummary,
                'public_surface_v1'
            );
            $edges[] = ['from' => 'result_summary', 'to' => 'continue_reading', 'relation' => 'continues_to'];
            if ($this->hasNode($nodes, 'working_life')) {
                $edges[] = ['from' => 'working_life', 'to' => 'continue_reading', 'relation' => 'recommended_next'];
            }
        }

        $supportingScales = $this->resolveSupportingScales($scaleCode, $payload);
        $fingerprintSeed = [
            'scale_code' => $scaleCode,
            'graph_scope' => 'public_share_safe',
            'supporting_scales' => $supportingScales,
            'public_summary_fingerprint' => $this->normalizeText($publicSurface['public_summary_fingerprint'] ?? null),
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        return [
            'version' => 'insight.graph.v1',
            'graph_contract_version' => 'insight.graph.v1',
            'root_node' => 'result_summary',
            'nodes' => $nodes,
            'edges' => $edges,
            'graph_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'graph_scope' => 'public_share_safe',
            'supporting_scales' => $supportingScales,
        ];
    }

    /**
     * @param  array<string,mixed>  $graph
     * @param  array<string,mixed>  $publicSurface
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function buildEmbedSurface(array $graph, array $publicSurface, array $payload): array
    {
        if ($graph === []) {
            return [];
        }

        $nodeIds = array_values(array_filter(array_map(
            static fn (mixed $node): string => is_array($node) ? trim((string) ($node['id'] ?? '')) : '',
            is_array($graph['nodes'] ?? null) ? $graph['nodes'] : []
        )));
        $continueTarget = $this->normalizeText(
            data_get($publicSurface, 'continue_reading_keys.0'),
            $payload['primary_cta_path'] ?? null
        ) ?? '';
        $summary = $this->normalizeText(
            data_get($payload, 'controlled_narrative_v1.narrative_summary'),
            $payload['summary'] ?? null,
            data_get($payload, 'comparative_v1.cohort_relative_position.summary')
        ) ?? '';
        $scaleCode = strtoupper(trim((string) ($payload['scale_code'] ?? '')));
        $surfaceKey = $scaleCode === 'BIG5_OCEAN' ? 'big5_share_embed_card' : 'mbti_share_embed_card';
        $title = $this->normalizeText($payload['title'] ?? null, $payload['type_name'] ?? null, $payload['type_code'] ?? null) ?? 'Insight graph';

        $fingerprintSeed = [
            'surface_key' => $surfaceKey,
            'entry_surface' => $this->normalizeText($publicSurface['entry_surface'] ?? null),
            'graph_fingerprint' => $this->normalizeText($graph['graph_fingerprint'] ?? null),
            'continue_target' => $continueTarget,
            'allowed_node_ids' => $nodeIds,
        ];

        return [
            'version' => 'embed.surface.v1',
            'surface_key' => $surfaceKey,
            'graph_scope' => $this->normalizeText($graph['graph_scope'] ?? null) ?? 'public_share_safe',
            'entry_surface' => $this->normalizeText($publicSurface['entry_surface'] ?? null) ?? '',
            'title' => $title,
            'summary' => $summary,
            'primary_cta_label' => $this->normalizeText($payload['primary_cta_label'] ?? null) ?? '',
            'primary_cta_path' => $this->normalizeText($payload['primary_cta_path'] ?? null) ?? '',
            'continue_target' => $continueTarget,
            'allowed_node_ids' => $nodeIds,
            'embed_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'render_mode' => 'card',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $teamDynamics
     * @param  array<string,mixed>|null  $workspaceSurface
     * @param  array{completed:int,total:int}  $completionRate
     * @return array<string,mixed>
     */
    public function buildForWorkspaceSummary(?array $teamDynamics, ?array $workspaceSurface, array $completionRate): array
    {
        if (! is_array($teamDynamics) && ! is_array($workspaceSurface)) {
            return [];
        }

        $nodes = [];
        $edges = [];
        $supportingScales = $this->normalizeStringList(
            (is_array($teamDynamics) ? ($teamDynamics['supporting_scales'] ?? []) : [])
        );
        if ($supportingScales === [] && is_array($workspaceSurface)) {
            $supportingScales = $this->normalizeStringList($workspaceSurface['supporting_scales'] ?? []);
        }
        if ($supportingScales === []) {
            $supportingScales = ['MBTI'];
        }

        $teamFocusKey = $this->normalizeText(
            is_array($workspaceSurface) ? ($workspaceSurface['workspace_focus_key'] ?? null) : null,
            is_array($teamDynamics) ? ($teamDynamics['team_focus_key'] ?? null) : null
        ) ?? 'team_alignment_review';

        $this->pushNode(
            $nodes,
            'result_summary',
            'result_summary',
            'Protected team workspace',
            'Focus: '.$teamFocusKey,
            'workspace_surface_v1'
        );

        if (is_array($teamDynamics)) {
            $teamSummaryParts = array_filter([
                $this->normalizeText($teamDynamics['team_focus_key'] ?? null),
                implode(' | ', $this->normalizeStringList($teamDynamics['communication_fit_keys'] ?? [])),
                implode(' | ', $this->normalizeStringList($teamDynamics['decision_mix_keys'] ?? [])),
                implode(' | ', $this->normalizeStringList($teamDynamics['stress_pattern_keys'] ?? [])),
            ]);

            $teamSummary = implode(' · ', array_slice(array_values($teamSummaryParts), 0, 4));
            if ($teamSummary !== '') {
                $this->pushNode(
                    $nodes,
                    'team_dynamics',
                    'team_dynamics',
                    'Team dynamics',
                    $teamSummary,
                    'team_dynamics_v1'
                );
                $edges[] = ['from' => 'team_dynamics', 'to' => 'result_summary', 'relation' => 'enriches'];
            }
        }

        if (is_array($workspaceSurface)) {
            $workspaceSummaryParts = $this->normalizeStringList($workspaceSurface['manager_action_keys'] ?? []);
            $workspaceSummary = $workspaceSummaryParts !== []
                ? implode(' -> ', array_slice($workspaceSummaryParts, 0, 3))
                : $teamFocusKey;

            $this->pushNode(
                $nodes,
                'workspace_surface',
                'workspace_surface',
                'Workspace focus',
                $workspaceSummary,
                'workspace_surface_v1'
            );
            $edges[] = ['from' => 'workspace_surface', 'to' => 'result_summary', 'relation' => 'supports'];
        }

        $completed = max(0, (int) ($completionRate['completed'] ?? 0));
        $total = max(0, (int) ($completionRate['total'] ?? 0));
        $pending = max(0, $total - $completed);
        $memberProgressSummary = sprintf('completed:%d pending:%d total:%d', $completed, $pending, $total);
        $this->pushNode(
            $nodes,
            'member_progress',
            'member_progress',
            'Member progress',
            $memberProgressSummary,
            'assessment_progress_v1'
        );
        $edges[] = ['from' => 'result_summary', 'to' => 'member_progress', 'relation' => 'continues_to'];

        $continueKeys = $this->normalizeStringList(is_array($workspaceSurface) ? ($workspaceSurface['manager_action_keys'] ?? []) : []);
        if ($continueKeys === []) {
            $continueKeys = $this->normalizeStringList(is_array($workspaceSurface) ? ($workspaceSurface['member_drill_in_keys'] ?? []) : []);
        }
        $continueSummary = $continueKeys !== [] ? implode(' -> ', array_slice($continueKeys, 0, 3)) : 'check_member_progress';
        $this->pushNode(
            $nodes,
            'continue_reading',
            'continue_reading',
            'Manager next',
            $continueSummary,
            'workspace_surface_v1'
        );
        $edges[] = ['from' => 'workspace_surface', 'to' => 'continue_reading', 'relation' => 'recommended_next'];
        $edges[] = ['from' => 'member_progress', 'to' => 'continue_reading', 'relation' => 'recommended_next'];

        $fingerprintSeed = [
            'graph_scope' => 'tenant_protected',
            'team_focus_key' => $teamFocusKey,
            'supporting_scales' => $supportingScales,
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        return [
            'version' => 'insight.graph.v1',
            'graph_contract_version' => 'insight.graph.v1',
            'root_node' => 'result_summary',
            'nodes' => $nodes,
            'edges' => $edges,
            'graph_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'graph_scope' => 'tenant_protected',
            'supporting_scales' => $supportingScales,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     */
    private function hasNode(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (trim((string) ($node['id'] ?? '')) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     */
    private function pushNode(
        array &$nodes,
        string $id,
        string $kind,
        string $title,
        string $summary,
        string $sourceContract
    ): void {
        if ($title === '' || $summary === '') {
            return;
        }

        $nodes[] = [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'source_contract' => $sourceContract,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function resolveSupportingScales(string $scaleCode, array $payload): array
    {
        $supporting = [$scaleCode];

        foreach ([
            data_get($payload, 'working_life_v1.supporting_scales'),
            data_get($payload, 'mbti_cross_assessment_v1.supporting_scales'),
        ] as $candidate) {
            foreach ($this->normalizeStringList(is_array($candidate) ? $candidate : []) as $value) {
                $supporting[] = strtoupper($value);
            }
        }

        $unique = [];
        foreach ($supporting as $value) {
            $normalized = trim(strtoupper((string) $value));
            if ($normalized === '') {
                continue;
            }
            $unique[$normalized] = true;
        }

        return array_keys($unique);
    }

    private function normalizeText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function normalizeStringList(array $values): array
    {
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
