<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecReportModuleSelector;
use PHPUnit\Framework\TestCase;

final class RiasecReportModuleSelectorTest extends TestCase
{
    public function test_normal_clear_60q_exposes_standard_modules_without_context_cards(): void
    {
        $policy = (new RiasecReportModuleSelector)->build($this->projection(
            qualityState: 'normal',
            profileShape: 'clear_code',
            formCode: 'riasec_60',
        ));

        $this->assertSame('riasec.module_visibility_policy.v1', $policy['schema_version']);
        $this->assertSame(RiasecReportModuleSelector::POLICY_ID, $policy['policy_id']);
        $this->assertSame('visible', $this->moduleVisibility($policy, 'hero_activity_chain'));
        $this->assertSame('visible', $this->moduleVisibility($policy, 'activity_explorer'));
        $this->assertSame('collapsed', $this->moduleVisibility($policy, 'occupation_examples'));
        $this->assertSame('visible', $this->moduleVisibility($policy, '140q_cta'));
        $this->assertSame('hidden', $this->moduleVisibility($policy, '140q_context_cards'));
        $this->assertFalse($policy['fallback_policy']['frontend_inference_allowed']);
        $this->assertNoForbiddenClaims($policy);
    }

    public function test_low_quality_hides_strong_modules_and_140q_cta(): void
    {
        $policy = (new RiasecReportModuleSelector)->build($this->projection(
            qualityState: 'low_quality',
            profileShape: 'low_quality',
            formCode: 'riasec_140',
        ));

        foreach (['hero_activity_chain', 'pair_blend', 'activity_explorer', 'occupation_examples', '140q_cta', '140q_context_cards'] as $module) {
            $this->assertSame('hidden', $this->moduleVisibility($policy, $module));
        }
        $this->assertSame('visible', $this->moduleVisibility($policy, 'six_dimension_map'));
        $this->assertSame('collapsed', $this->moduleVisibility($policy, 'share_card'));
        $this->assertNoForbiddenClaims($policy);
    }

    public function test_near_tie_routes_to_candidate_chain_without_fixed_identity(): void
    {
        $policy = (new RiasecReportModuleSelector)->build($this->projection(
            qualityState: 'normal',
            profileShape: 'near_tie',
            formCode: 'riasec_60',
        ));

        $this->assertSame('collapsed', $this->moduleVisibility($policy, 'hero_activity_chain'));
        $this->assertSame('visible', $this->moduleVisibility($policy, 'pair_blend'));
        $this->assertSame('near_tie_candidate_chains_first', $this->moduleReason($policy, 'hero_activity_chain'));
        $this->assertNoForbiddenClaims($policy);
    }

    /**
     * @return array<string,mixed>
     */
    private function projection(string $qualityState, string $profileShape, string $formCode): array
    {
        return [
            'quality' => ['quality_state' => $qualityState],
            'interpretation_state' => ['profile_shape' => $profileShape],
            'form' => ['form_code' => $formCode],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function moduleVisibility(array $policy, string $key): string
    {
        return (string) ($this->module($policy, $key)['visibility'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function moduleReason(array $policy, string $key): string
    {
        return (string) ($this->module($policy, $key)['reason'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,string>
     */
    private function module(array $policy, string $key): array
    {
        foreach ((array) ($policy['modules'] ?? []) as $module) {
            if (is_array($module) && ($module['key'] ?? null) === $key) {
                return $module;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoForbiddenClaims(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['Matches', 'career match', 'occupation match', 'job fit', 'fit score', 'success prediction', 'more accurate', '更准确', 'raw delta'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $json);
        }
    }
}
