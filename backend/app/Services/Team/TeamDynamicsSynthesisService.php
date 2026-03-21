<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Models\Assessment;
use Illuminate\Support\Collection;

final class TeamDynamicsSynthesisService
{
    private const VERSION = 'team_dynamics.v1';

    /**
     * @param  Collection<int,object>  $results
     * @return array<string,mixed>|null
     */
    public function buildForAssessment(Assessment $assessment, Collection $results, int $teamMemberCount): ?array
    {
        $scaleCode = strtoupper(trim((string) ($assessment->scale_code ?? '')));
        $analyzedMemberCount = $results->count();

        if ($teamMemberCount < 2 || $analyzedMemberCount < 2) {
            return null;
        }

        return match ($scaleCode) {
            'MBTI' => $this->buildForMbti($results, $teamMemberCount, $analyzedMemberCount),
            'BIG5_OCEAN' => $this->buildForBigFive($results, $teamMemberCount, $analyzedMemberCount),
            default => null,
        };
    }

    /**
     * @param  Collection<int,object>  $results
     * @return array<string,mixed>
     */
    private function buildForMbti(Collection $results, int $teamMemberCount, int $analyzedMemberCount): array
    {
        $axisCounts = [
            'E' => 0, 'I' => 0,
            'S' => 0, 'N' => 0,
            'T' => 0, 'F' => 0,
            'J' => 0, 'P' => 0,
            'A' => 0, 'TURB' => 0,
        ];

        foreach ($results as $row) {
            $typeCode = strtoupper(trim((string) ($row->type_code ?? '')));
            if ($typeCode === '') {
                $typeCode = strtoupper(trim((string) data_get($this->normalizeJson($row->result_json ?? null), 'type_code', '')));
            }

            if (preg_match('/^[EI][SN][TF][JP](?:-[AT])?$/', $typeCode) !== 1) {
                continue;
            }

            $axisCounts[$typeCode[0]]++;
            $axisCounts[$typeCode[1]]++;
            $axisCounts[$typeCode[2]]++;
            $axisCounts[$typeCode[3]]++;
            if (str_ends_with($typeCode, '-A')) {
                $axisCounts['A']++;
            } elseif (str_ends_with($typeCode, '-T')) {
                $axisCounts['TURB']++;
            }
        }

        $communicationFitKeys = [];
        if ($axisCounts['E'] > 0 && $axisCounts['I'] > 0) {
            $communicationFitKeys[] = 'team.communication.energy_translation';
        }
        $communicationFitKeys[] = $axisCounts['N'] >= $axisCounts['S']
            ? 'team.communication.abstract_first'
            : 'team.communication.concrete_first';

        $decisionMixKeys = [];
        if ($axisCounts['T'] > 0 && $axisCounts['F'] > 0) {
            $decisionMixKeys[] = 'team.decision.logic_empathy_mix';
        }
        $decisionMixKeys[] = $axisCounts['J'] >= $axisCounts['P']
            ? 'team.decision.structured_closure'
            : 'team.decision.iterative_exploration';

        $stressPatternKeys = [];
        if ($axisCounts['TURB'] > 0 && $axisCounts['A'] > 0) {
            $stressPatternKeys[] = 'team.stress.stability_gap';
        }
        $stressPatternKeys[] = $axisCounts['TURB'] >= $axisCounts['A']
            ? 'team.stress.reactivity_spikes'
            : 'team.stress.steady_recovery';

        $blindspotKeys = [];
        if ($axisCounts['N'] > 0 && $axisCounts['S'] > 0) {
            $blindspotKeys[] = 'team.blindspot.context_translation';
        }
        if ($axisCounts['T'] > 0 && $axisCounts['F'] > 0) {
            $blindspotKeys[] = 'team.blindspot.decision_friction';
        }
        if ($axisCounts['J'] > 0 && $axisCounts['P'] > 0) {
            $blindspotKeys[] = 'team.blindspot.execution_alignment';
        }

        $teamFocusKey = $this->resolveFocusKey([
            ...$communicationFitKeys,
            ...$decisionMixKeys,
            ...$stressPatternKeys,
            ...$blindspotKeys,
        ], 'team.communication.energy_translation');

        $actionPromptKeys = $this->buildActionPromptKeys($teamFocusKey, 'MBTI');

        return $this->finalize(
            $teamMemberCount,
            $analyzedMemberCount,
            ['MBTI'],
            $teamFocusKey,
            $communicationFitKeys,
            $decisionMixKeys,
            $stressPatternKeys,
            $blindspotKeys,
            $actionPromptKeys
        );
    }

