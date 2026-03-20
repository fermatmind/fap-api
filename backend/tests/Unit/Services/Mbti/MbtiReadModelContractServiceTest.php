<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Mbti\MbtiReadModelContractService;
use Tests\TestCase;

final class MbtiReadModelContractServiceTest extends TestCase
{
    public function test_build_contract_exposes_canonical_overlay_and_cache_boundaries(): void
    {
        $service = app(MbtiReadModelContractService::class);

        $contract = $service->buildContract([
            'schema_version' => 'mbti.personalization.phase8c.v1',
            'identity' => 'A',
            'variant_keys' => ['overview' => 'overview:clear'],
            'scene_fingerprint' => ['work' => ['style_key' => 'work.primary.EI.E.clear']],
            'user_state' => ['is_first_view' => true],
            'orchestration' => ['primary_focus_key' => 'growth.next_actions'],
            'continuity' => ['carryover_focus_key' => 'growth.next_actions'],
            'ordered_recommendation_keys' => ['read-action'],
            'reading_focus_key' => 'read-action',
        ]);

        $this->assertSame('mbti.read_contract.v1', $contract['version']);
        $this->assertContains('identity', $contract['canonical_read_model']['personalization_fields']);
        $this->assertContains('variant_keys', $contract['canonical_read_model']['personalization_fields']);
        $this->assertNotContains('user_state', $contract['canonical_read_model']['personalization_fields']);
        $this->assertSame(
            [
                'user_state',
                'orchestration',
                'ordered_recommendation_keys',
                'ordered_action_keys',
                'recommendation_priority_keys',
                'action_priority_keys',
                'reading_focus_key',
                'action_focus_key',
                'continuity',
            ],
            $contract['overlay_patch']['personalization_fields']
        );
        $this->assertContains('report.sections', $contract['cacheable_fields']);
        $this->assertContains('report._meta.personalization.user_state', $contract['non_cacheable_fields']);
        $this->assertContains('mbti_public_projection_v1._meta.personalization.continuity', $contract['non_cacheable_fields']);
        $this->assertContains('user_state', $contract['telemetry_parity_fields']);
        $this->assertContains('continuity.carryover_focus_key', $contract['telemetry_parity_fields']);
    }

    public function test_apply_overlay_patch_preserves_canonical_fields_and_replaces_only_overlay_fields(): void
    {
        $service = app(MbtiReadModelContractService::class);

        $canonical = [
            'identity' => 'A',
            'action_plan_summary' => 'Canonical summary',
            'user_state' => ['is_first_view' => true],
            'orchestration' => ['primary_focus_key' => 'growth.next_actions'],
            'continuity' => ['carryover_focus_key' => 'growth.next_actions'],
            'ordered_recommendation_keys' => ['read-action'],
        ];

        $effective = [
            'identity' => 'T',
            'action_plan_summary' => 'Overlay must not rewrite canonical text',
            'user_state' => ['is_first_view' => false, 'is_revisit' => true],
            'orchestration' => ['primary_focus_key' => 'traits.close_call_axes'],
            'continuity' => ['carryover_focus_key' => 'traits.close_call_axes'],
            'ordered_recommendation_keys' => ['read-explain'],
        ];

        $merged = $service->applyOverlayPatch($canonical, $effective);

        $this->assertSame('A', $merged['identity']);
        $this->assertSame('Canonical summary', $merged['action_plan_summary']);
        $this->assertSame(['is_first_view' => false, 'is_revisit' => true], $merged['user_state']);
        $this->assertSame(['primary_focus_key' => 'traits.close_call_axes'], $merged['orchestration']);
        $this->assertSame(['carryover_focus_key' => 'traits.close_call_axes'], $merged['continuity']);
        $this->assertSame(['read-explain'], $merged['ordered_recommendation_keys']);
        $this->assertSame('mbti.read_contract.v1', data_get($merged, 'read_contract_v1.version'));
    }
}
