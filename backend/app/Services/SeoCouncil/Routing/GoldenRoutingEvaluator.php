<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Routing;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use RuntimeException;

final class GoldenRoutingEvaluator
{
    public function __construct(
        private readonly DeterministicMissionRouter $router,
        private readonly CouncilContractValidator $validator,
        private readonly SeoRegistryHasher $hasher,
        private readonly RoleCapabilityBindingRegistry $binding,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(): array
    {
        $corpus = json_decode((string) file_get_contents(resource_path('seo-agent/council/routing/seo.council_golden_routing.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        if (($corpus['binding_ref'] ?? null) !== $this->binding->reference()) {
            throw new RuntimeException('GOLDEN_BINDING_DRIFT');
        }
        $tp = $fp = $fn = $allTeam = $unauthorizedAllTeam = $totalModes = 0;
        $fixtures = (array) ($corpus['fixtures'] ?? []);
        foreach ($fixtures as $fixture) {
            $request = MissionRequestData::fromInput($this->request($fixture), 'cli', $this->validator, $this->hasher);
            $actual = $this->router->route($request);
            $expectedRoles = (array) $fixture['expected_roles'];
            $tp += count(array_intersect($actual['roles'], $expectedRoles));
            $fp += count(array_diff($actual['roles'], $expectedRoles));
            $fn += count(array_diff($expectedRoles, $actual['roles']));
            $totalModes += count($actual['roles']);
            $allTeam += (int) $actual['all_team'];
            $unauthorizedAllTeam += (int) ($actual['all_team'] && $fixture['mission_type'] !== 'global_portfolio');
            if ($actual['status'] !== $fixture['expected_status']) {
                $fp++;
                $fn++;
            }
        }
        $precisionDenominator = $tp + $fp;
        $recallDenominator = $tp + $fn;

        return [
            'corpus_id' => $corpus['corpus_id'],
            'corpus_version' => $corpus['corpus_version'],
            'corpus_hash' => $this->hasher->hash($corpus),
            'fixture_count' => count($fixtures),
            'binding_ref' => $this->binding->reference(),
            'routing_precision' => $this->metric($tp, $precisionDenominator),
            'routing_recall' => $this->metric($tp, $recallDenominator),
            'missed_required_mode_rate' => $this->metric($fn, $recallDenominator),
            'unnecessary_mode_rate' => $this->metric($fp, $precisionDenominator),
            'average_modes_per_run' => $this->metric($totalModes, count($fixtures)),
            'all_team_invocation_count' => ['numerator' => $allTeam, 'denominator' => count($fixtures), 'measurement_state' => 'observed'],
            'unauthorized_all_team_invocation_count' => ['numerator' => $unauthorizedAllTeam, 'denominator' => count($fixtures), 'measurement_state' => 'observed'],
            'human_route_correction_rate' => ['numerator' => null, 'denominator' => 0, 'measurement_state' => 'not_observed'],
            'routing_cost' => ['numerator' => 0, 'denominator' => count($fixtures), 'measurement_state' => 'observed'],
            'routing_latency' => ['numerator' => null, 'denominator' => 0, 'measurement_state' => 'not_observed'],
        ];
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function request(array $fixture): array
    {
        $refs = [];
        foreach ((array) $fixture['evidence_types'] as $index => $type) {
            $refs[] = [
                'bundle_id' => 'bundle:golden:'.$fixture['id'].':'.$index,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', $fixture['id'].':'.$index),
                'evidence_type' => $type,
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ];
        }

        return [
            'mission_id' => 'mission:golden:'.$fixture['id'],
            'idempotency_key' => 'golden:'.$fixture['id'],
            'mission_type' => $fixture['mission_type'],
            'family' => $fixture['family'],
            'locale' => $fixture['locale'],
            'review_domain' => $fixture['review_domain'],
            'requested_role' => null,
            'evidence_bundle_refs' => $refs,
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }

    /** @return array{numerator:int,denominator:int,measurement_state:string} */
    private function metric(int $numerator, int $denominator): array
    {
        return [
            'numerator' => $denominator === 0 ? null : $numerator,
            'denominator' => $denominator,
            'measurement_state' => $denominator === 0 ? 'not_observed' : 'observed',
        ];
    }
}
