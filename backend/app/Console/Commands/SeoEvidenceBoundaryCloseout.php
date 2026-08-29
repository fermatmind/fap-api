<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Context\SeoEvidenceContextBuilder;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentEvidence\Dependency\SeoEvidenceDependencySnapshotBuilder;
use App\Services\SeoAgentEvidence\Dependency\SeoEvidenceDependencySnapshotVerifier;
use App\Services\SeoAgentEvidence\External\ExternalContentGateway;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoAgentEvidence\Sources\SeoPlatformDependencyEvidenceAdapter;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
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
        PageFamilyPolicyRegistry $pageFamilies,
        SeoPrivateRouteNegativeSet $negativeSet,
        SeoPrivateDataScanner $privateScanner,
        SeoEvidenceBundleFactory $bundleFactory,
        SeoEvidenceBundleVerifier $bundleVerifier,
        SeoEvidenceContextBuilder $contextBuilder,
    ): int {
        try {
            $expectedSha = strtolower(trim((string) $this->option('expected-sha')));
            $releaseSha = $this->releaseSha();
            if (preg_match('/^[a-f0-9]{40}$/', $expectedSha) !== 1 || ! hash_equals($expectedSha, $releaseSha)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'RELEASE_SHA_MISMATCH'], self::FAILURE);
            }
            $manifest = $contracts->manifest();
            $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json')), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($artifact) || ! $contracts->verify($artifact)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'CONTRACT_MANIFEST_INVALID'], self::FAILURE);
            }
            $roleRegistry = $registry->registry();
            if (($roleRegistry['registry_hash'] ?? null) !== 'b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791'
                || count((array) ($roleRegistry['roles'] ?? [])) !== 9 || count((array) ($roleRegistry['capabilities'] ?? [])) !== 20) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'REGISTRY_FREEZE_INVALID'], self::FAILURE);
            }
            $privateRouteProbes = $this->privateRouteProbes($pageFamilies, $negativeSet);
            $piiEvasionProbes = $this->piiEvasionProbes($privateScanner);
            $invalidContextScope = $this->invalidContextScope($roleRegistry, $contextBuilder);
            $metadataPrivacyProbes = $this->metadataPrivacyProbes($bundleFactory, $bundleVerifier, $contextBuilder, $hasher);
            $gatewayChecks = ExternalContentGateway::privacySelfCheck();
            if ($privateRouteProbes !== ['total' => 36, 'rejected' => 36, 'bypass' => 0]
                || ($piiEvasionProbes['bypass'] ?? null) !== 0
                || ($invalidContextScope['ready'] ?? null) !== 0
                || $metadataPrivacyProbes !== [
                    'total' => 52,
                    'factory' => ['total' => 19, 'rejected' => 19, 'bypass' => 0],
                    'verifier' => ['total' => 27, 'rejected' => 27, 'bypass' => 0],
                    'context_builder' => ['total' => 6, 'held' => 6, 'fully_sanitized' => 6, 'bypass' => 0],
                ]
                || in_array('fail', $gatewayChecks, true)) {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'PRIVACY_GATEWAY_SELF_CHECK_FAILED'], self::FAILURE);
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
                'contract_version' => 'seo.evidence_boundary_closeout.v3',
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
                'query_hmac_dual_write_enabled' => (bool) config('seo_agent_evidence.query_hmac_dual_write_enabled', false),
                'agent_external_egress' => (bool) config('seo_agent_evidence.agent_external_egress', false),
                'allowed_sources_count' => count((array) config('seo_agent_evidence.allowed_sources', [])),
                'read_only_gsc' => (bool) ($roleRegistry['global_guards']['read_only_gsc'] ?? false),
                'search_submission_allowed' => (bool) ($roleRegistry['global_guards']['search_submission_allowed'] ?? true),
                'post12_agent_write_enabled' => (bool) ($roleRegistry['global_guards']['post12_agent_write_enabled'] ?? true),
                'l4_state' => (string) ($roleRegistry['global_guards']['l4_state'] ?? 'unknown'),
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'business_writes' => 0,
                'self_checks' => [
                    'private_route_probes' => $privateRouteProbes,
                    'pii_evasion_probes' => $piiEvasionProbes,
                    'invalid_context_scope' => $invalidContextScope,
                    'metadata_privacy_probes' => $metadataPrivacyProbes,
                    'gateway' => $gatewayChecks,
                ],
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
            if ($receipt['bundle_write_enabled'] || $receipt['context_build_enabled'] || $receipt['external_fetch_enabled']
                || $receipt['retention_delete_enabled'] || $receipt['query_hmac_dual_write_enabled']
                || $receipt['agent_external_egress'] || $receipt['allowed_sources_count'] !== 0
                || ! $receipt['read_only_gsc'] || $receipt['search_submission_allowed']
                || $receipt['post12_agent_write_enabled'] || $receipt['l4_state'] !== 'dormant_not_authorized') {
                return $this->emit(['status' => 'failed', 'safe_error_code' => 'PRODUCTION_GATE_ENABLED'], self::FAILURE);
            }
            $receipt['receipt_hash'] = $hasher->hash($receipt);

            return $this->emit($receipt, self::SUCCESS);
        } catch (Throwable) {
            return $this->emit(['status' => 'failed', 'safe_error_code' => 'SEO_EVIDENCE_CLOSEOUT_FAILED'], self::FAILURE);
        }
    }

    /** @return array{total:int,rejected:int,bypass:int} */
    private function privateRouteProbes(PageFamilyPolicyRegistry $pageFamilies, SeoPrivateRouteNegativeSet $negativeSet): array
    {
        $probes = $pageFamilies->negativeSetProbes();
        $rejected = 0;
        foreach ($probes as $probe) {
            if ($negativeSet->classify(
                (string) ($probe['canonical_path'] ?? ''),
                null,
                (string) ($probe['page_entity_type'] ?? ''),
            )['private']) {
                $rejected++;
            }
        }

        return ['total' => count($probes), 'rejected' => $rejected, 'bypass' => count($probes) - $rejected];
    }

    /** @return array{total:int,rejected:int,bypass:int} */
    private function piiEvasionProbes(SeoPrivateDataScanner $scanner): array
    {
        $probes = [
            ['userId' => 42],
            ['accessToken' => 'opaque-secret-value'],
            ['emailAddress' => 'person@example.com'],
            ['payment-id' => 4111111111111111],
            ['profile.user.id' => 42],
            ['nested' => ['accountRecovery' => 'value']],
            ['nested' => [['phone' => 13800138000]]],
            ['ｕｓｅｒ＿ｉｄ' => 42],
            ['reportPrivate' => true],
            ['history_ref' => 'history_12345'],
            (object) ['public' => true],
        ];
        $rejected = count(array_filter($probes, static fn (mixed $probe): bool => $scanner->scan($probe)['private_data_present']));

        return ['total' => count($probes), 'rejected' => $rejected, 'bypass' => count($probes) - $rejected];
    }

    /**
     * @return array{
     *     total:int,
     *     factory:array{total:int,rejected:int,bypass:int},
     *     verifier:array{total:int,rejected:int,bypass:int},
     *     context_builder:array{total:int,held:int,fully_sanitized:int,bypass:int}
     * }
     */
    private function metadataPrivacyProbes(
        SeoEvidenceBundleFactory $factory,
        SeoEvidenceBundleVerifier $verifier,
        SeoEvidenceContextBuilder $contextBuilder,
        SeoEvidenceCanonicalHasher $hasher,
    ): array {
        $factoryInput = $this->safeBundleInput();
        $factoryFields = array_values(array_diff(array_keys($factoryInput), ['payload']));
        $factoryRejected = 0;
        foreach ($factoryFields as $index => $field) {
            $mutated = $factoryInput;
            $probe = $this->privateProbe($index);
            $mutated[$field] = $field === 'lineage_refs' ? ['nested' => ['value' => $probe]] : $probe;
            try {
                $factory->create($mutated);
            } catch (InvalidArgumentException $exception) {
                if ($exception->getMessage() === 'SEO_EVIDENCE_PRIVATE_DATA') {
                    $factoryRejected++;
                }
            }
        }

        $safeBundle = $factory->create($factoryInput);
        $verifierFields = array_values(array_diff(array_keys($safeBundle), ['payload']));
        $verifierRejected = 0;
        foreach ($verifierFields as $index => $field) {
            $mutated = $safeBundle;
            $probe = $this->privateProbe($index);
            $mutated[$field] = in_array($field, ['redaction_summary', 'lineage_refs'], true)
                ? ['nested' => ['value' => $probe]]
                : $probe;
            if ($field !== 'bundle_hash') {
                $mutated['bundle_hash'] = $hasher->hashWithout($mutated, 'bundle_hash');
            }
            if ($verifier->verify($mutated) === ['valid' => false, 'code' => 'PRIVATE_DATA_PRESENT']) {
                $verifierRejected++;
            }
        }

        $contextArguments = [
            'mission_id' => 'mission:closeout',
            'mission_type' => 'bounded_review',
            'role_id' => 'seo.expert.search_analytics_measurement',
            'page_family' => 'tests',
            'locale' => 'zh-CN',
        ];
        $contextHeld = 0;
        $contextSanitized = 0;
        $contextPassed = 0;
        foreach (array_keys($contextArguments) as $index => $field) {
            $probe = $this->privateProbe($index);
            $mutated = $contextArguments;
            $mutated[$field] = $probe;
            $buildArguments = [...array_values($mutated), [$safeBundle]];
            $context = $contextBuilder->build(...$buildArguments);
            $held = ($context['status'] ?? null) === 'EVIDENCE_HOLD';
            $sanitized = $this->isFullySanitizedContext($context, $probe);
            $contextHeld += (int) $held;
            $contextSanitized += (int) $sanitized;
            $contextPassed += (int) ($held && $sanitized);
        }

        $bundleProbe = $this->privateProbe(count($contextArguments));
        $maliciousBundle = $safeBundle;
        $maliciousBundle['payload']['query_hmac'] = $bundleProbe;
        $maliciousBundle['content_hash'] = $hasher->hash($maliciousBundle['payload']);
        $maliciousBundle['bundle_hash'] = $hasher->hashWithout($maliciousBundle, 'bundle_hash');
        $bundleBuildArguments = [...array_values($contextArguments), [$maliciousBundle]];
        $bundleContext = $contextBuilder->build(...$bundleBuildArguments);
        $bundleHeld = ($bundleContext['status'] ?? null) === 'EVIDENCE_HOLD';
        $bundleSanitized = $this->isFullySanitizedContext($bundleContext, $bundleProbe);
        $contextHeld += (int) $bundleHeld;
        $contextSanitized += (int) $bundleSanitized;
        $contextPassed += (int) ($bundleHeld && $bundleSanitized);

        $factoryTotal = count($factoryFields);
        $verifierTotal = count($verifierFields);
        $contextTotal = count($contextArguments) + 1;

        return [
            'total' => $factoryTotal + $verifierTotal + $contextTotal,
            'factory' => ['total' => $factoryTotal, 'rejected' => $factoryRejected, 'bypass' => $factoryTotal - $factoryRejected],
            'verifier' => ['total' => $verifierTotal, 'rejected' => $verifierRejected, 'bypass' => $verifierTotal - $verifierRejected],
            'context_builder' => [
                'total' => $contextTotal,
                'held' => $contextHeld,
                'fully_sanitized' => $contextSanitized,
                'bypass' => $contextTotal - $contextPassed,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function safeBundleInput(): array
    {
        return [
            'bundle_id' => 'bundle:closeout',
            'bundle_version' => 1,
            'mission_id' => 'mission:closeout',
            'source_type' => 'gsc_aggregate',
            'source_ref' => str_repeat('d', 64),
            'authority_type' => 'gsc_measurement',
            'captured_at' => '2026-08-29T00:00:00Z',
            'evidence_state' => 'verified',
            'freshness_state' => 'fresh',
            'source_capability_state' => 'available',
            'retention_class' => 'first_party_aggregate',
            'page_family' => 'tests',
            'locale' => 'zh-CN',
            'authority_revision' => 'revision:closeout',
            'injection_scan_result' => 'pass',
            'source_license_class' => 'first_party',
            'data_usage_purpose' => 'search_measurement',
            'egress_decision' => 'not_required',
            'lineage_refs' => [],
            'payload' => [
                'query_hmac' => str_repeat('e', 64),
                'query_hmac_key_version' => 'k1',
                'clicks' => 10,
                'impressions' => 100,
            ],
        ];
    }

    private function privateProbe(int $index): string
    {
        return match ($index % 3) {
            0 => 'privacy-probe@example.com',
            1 => 'sk-live-privacyprobe12345678',
            default => 'attempt_id_privacyprobe1234',
        };
    }

    /** @param array<string, mixed> $context */
    private function isFullySanitizedContext(array $context, string $probe): bool
    {
        $serialized = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ($context['mission_id'] ?? null) === 'mission:held'
            && ($context['mission_type'] ?? null) === 'mission:held'
            && ($context['role_id'] ?? null) === 'role:held'
            && ($context['page_family'] ?? null) === 'page_family:held'
            && ($context['locale'] ?? null) === 'und'
            && ($context['payload'] ?? null) === []
            && ($context['bundle_refs'] ?? null) === []
            && ($context['source_capability_states'] ?? null) === []
            && preg_match('/^[a-f0-9]{64}$/', (string) ($context['context_id'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/', (string) ($context['context_hash'] ?? '')) === 1
            && ! str_contains($serialized, $probe);
    }

    /** @param array<string, mixed> $registry @return array{total:int,ready:int} */
    private function invalidContextScope(array $registry, SeoEvidenceContextBuilder $builder): array
    {
        $total = 0;
        $ready = 0;
        foreach ((array) ($registry['roles'] ?? []) as $role) {
            $mission = (string) (($role['allowed_missions'][0] ?? 'invalid'));
            $family = (string) (($role['page_family_scope'][0] ?? 'other_public'));
            $locale = (string) (($role['locale_scope'][0] ?? 'en'));
            foreach ([
                ['invalid_mission', $family, $locale],
                [$mission, 'private_excluded', $locale],
                [$mission, $family, 'fr'],
            ] as [$invalidMission, $invalidFamily, $invalidLocale]) {
                $total++;
                if ($builder->scopeDecision((string) $invalidMission, (string) ($role['role_id'] ?? ''), (string) $invalidFamily, (string) $invalidLocale) === 'READY') {
                    $ready++;
                }
            }
        }

        return ['total' => $total, 'ready' => $ready];
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