    /**
     * @param  Collection<int,object>  $results
     * @return array<string,mixed>
     */
    private function buildForBigFive(Collection $results, int $teamMemberCount, int $analyzedMemberCount): array
    {
        $domains = ['O', 'C', 'E', 'A', 'N'];
        $sum = array_fill_keys($domains, 0.0);
        $count = array_fill_keys($domains, 0);
        $high = array_fill_keys($domains, 0);
        $low = array_fill_keys($domains, 0);

        foreach ($results as $row) {
            $scores = $this->normalizeJson($row->scores_pct ?? null);
            foreach ($domains as $domain) {
                $value = $scores[$domain] ?? null;
                if (! is_numeric($value)) {
                    continue;
                }
                $numeric = (float) $value;
                $sum[$domain] += $numeric;
                $count[$domain]++;
                if ($numeric >= 67) {
                    $high[$domain]++;
                } elseif ($numeric <= 33) {
                    $low[$domain]++;
                }
            }
        }

        $means = [];
        foreach ($domains as $domain) {
            $means[$domain] = $count[$domain] > 0 ? round($sum[$domain] / $count[$domain], 2) : 0.0;
        }

        $communicationFitKeys = [];
        if ($high['E'] > 0 && $low['E'] > 0) {
            $communicationFitKeys[] = 'team.communication.energy_range';
        }
        $communicationFitKeys[] = $means['A'] >= 50
            ? 'team.communication.harmony_bias'
            : 'team.communication.direct_challenge';

        $decisionMixKeys = [];
        if ($means['O'] >= 60 && $means['C'] >= 60) {
            $decisionMixKeys[] = 'team.decision.idea_execution_mix';
        } elseif ($means['O'] >= $means['C']) {
            $decisionMixKeys[] = 'team.decision.exploratory_ideation';
        } else {
            $decisionMixKeys[] = 'team.decision.execution_rigor';
        }
        if ($means['A'] < 45) {
            $decisionMixKeys[] = 'team.decision.challenge_tolerance';
        }

        $stressPatternKeys = [];
        if ($high['N'] > 0 && $low['N'] > 0) {
            $stressPatternKeys[] = 'team.stress.reactivity_range';
        }
        $stressPatternKeys[] = $means['N'] >= 55
            ? 'team.stress.emotional_load'
            : 'team.stress.steady_baseline';

        $blindspotKeys = [];
        if ($means['C'] < 45) {
            $blindspotKeys[] = 'team.blindspot.follow_through_drift';
        }
        if ($means['A'] < 45) {
            $blindspotKeys[] = 'team.blindspot.relational_friction';
        }
        if ($means['O'] >= 65 && $means['C'] < 50) {
            $blindspotKeys[] = 'team.blindspot.ideation_overhang';
        }

        $teamFocusKey = $this->resolveFocusKey([
            ...$communicationFitKeys,
            ...$decisionMixKeys,
            ...$stressPatternKeys,
            ...$blindspotKeys,
        ], 'team.decision.idea_execution_mix');

        $actionPromptKeys = $this->buildActionPromptKeys($teamFocusKey, 'BIG5_OCEAN');

        return $this->finalize(
            $teamMemberCount,
            $analyzedMemberCount,
            ['BIG5_OCEAN'],
            $teamFocusKey,
            $communicationFitKeys,
            $decisionMixKeys,
            $stressPatternKeys,
            $blindspotKeys,
            $actionPromptKeys
        );
    }

    /**
     * @param  list<string>  $candidateKeys
     */
    private function resolveFocusKey(array $candidateKeys, string $fallback): string
    {
        foreach ($candidateKeys as $key) {
            $normalized = trim((string) $key);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function buildActionPromptKeys(string $teamFocusKey, string $scaleCode): array
    {
        return match ($teamFocusKey) {
            'team.communication.energy_translation' => [
                'team.action.sync_communication_cadence',
                'team.action.document_async_then_discuss',
            ],
            'team.decision.logic_empathy_mix', 'team.decision.idea_execution_mix' => [
                'team.action.separate_idea_and_decision_rounds',
                'team.action.assign_decision_owner',
            ],
            'team.stress.stability_gap', 'team.stress.reactivity_range' => [
                'team.action.define_escalation_norms',
                'team.action.add_recovery_buffer',
            ],
            default => $scaleCode === 'BIG5_OCEAN'
                ? ['team.action.clarify_success_criteria', 'team.action.reduce_context_switching']
                : ['team.action.align_decision_rules', 'team.action.close_execution_gaps'],
        };
    }

    /**
     * @param  list<string>  $supportingScales
     * @param  list<string>  $communicationFitKeys
     * @param  list<string>  $decisionMixKeys
     * @param  list<string>  $stressPatternKeys
     * @param  list<string>  $blindspotKeys
     * @param  list<string>  $actionPromptKeys
     * @return array<string,mixed>
     */
    private function finalize(
        int $teamMemberCount,
        int $analyzedMemberCount,
        array $supportingScales,
        string $teamFocusKey,
        array $communicationFitKeys,
        array $decisionMixKeys,
        array $stressPatternKeys,
        array $blindspotKeys,
        array $actionPromptKeys
    ): array {
        $authority = [
            'version' => self::VERSION,
            'team_focus_key' => $teamFocusKey,
            'team_member_count' => $teamMemberCount,
            'analyzed_member_count' => $analyzedMemberCount,
            'supporting_scales' => array_values(array_unique(array_map('strval', $supportingScales))),
            'communication_fit_keys' => array_values(array_unique(array_filter(array_map('strval', $communicationFitKeys)))),
            'decision_mix_keys' => array_values(array_unique(array_filter(array_map('strval', $decisionMixKeys)))),
            'stress_pattern_keys' => array_values(array_unique(array_filter(array_map('strval', $stressPatternKeys)))),
            'team_blindspot_keys' => array_values(array_unique(array_filter(array_map('strval', $blindspotKeys)))),
            'team_action_prompt_keys' => array_values(array_unique(array_filter(array_map('strval', $actionPromptKeys)))),
            'workspace_scope' => 'assessment.summary.v0_4',
        ];

        $authority['fingerprint'] = sha1(json_encode($authority, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: self::VERSION);

        return $authority;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
