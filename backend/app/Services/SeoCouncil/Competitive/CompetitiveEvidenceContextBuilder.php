<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use InvalidArgumentException;

final class CompetitiveEvidenceContextBuilder
{
    public function __construct(
        private readonly CompetitiveContractValidator $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    public function build(MissionRequestData $request, array $bundle): array
    {
        if (! $this->contracts->bundle($bundle, $request)) {
            throw new InvalidArgumentException('COMPETITIVE_BUNDLE_HOLD');
        }
        $output = (array) $bundle['competitive_output'];
        $context = [
            'version' => 'seo.competitive_council_context.v1',
            'status' => 'READY',
            'role_id' => 'seo.expert.competitor_research',
            'mission_id' => $request->missionId(),
            'page_family' => $request->payload['family'],
            'locale' => $request->payload['locale'],
            'bundle_hash' => $bundle['bundle_hash'],
            'output_hash' => $output['output_hash'],
            'handoff_hash' => data_get($output, '11i_handoff.handoff_hash'),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
        $context['context_hash'] = $this->hasher->hash($context);

        return $context;
    }
}
