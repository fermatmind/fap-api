<?php

declare(strict_types=1);

namespace App\Services\Assessments;

final class WorkspaceSurfaceContractService
{
    /**
     * @param  array<string,mixed>|null  $teamDynamics
     * @param  array{completed:int,total:int}  $completionRate
     * @return array<string,mixed>
     */
    public function build(?array $teamDynamics, array $completionRate): array
    {
        $completed = max(0, (int) ($completionRate['completed'] ?? 0));
        $total = max(0, (int) ($completionRate['total'] ?? 0));
        $pending = max(0, $total - $completed);

        $workspaceFocusKey = $this->normalizeString($teamDynamics['team_focus_key'] ?? null)
            ?? ($completed > 0 ? 'team_alignment_review' : 'team_setup_incomplete');

        $managerActionKeys = $this->normalizeStringList($teamDynamics['team_action_prompt_keys'] ?? []);
        if ($managerActionKeys === []) {
            $managerActionKeys = $completed > 0
                ? ['review_team_action_prompts', 'check_member_progress']
                : ['invite_more_members', 'check_member_progress'];
        }

        $memberDrillInKeys = [];
        if ($completed > 0) {
            $memberDrillInKeys[] = 'completed_assignments';
        }
        if ($pending > 0) {
            $memberDrillInKeys[] = 'pending_assignments';
        }
        if ($memberDrillInKeys === []) {
            $memberDrillInKeys[] = 'assignment_progress';
        }

        $supportingScales = $this->normalizeStringList($teamDynamics['supporting_scales'] ?? []);
        $analyzedMemberCount = max(0, (int) ($teamDynamics['analyzed_member_count'] ?? 0));
        $teamMemberCount = max(0, (int) ($teamDynamics['team_member_count'] ?? $total));
        $workspaceScope = $this->normalizeString($teamDynamics['workspace_scope'] ?? 'tenant_protected')
            ?? 'tenant_protected';

        $fingerprintSeed = [
            'workspace_focus_key' => $workspaceFocusKey,
            'manager_action_keys' => $managerActionKeys,
            'member_drill_in_keys' => $memberDrillInKeys,
            'supporting_scales' => $supportingScales,
            'team_member_count' => $teamMemberCount,
            'analyzed_member_count' => $analyzedMemberCount,
            'workspace_scope' => $workspaceScope,
            'completed' => $completed,
            'pending' => $pending,
            'team_dynamics_version' => $this->normalizeString($teamDynamics['version'] ?? null),
        ];

        return [
            'version' => 'workspace.surface.v1',
            'workspace_focus_key' => $workspaceFocusKey,
            'manager_action_keys' => $managerActionKeys,
            'member_drill_in_keys' => $memberDrillInKeys,
            'workspace_surface_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'workspace_scope' => $workspaceScope,
            'supporting_scales' => $supportingScales,
            'team_member_count' => $teamMemberCount,
            'analyzed_member_count' => $analyzedMemberCount,
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $item = $this->normalizeString($value);
            if ($item === null) {
                continue;
            }

            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }
}
