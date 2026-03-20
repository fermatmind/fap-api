<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Tests\TestCase;

final class MbtiContentGovernanceSnapshotRegressionTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function readCompiled(string $file): array
    {
        $path = base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/compiled/'.$file);
        $raw = file_get_contents($path);
        $this->assertIsString($raw, "Unable to read compiled artifact {$file}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Invalid JSON in compiled artifact {$file}");

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function readFixture(): array
    {
        $path = base_path('tests/Fixtures/mbti_content_governance_snapshot_expected.json');
        $raw = file_get_contents($path);
        $this->assertIsString($raw, 'missing governance snapshot fixture');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'invalid governance snapshot fixture');

        return $decoded;
    }

    public function test_compiled_governance_snapshot_matches_expected_contract(): void
    {
        $this->artisan('content:lint --pack=MBTI.cn-mainland.zh-CN.v0.3')->assertExitCode(0);
        $this->artisan('content:compile --pack=MBTI.cn-mainland.zh-CN.v0.3')->assertExitCode(0);

        $compiled = $this->readCompiled('governance.spec.json');
        $compiledManifest = $this->readCompiled('manifest.json');
        $filePolicies = is_array($compiled['file_policies'] ?? null) ? $compiled['file_policies'] : [];

        $actual = [
            'required_layers' => $compiled['required_layers'] ?? [],
            'block_kind_index' => data_get($compiled, 'taxonomy.block_kind_index', []),
            'tier_policies' => $compiled['tier_policies'] ?? [],
            'locale_guardrails' => $compiled['locale_guardrails'] ?? [],
            'snapshot_fixtures' => $compiled['snapshot_fixtures'] ?? [],
            'file_policies' => [
                'report_dynamic_sections.json' => $filePolicies['report_dynamic_sections.json'] ?? [],
                'report_recommended_reads.json' => $filePolicies['report_recommended_reads.json'] ?? [],
                'report_cards_growth.json' => $filePolicies['report_cards_growth.json'] ?? [],
                'report_select_rules.json' => $filePolicies['report_select_rules.json'] ?? [],
            ],
        ];

        $this->assertSame($this->readFixture(), $actual);
        $this->assertContains('governance.spec.json', (array) ($compiledManifest['files'] ?? []));
    }
}
