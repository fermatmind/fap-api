<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IqBeta50BankSpecTest extends TestCase
{
    private function bankDir(): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_BETA_50_ORIGINAL');
    }

    private function readManifest(): array
    {
        $payload = json_decode((string) file_get_contents($this->bankDir().'/manifest.json'), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    #[Test]
    public function manifest_defines_beta50_as_generated_future_bank_only(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame('IQ_BETA_50_ORIGINAL', $manifest['bank_id']);
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', $manifest['scale_code']);
        $this->assertSame('generated_formal_original_pending_norms', $manifest['status']);
        $this->assertFalse($manifest['runtime_bound']);
        $this->assertFalse($manifest['public_take_enabled']);
        $this->assertSame(50, $manifest['item_count_target']);
        $this->assertSame(50, $manifest['item_count_imported']);
        $this->assertSame(['VSPR' => 22, 'VSI' => 16, 'NPR' => 12], $manifest['dimension_targets']);
    }

    #[Test]
    public function launch_gates_block_runtime_use_until_items_scoring_and_norms_exist(): void
    {
        $manifest = $this->readManifest();

        foreach ([
            'norm_authority_required',
            'copyright_gate_required',
            'ambiguity_gate_required',
            'provenance_gate_required',
        ] as $gate) {
            $this->assertTrue((bool) $manifest['launch_gates'][$gate], $gate.' should remain required');
        }

        foreach ([
            'items_import_required',
            'answer_key_required',
            'scoring_spec_required',
        ] as $gate) {
            $this->assertFalse((bool) $manifest['launch_gates'][$gate], $gate.' should be satisfied by generated bank artifacts');
        }

        $this->assertFalse($manifest['public_payload_policy']['may_emit_items']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_answer_key']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_solution_rule']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_generator_metadata']);
        $this->assertFalse($manifest['norm_policy']['iq_claims_enabled']);
        $this->assertFalse($manifest['norm_policy']['percentile_claims_enabled']);
    }

    #[Test]
    public function beta50_bank_imports_generated_assets_but_remains_runtime_disabled(): void
    {
        $this->assertFileExists($this->bankDir().'/manifest.json');
        $this->assertFileExists($this->bankDir().'/items.json');
        $this->assertFileExists($this->bankDir().'/answer_key.json');
        $this->assertFileExists($this->bankDir().'/scoring_spec.json');

        $items = json_decode((string) file_get_contents($this->bankDir().'/items.json'), true);
        $answerKey = json_decode((string) file_get_contents($this->bankDir().'/answer_key.json'), true);
        $scoring = json_decode((string) file_get_contents($this->bankDir().'/scoring_spec.json'), true);

        $this->assertIsArray($items);
        $this->assertIsArray($answerKey);
        $this->assertIsArray($scoring);
        $this->assertSame(50, $items['item_count']);
        $this->assertCount(50, $items['items']);
        $this->assertFalse($answerKey['public_payload']);
        $this->assertSame('backend_only_never_emit_to_public_api', $answerKey['storage_policy']);
        $this->assertFalse($scoring['runtime_binding']['enabled']);
    }
}
