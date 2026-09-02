<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveReleaseIdentity;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReadOnlyCompetitiveEvidenceBundleLoader implements CompetitiveEvidenceBundleLoader
{
    public function __construct(
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly SeoRegistryHasher $hasher,
        private readonly CompetitiveReleaseIdentity $releaseIdentity,
    ) {}

    public function load(MissionRequestData $request, string $releaseSha, string $environment): array
    {
        $expectedEnvironment = match ($environment) {
            'staging_runtime' => 'staging',
            'production_runtime' => 'production',
            default => null,
        };
        if ($expectedEnvironment === null || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1) {
            return [];
        }

        try {
            $expectedReleaseRef = $this->releaseIdentity->reference($expectedEnvironment, $releaseSha);
            foreach ((array) $request->payload['evidence_bundle_refs'] as $ref) {
                if (($ref['evidence_type'] ?? null) !== 'gateway_competitor_public'
                    || ($ref['status'] ?? null) !== 'READY') {
                    continue;
                }
                $row = DB::connection((string) config('seo_agent_evidence.connection', 'seo_intel'))
                    ->table('seo_evidence_bundles')
                    ->where('bundle_id', (string) ($ref['bundle_id'] ?? ''))
                    ->where('bundle_version', (int) ($ref['bundle_version'] ?? 0))
                    ->where('bundle_hash', (string) ($ref['bundle_hash'] ?? ''))
                    ->first(['bundle_json']);
                if ($row === null) {
                    continue;
                }
                $bundle = json_decode((string) $row->bundle_json, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($bundle) || ! $this->verifier->verify($bundle)['valid']) {
                    continue;
                }
                $payload = (array) ($bundle['payload'] ?? []);
                if (($payload['environment'] ?? null) !== $expectedEnvironment
                    || ($payload['release_ref'] ?? null) !== $expectedReleaseRef) {
                    continue;
                }
                $loaded = [
                    'competitive_output' => (array) ($payload['competitive_output'] ?? []),
                    'environment' => $environment,
                    'release_ref' => $expectedReleaseRef,
                ];
                $loaded['bundle_hash'] = $this->hasher->hash($loaded);

                return [$loaded];
            }
        } catch (Throwable) {
            return [];
        }

        return [];
    }
}
