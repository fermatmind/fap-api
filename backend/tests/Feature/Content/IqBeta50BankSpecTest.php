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
    public function manifest_defines_beta50_as_future_placeholder_only(): void
    {
        $manifest = $this->readManifest();

        $this->assertSame('IQ_BETA_50_ORIGINAL', $manifest['bank_id']);
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', $manifest['scale_code']);
        $this->assertSame('future_placeholder_spec_only', $manifest['status']);
        $this->assertFalse($manifest['runtime_bound']);
        $this->assertFalse($manifest['public_take_enabled']);
        $this->assertSame(50, $manifest['item_count_target']);
        $this->assertSame(0, $manifest['item_count_imported']);
        $this->assertSame(['VSPR' => 22, 'VSI' => 16, 'NPR' => 12], $manifest['dimension_targets']);
    }

    #[Test]
    public function launch_gates_block_runtime_use_until_items_scoring_and_norms_exist(): void
    {
        $manifest = $this->readManifest();

        foreach ([
            'items_import_required',
            'answer_key_required',
            'scoring_spec_required',
            'norm_authority_required',
            'copyright_gate_required',
            'ambiguity_gate_required',
            'provenance_gate_required',
        ] as $gate) {
            $this->assertTrue((bool) $manifest['launch_gates'][$gate], $gate.' should remain required');
        }

        $this->assertFalse($manifest['public_payload_policy']['may_emit_items']);
        $this->assertFalse($manifest['public_payload_policy']['may_emit_answer_key']);
        $this->assertFalse($manifest['norm_policy']['iq_claims_enabled']);
        $this->assertFalse($manifest['norm_policy']['percentile_claims_enabled']);
    }

    #[Test]
    public function beta50_pr_does_not_import_runtime_items_answer_key_or_scoring_spec(): void
    {
        $this->assertFileExists($this->bankDir().'/manifest.json');
        $this->assertFileDoesNotExist($this->bankDir().'/items.json');
        $this->assertFileDoesNotExist($this->bankDir().'/answer_key.json');
        $this->assertFileDoesNotExist($this->bankDir().'/scoring_spec.json');
    }
}
