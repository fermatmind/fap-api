<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecContentRegistrySlotContract;
use PHPUnit\Framework\TestCase;

final class RiasecContentRegistrySlotContractTest extends TestCase
{
    public function test_schema_defines_readiness_slots_without_public_copy_runtime(): void
    {
        $schema = (new RiasecContentRegistrySlotContract)->schema();

        $this->assertSame(RiasecContentRegistrySlotContract::SCHEMA_VERSION, $schema['schema_version']);
        $this->assertSame('readiness_contract_only', $schema['slot_status']);
        $this->assertFalse($schema['runtime_public_copy_included']);
        $this->assertFalse($schema['frontend_fallback_allowed']);
        $this->assertSame('omit_module_fail_closed', $schema['missing_content_policy']);
        $this->assertContains('pair_blend_copy', array_column($schema['slots'], 'slot'));
        $this->assertContains('low_quality_copy', array_column($schema['slots'], 'slot'));
        $this->assertContains('140q_task_card_copy', array_column($schema['slots'], 'slot'));
        $this->assertNoForbiddenClaims($schema);
    }

    public function test_validator_accepts_supported_slot_metadata(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate([
            'slot' => 'pair_blend_copy',
            'version' => 'v0.1-draft',
            'locale' => 'zh-CN',
            'owner' => 'content',
            'evidence_level' => 'theory_based',
            'status' => 'draft',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function test_validator_rejects_unknown_slots_and_forbidden_claim_fields(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate([
            'slot' => 'career_recommendation_copy',
            'version' => 'v0.1-draft',
            'locale' => 'zh-CN',
            'owner' => 'content',
            'evidence_level' => 'content_example',
            'status' => 'draft',
            'career_match' => true,
            'source_url' => 'https://example.test/fake-source',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('unsupported_slot', $result['errors']);
        $this->assertContains('forbidden_field_career_match', $result['errors']);
        $this->assertContains('forbidden_field_source_url', $result['errors']);
    }

    public function test_validator_fails_closed_when_required_metadata_is_missing(): void
    {
        $result = (new RiasecContentRegistrySlotContract)->validate([
            'slot' => 'low_quality_copy',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('missing_version', $result['errors']);
        $this->assertContains('missing_locale', $result['errors']);
        $this->assertContains('missing_owner', $result['errors']);
        $this->assertContains('missing_evidence_level', $result['errors']);
        $this->assertContains('missing_status', $result['errors']);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoForbiddenClaims(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['Matches', 'job fit', 'fit score', 'success prediction', 'more accurate', '更准确', 'raw delta'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $json);
        }
    }
}
