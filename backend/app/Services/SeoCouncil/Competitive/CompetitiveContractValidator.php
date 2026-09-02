<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceBoundaryGuard;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;

final class CompetitiveContractValidator
{
    public function __construct(
        private readonly CompetitiveEvidenceBoundaryGuard $guard,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    public function admits(MissionRequestData $request): bool
    {
        $ready = [];
        foreach ((array) $request->payload['evidence_bundle_refs'] as $ref) {
            if (($ref['status'] ?? null) === 'READY') {
                $ready[(string) ($ref['evidence_type'] ?? '')] = true;
            }
        }

        return isset($ready['gateway_competitor_public'], $ready['search_measurement']);
    }

    /** @param array<string, mixed> $bundle */
    public function bundle(array $bundle, MissionRequestData $request): bool
    {
        $expected = ['bundle_hash', 'competitive_output', 'environment', 'release_ref'];
        $actual = array_keys($bundle);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        $output = (array) ($bundle['competitive_output'] ?? []);

        return $actual === $expected
            && $this->admits($request)
            && in_array($bundle['environment'] ?? null, ['ci_candidate', 'staging_runtime', 'production_runtime'], true)
            && preg_match('/^release_[a-p]{64}$/D', (string) ($bundle['release_ref'] ?? '')) === 1
            && $this->guard->output($output)
            && ($output['status'] ?? null) === 'READY'
            && data_get($output, '11i_handoff.source_freshness') === 'fresh'
            && hash_equals(
                $this->hasher->hashWithout($bundle, 'bundle_hash'),
                (string) ($bundle['bundle_hash'] ?? ''),
            );
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): bool
    {
        return ($context['version'] ?? null) === 'seo.competitive_council_context.v1'
            && ($context['status'] ?? null) === 'READY'
            && ($context['role_id'] ?? null) === 'seo.expert.competitor_research'
            && ($context['model_calls'] ?? null) === 0
            && ($context['tool_calls'] ?? null) === 0
            && ($context['external_calls'] ?? null) === 0
            && ($context['write_count'] ?? null) === 0
            && ($context['execution_allowed'] ?? null) === false
            && is_string($context['context_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($context, 'context_hash'), (string) $context['context_hash']);
    }
}
