<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Tests\TestCase;

final class SeoPlatform11BAuthorityBoundaryTest extends TestCase
{
    public function test_11a_freeze_and_zero_runtime_authority_are_unchanged(): void
    {
        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        $this->assertSame('b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791', $registry['registry_hash']);
        $this->assertCount(9, $registry['roles']);
        $this->assertCount(20, $registry['capabilities']);
        $this->assertFalse($registry['global_guards']['model_invocation_enabled']);
        $this->assertFalse($registry['global_guards']['fap_web_agent_authority']);

        $files = glob(app_path('Services/SeoAgentEvidence/**/*.php')) ?: [];
        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
        foreach (['OpenAI', 'search_submission_allowed = true', 'agent_write_permission = true'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function test_v1_contract_bytes_remain_historical_while_v2_manifest_is_active(): void
    {
        $historical = [
            'resources/seo-agent/evidence/policies/seo-context-minimization.v1.json' => '6b4a87cbe012cb11bf1d0b18e7a43783f5e3f2b1821071674f278e4fa17467bf',
            'resources/seo-agent/evidence/policies/seo-evidence-retention.v1.json' => '77643acfabb9be1ab7b0727cc5241edb72a4385f6856de7e60b4cd2aeb25d9d2',
            'resources/seo-agent/evidence/policies/seo-external-content-gateway.v1.json' => 'c0cba49d85fbee7e7b3226d98e9c4183f336b1b5139a38b02ec61330635d521b',
            'resources/seo-agent/evidence/policies/seo-private-negative-set.v1.json' => 'b93759a58700e32f9fe9aa189dbbf6f212271a6aab9e1d0e91420f8dddcbb2a8',
            'resources/seo-agent/evidence/policies/seo-query-privacy.v1.json' => '1733a92e94e8072a59792e4a3b4a13306a21ffd8be10dfac897672b73de9c0dd',
            'docs/seo/generated/seo-agent-evidence-contract-manifest.v1.json' => 'f152bd122e0a3ec289483d052fc3e5d0d580c05e8f6f3e2e89e05ac02b54b819',
        ];
        foreach ($historical as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', base_path($path)), $path);
        }

        $active = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('seo.evidence_contract_manifest.v2', $active['schema_version']);
        $this->assertSame('2.0.0', $active['manifest_version']);
        $this->assertCount(9, $active['contracts']);
        $this->assertSame(5, count(array_filter($active['contracts'], static fn (array $contract): bool => $contract['version'] === '2.0.0')));
    }
}
