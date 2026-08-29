<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentEvidence\Dependency\SeoEvidenceDependencySnapshotBuilder;
use App\Services\SeoAgentEvidence\Dependency\SeoEvidenceDependencySnapshotVerifier;
use App\Services\SeoAgentEvidence\Sources\SeoPlatformDependencyEvidenceAdapter;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class SeoEvidenceBoundaryCloseout extends Command
{
    protected $signature = 'seo:evidence-boundary-closeout {--expected-sha=} {--json}';

    protected $description = 'Verify the read-only SEO Evidence boundary for an exact release SHA';

    public function handle(
        SeoEvidenceContractRegistry $contracts,
        SeoEvidenceDependencySnapshotBuilder $builder,
        SeoEvidenceDependencySnapshotVerifier $verifier,
        SeoEvidenceCanonicalHasher $hasher,
        SeoRoleCapabilityRegistry $registry,
        SeoPlatformDependencyEvidenceAdapter $dependencyEvidence,
    ): int {
        try {
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            $releaseSha = $this->releaseSha();
            if (preg_match('/^[a-f0-9]{40}$/', $expectedSha) !== 1 || ! hash_equals($expectedSha, $releaseSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            $manifest = $contracts->manifest();
            $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-agent-evidence-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($artifact) || ! $contracts->verify($artifact)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'CONTRACT_MANIFEST_INVALID'], self::FAILURE);
            }
            $roleRegistry = $registry->registry();
            if (($roleRegistry['registry_hash'] ?? null) !== 'b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791'
                || count((array) ($roleRegistry['roles'] ?? [])) !== 9 || count((array) ($roleRegistry['capabilities'] ?? [])) !== 20) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'REGISTRY_FREEZE_INVALID'], self::FAILURE);
            }
            $dependencies = $dependencyEvidence->snapshot($releaseSha);
            $snapshot = $builder->build($releaseSha, $dependencies, [
                'captured_at' => $this->releaseCapturedAt($releaseSha),
                ...$dependencyEvidence->urlTruthBinding(),
            ]);
            $snapshotVerification = $verifier->verify($snapshot, $expectedSha);
            if (! $snapshotVerification['valid']) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'DEPENDENCY_SNAPSHOT_INVALID'], self::FAILURE);
            }
            $receipt = [
                'contract_version' => 'seo.evidence_boundary_closeout.v1',
                'release_sha' => $releaseSha,
                'registry_hash' => $roleRegistry['registry_hash'],
                'inventory_v3_hash' => $snapshot['inventory_v3_hash'],
                'contract_manifest_hash' => $manifest['manifest_hash'],
                'dependency_snapshot_hash' => $snapshot['snapshot_hash'],
                'dependency_status' => $snapshot['status'],
                'execution_allowed' => false,
                'bundle_write_enabled' => (bool) config('seo_agent_evidence.bundle_write_enabled', false),
                'context_build_enabled' => (bool) config('seo_agent_evidence.context_build_enabled', false),
                'external_fetch_enabled' => (bool) config('seo_agent_evidence.external_fetch_enabled', false),
                'retention_delete_enabled' => (bool) config('seo_agent_evidence.retention_delete_enabled', false),
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'business_writes' => 0,
                'negative_guarantees' => [
                    'raw_query_exposed' => false,
                    'private_data_exposed' => false,
                    'agent_runtime_created' => false,
                    'delegation' => 0,
                    'agent_write_permissions' => 0,
                    'cms_write' => 0,
                    'search_submission' => 0,
                    'url_truth_write' => 0,
                    'production_evidence_rows_created' => 0,
                    'external_http_calls' => 0,
                    'fap_web_agent_authority' => false,
                ],
            ];
            if ($receipt['bundle_write_enabled'] || $receipt['context_build_enabled'] || $receipt['external_fetch_enabled'] || $receipt['retention_delete_enabled']) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'PRODUCTION_GATE_ENABLED'], self::FAILURE);
            }
            $receipt['receipt_hash'] = $hasher->hash($receipt);

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'SEO_EVIDENCE_CLOSEOUT_FAILED'], self::FAILURE);
        }
    }

    private function releaseSha(): string
    {
        $revision = dirname(base_path()).'/REVISION';
        if (is_file($revision)) {
            return strtolower(trim((string) file_get_contents($revision)));
        }
        $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();

        return strtolower(trim($process->getOutput()));
    }

    private function releaseCapturedAt(string $releaseSha): string
    {
        $revision = dirname(base_path()).'/REVISION';
        if (is_file($revision) && is_int(filemtime($revision))) {
            return \Carbon\CarbonImmutable::createFromTimestampUTC((int) filemtime($revision))->format('Y-m-d\TH:i:s\Z');
        }
        $process = new Process(['git', 'show', '-s', '--format=%cI', $releaseSha], dirname(base_path()));
        $process->mustRun();

        return \Carbon\CarbonImmutable::parse(trim($process->getOutput()))->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($encoded);

        return $code;
    }
}
