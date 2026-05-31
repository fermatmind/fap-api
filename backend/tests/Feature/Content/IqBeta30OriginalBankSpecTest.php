<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IqBeta30OriginalBankSpecTest extends TestCase
{
    private function bankDir(): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_BETA_30_ORIGINAL');
    }

    private function readJson(string $file): array
    {
        $payload = json_decode((string) file_get_contents($this->bankDir() . '/' . $file), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    #[Test]
    public function manifest_defines_generated_original_beta30_bank_without_runtime_binding(): void
    {
        $manifest = $this->readJson('manifest.json');

        $this->assertSame('IQ_BETA_30_ORIGINAL', $manifest['bank_id']);
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', $manifest['scale_code']);
        $this->assertSame('beta_internal_validation', $manifest['status']);
        $this->assertFalse($manifest['runtime_bound']);
        $this->assertSame(30, $manifest['item_count']);
        $this->assertSame(6, $manifest['option_count']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $manifest['option_codes']);
        $this->assertSame(['VSPR' => 14, 'VSI' => 10, 'NPR' => 6], $manifest['dimension_targets']);
        $this->assertSame([
            'matrix_3x3' => 10,
            'matrix_2x2' => 4,
            'series' => 4,
            'odd_one_out' => 4,
            'rotation' => 3,
            'overlay' => 3,
            'numeric_pattern' => 2,
        ], $manifest['item_family_targets']);
    }

    #[Test]
    public function copyright_and_public_payload_boundaries_are_explicit(): void
    {
        $manifest = $this->readJson('manifest.json');

        $this->assertSame('repo_generated_original', $manifest['copyright_policy']['source']);
        $this->assertFalse($manifest['copyright_policy']['copied_from_third_party']);
        $this->assertFalse($manifest['copyright_policy']['traced_from_third_party']);
        $this->assertFalse($manifest['copyright_policy']['third_party_license_required']);
        $this->assertTrue($manifest['copyright_policy']['myiq_science_requires_license_verification_gate_before_use']);
        $this->assertTrue($manifest['public_payload_policy']['may_emit_items']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_answer_key']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_solution_rule']);
    }

    #[Test]
    public function norm_claims_remain_disabled_before_population_validation(): void
    {
        $manifest = $this->readJson('manifest.json');
        $scoring = $this->readJson('scoring_spec.json');

        $this->assertFalse($manifest['norm_policy']['iq_claims_enabled']);
        $this->assertFalse($manifest['norm_policy']['percentile_claims_enabled']);
        $this->assertTrue($manifest['norm_policy']['population_norm_table_required_before_production']);
        $this->assertFalse($scoring['norm_policy']['iq_claims_enabled']);
        $this->assertFalse($scoring['runtime_binding']['enabled']);
    }

    #[Test]
    public function generated_items_and_answer_key_files_exist_but_answer_key_is_backend_only(): void
    {
        $items = $this->readJson('items.json');
        $answerKey = $this->readJson('answer_key.json');

        $this->assertSame(30, $items['item_count']);
        $this->assertCount(30, $items['items']);
        $this->assertFalse($answerKey['public_payload']);
        $this->assertSame('backend_only_never_emit_to_public_api', $answerKey['storage_policy']);
        $this->assertCount(30, $answerKey['answers']);
    }
}
