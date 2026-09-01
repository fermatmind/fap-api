<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use Throwable;

final class CompetitiveCoordinator implements CompetitiveRunner
{
    public function __construct(
        private readonly CompetitiveEvidenceBundleLoader $loader,
        private readonly CompetitiveEvidenceContextBuilder $contexts,
        private readonly CompetitiveContractValidator $contracts,
        private readonly CompetitiveActivityLedger $activity,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    public function run(MissionRequestData $request, array $handoff, string $releaseSha, string $environment): array
    {
        $this->activity->record('runner_calls');
        try {
            $bundles = $this->loader->load($request, $releaseSha, $environment);
            if (count($bundles) !== 1) {
                return $this->modeOutput($handoff, 'HOLD', 'competitive_bundle_body_unavailable');
            }
            $context = $this->contexts->build($request, $bundles[0]);
            if (! $this->contracts->context($context)) {
                return $this->modeOutput($handoff, 'HOLD', 'competitive_context_contract_hold');
            }

            return $this->modeOutput($handoff, 'PASS', 'competitive_evidence_ready');
        } catch (Throwable) {
            return $this->modeOutput($handoff, 'HOLD', 'competitive_context_contract_hold');
        }
    }

    /** @param array<string, mixed> $handoff @return array<string, mixed> */
    private function modeOutput(array $handoff, string $status, string $summary): array
    {
        $activity = $this->activity->snapshot();
        $writeCount = array_sum(array_intersect_key($activity, array_flip([
            'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes',
        ])));
        $output = [
            'output_id' => $this->hasher->hash([$handoff['handoff_hash'] ?? null, $summary]),
            'handoff_hash' => $handoff['handoff_hash'] ?? null,
            'role_id' => $handoff['target_role_id'] ?? null,
            'status' => $status,
            'summary_code' => $summary,
            'execution_allowed' => false,
            'model_calls' => $activity['model_calls'],
            'tool_calls' => $activity['tool_calls'],
            'external_calls' => $activity['external_calls'],
            'write_count' => $writeCount,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }
}
