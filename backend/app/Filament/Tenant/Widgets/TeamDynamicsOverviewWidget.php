<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Assessment;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessments\AssessmentService;
use App\Support\OrgContext;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class TeamDynamicsOverviewWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return 'Team dynamics';
    }

    protected function getStats(): array
    {
        $orgId = app(OrgContext::class)->orgId();
        if ($orgId <= 0) {
            return [
                Stat::make('Team dynamics', 'No tenant context')->color('gray'),
            ];
        }

        $assessment = $this->resolveAssessment($orgId);
        if (! $assessment instanceof Assessment) {
            return [
                Stat::make('Team dynamics', 'No completed team summary yet')
                    ->description('Invite at least two members to the same MBTI or Big Five assessment.')
                    ->color('gray'),
            ];
        }

        $summary = app(AssessmentService::class)->summary($assessment);
        $teamDynamics = is_array($summary['team_dynamics_v1'] ?? null) ? $summary['team_dynamics_v1'] : null;
        if ($teamDynamics === null) {
            return [
                Stat::make('Team dynamics', 'Not enough analyzed members')
                    ->description('Complete at least two results in the same assessment.')
                    ->color('warning'),
            ];
        }

        $this->recordExposure($orgId, $assessment, $teamDynamics);

        return [
            Stat::make('Team focus', $this->humanizeKey((string) ($teamDynamics['team_focus_key'] ?? 'team.focus')))
                ->description(sprintf(
                    'Assessment #%d · %d/%d analyzed',
                    (int) $assessment->id,
                    (int) ($teamDynamics['analyzed_member_count'] ?? 0),
                    (int) ($teamDynamics['team_member_count'] ?? 0)
                ))
                ->color('primary'),
            Stat::make('Communication', $this->firstHumanized($teamDynamics['communication_fit_keys'] ?? [], 'No signal yet'))
                ->description($this->firstHumanized($teamDynamics['decision_mix_keys'] ?? [], 'Decision mix pending'))
                ->color('info'),
            Stat::make('Stress / blindspot', $this->firstHumanized($teamDynamics['stress_pattern_keys'] ?? [], 'No stress signal yet'))
                ->description($this->firstHumanized($teamDynamics['team_blindspot_keys'] ?? [], 'No blindspot signal yet'))
                ->color('warning'),
            Stat::make('Next action', $this->firstHumanized($teamDynamics['team_action_prompt_keys'] ?? [], 'No team action prompt'))
                ->description(implode(', ', array_values((array) ($teamDynamics['supporting_scales'] ?? []))))
                ->color('success'),
        ];
    }

    private function resolveAssessment(int $orgId): ?Assessment
    {
        $candidates = Assessment::query()
            ->where('org_id', $orgId)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        foreach ($candidates as $assessment) {
            $summary = app(AssessmentService::class)->summary($assessment);
            if (is_array($summary['team_dynamics_v1'] ?? null)) {
                return $assessment;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $teamDynamics
     */
    private function recordExposure(int $orgId, Assessment $assessment, array $teamDynamics): void
    {
        $user = auth((string) config('tenant.guard', 'tenant'))->user();
        $userId = is_object($user) && method_exists($user, 'getAuthIdentifier')
            ? (int) $user->getAuthIdentifier()
            : null;

        app(EventRecorder::class)->record('team_workspace_surface_view', $userId, [
            'assessment_id' => (int) $assessment->id,
            'team_focus_key' => (string) ($teamDynamics['team_focus_key'] ?? ''),
            'supporting_scales' => array_values((array) ($teamDynamics['supporting_scales'] ?? [])),
            'version' => (string) ($teamDynamics['version'] ?? ''),
            'workspace_scope' => (string) ($teamDynamics['workspace_scope'] ?? ''),
        ], [
            'org_id' => $orgId,
            'scale_code' => (string) ($assessment->scale_code ?? ''),
        ]);
    }

    /**
     * @param  list<string>|array<int,string>  $keys
     */
    private function firstHumanized(array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            $normalized = trim((string) $key);
            if ($normalized !== '') {
                return $this->humanizeKey($normalized);
            }
        }

        return $fallback;
    }

    private function humanizeKey(string $key): string
    {
        $tail = trim((string) preg_replace('/^[^.]+\./', '', $key));
        $tail = str_replace(['.', '_'], ' ', $tail);
        $tail = preg_replace('/\s+/', ' ', $tail) ?? $tail;

        return ucfirst(trim($tail));
    }
}
