<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Tests\TestCase;

final class IqBeta30OriginalBankSpecTest extends TestCase
{
    public function test_beta30_original_bank_manifest_is_planned_and_not_runtime_bound(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame('fm.iq.item_bank_manifest.v1', $manifest['schema_version'] ?? null);
        $this->assertSame('IQ_BETA_30_ORIGINAL', $manifest['bank_id'] ?? null);
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', $manifest['scale_code'] ?? null);
        $this->assertSame('planned_spec_only', $manifest['status'] ?? null);
        $this->assertFalse((bool) ($manifest['runtime_bound'] ?? true));
        $this->assertSame(30, $manifest['item_count_target'] ?? null);
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $manifest['option_codes_target'] ?? null);
        $this->assertSame(20, $manifest['time_limit_minutes_target'] ?? null);
    }

    public function test_beta30_original_bank_dimension_and_family_plan_matches_launch_spec(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame(['VSPR' => 14, 'VSI' => 10, 'NPR' => 6], $manifest['dimension_counts_target'] ?? null);
        $this->assertSame(30, array_sum($manifest['dimension_counts_target'] ?? []));
        $this->assertSame([
            'matrix_3x3' => 10,
            'matrix_2x2' => 4,
            'series' => 4,
            'odd_one_out' => 4,
            'rotation' => 3,
            'overlay' => 3,
            'numeric_pattern' => 2,
        ], $manifest['item_family_counts_target'] ?? null);
        $this->assertSame(30, array_sum($manifest['item_family_counts_target'] ?? []));
    }

    public function test_beta30_original_bank_keeps_copyright_redaction_and_norm_boundaries_explicit(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame('repo_generated', data_get($manifest, 'copyright_policy.source_mode_required'));
        $this->assertFalse((bool) data_get($manifest, 'copyright_policy.third_party_item_copying_allowed', true));
        $this->assertFalse((bool) data_get($manifest, 'copyright_policy.third_party_visual_tracing_allowed', true));
        $this->assertTrue((bool) data_get($manifest, 'copyright_policy.license_verification_required_for_external_items'));

        $this->assertTrue((bool) data_get($manifest, 'public_payload_policy.structured_svg_only'));
        $this->assertFalse((bool) data_get($manifest, 'public_payload_policy.raw_svg_html_allowed', true));
        $this->assertFalse((bool) data_get($manifest, 'public_payload_policy.answer_key_public_allowed', true));
        $this->assertFalse((bool) data_get($manifest, 'public_payload_policy.solution_rule_public_allowed', true));
        $this->assertFalse((bool) data_get($manifest, 'public_payload_policy.frontend_scoring_allowed', true));

        $this->assertTrue((bool) data_get($manifest, 'norm_policy.norm_table_required_before_iq_estimate_claims'));
        $this->assertFalse((bool) data_get($manifest, 'norm_policy.iq_estimate_runtime_authoritative', true));
        $this->assertFalse((bool) data_get($manifest, 'norm_policy.percentile_runtime_authoritative', true));
        $this->assertFalse((bool) data_get($manifest, 'norm_policy.confidence_interval_runtime_authoritative', true));
    }

    public function test_beta30_original_bank_spec_document_records_required_review_gates(): void
    {
        $docPath = base_path('docs/iq/iq-beta-30-original-bank-spec.md');

        $this->assertFileExists($docPath);
        $doc = (string) file_get_contents($docPath);

        foreach ([
            'copyright_gate',
            'technical_svg_gate',
            'answer_key_gate',
            'ambiguity_gate',
            'difficulty_gate',
            'claim_gate',
            'provenance_gate',
            'contract_gate',
        ] as $gate) {
            $this->assertStringContainsString($gate, $doc);
        }

        $this->assertStringContainsString('FermatMind may reproduce item archetypes, not third-party items.', $doc);
        $this->assertStringContainsString('It must not make normed IQ estimate, percentile, or confidence interval production-authoritative', $doc);
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $path = dirname(base_path()).'/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_BETA_30_ORIGINAL/manifest.json';

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($manifest);

        return $manifest;
    }
}
