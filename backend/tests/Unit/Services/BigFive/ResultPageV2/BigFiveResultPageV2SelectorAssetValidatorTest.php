<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetValidator;
use Tests\TestCase;

final class BigFiveResultPageV2SelectorAssetValidatorTest extends TestCase
{
    private BigFiveResultPageV2SelectorAssetValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new BigFiveResultPageV2SelectorAssetValidator;
    }

    public function test_selector_ready_fixtures_parse_and_pass_validator(): void
    {
        $assets = $this->fixtures();

        $this->assertCount(12, $assets);
        $this->assertSame([], $this->validator->validateAssetSet($assets));
    }

    public function test_p0_registry_coverage_has_fixture_coverage(): void
    {
        $registryKeys = array_values(array_unique(array_column($this->fixtures(), 'registry_key')));

        foreach ([
            'state_scope_registry',
            'profile_signature_registry',
            'domain_registry',
            'facet_pattern_registry',
            'coupling_registry',
            'share_safety_registry',
            'boundary_registry',
            'method_registry',
        ] as $requiredP0Registry) {
            $this->assertContains($requiredP0Registry, $registryKeys);
        }
    }

    public function test_matrix_registries_have_minimal_fixture_coverage(): void
    {
        $registryKeys = array_values(array_unique(array_column($this->fixtures(), 'registry_key')));

        foreach (BigFiveResultPageV2SelectorAssetContract::REGISTRY_KEYS as $registryKey) {
            $this->assertContains($registryKey, $registryKeys);
        }
    }

    public function test_missing_trigger_is_rejected(): void
    {
        $asset = $this->fixture('fixture.domain.o_mid_high');
        unset($asset['trigger']);

        $this->assertHasError('trigger must be a non-empty object', $asset);
    }

    public function test_missing_slot_key_is_rejected(): void
    {
        $asset = $this->fixture('fixture.domain.o_mid_high');
        unset($asset['slot_key']);

        $this->assertHasError('selector asset missing slot_key', $asset);
        $this->assertHasError('slot_key must not be empty', $asset);
    }

    public function test_invalid_priority_is_rejected(): void
    {
        $asset = $this->fixture('fixture.domain.o_mid_high');
        $asset['priority'] = 101;

        $this->assertHasError('priority must be an integer from 1 to 100', $asset);
    }

    public function test_invalid_reading_mode_is_rejected(): void
    {
        $asset = $this->fixture('fixture.domain.o_mid_high');
        $asset['reading_modes'][] = 'share_safe';

        $this->assertHasError('reading_mode is invalid: share_safe', $asset);
    }

    public function test_fixed_type_language_is_rejected(): void
    {
        $asset = $this->fixture('fixture.profile_signature.auxiliary_label');
        $asset['trigger']['signature_policy'] = 'fixed_type';

        $this->assertHasError('selector asset contains forbidden phrase: fixed_type', $asset);
    }

    public function test_user_confirmed_type_is_rejected(): void
    {
        $asset = $this->fixture('fixture.observation_feedback.module_response');
        $asset['trigger']['user_confirmed_type'] = '5';

        $this->assertHasError('Forbidden field trigger.user_confirmed_type', $asset);
    }

    public function test_frontend_fallback_is_rejected(): void
    {
        $asset = $this->fixture('fixture.boundary.non_diagnostic');
        $asset['fallback_policy'] = 'frontend_fallback';

        $this->assertHasError('fallback_policy is invalid: frontend_fallback', $asset);
        $this->assertHasError('fallback_policy must not use frontend-authored interpretation fallback', $asset);
    }

    public function test_public_internal_metadata_leak_is_rejected(): void
    {
        $asset = $this->fixture('fixture.domain.o_mid_high');
        $asset['public_payload']['internal_metadata'] = ['leak' => true];

        $this->assertHasError('Forbidden field public_payload.internal_metadata', $asset);
    }

    public function test_shareable_raw_score_is_rejected(): void
    {
        $asset = $this->fixture('fixture.share_safety.safe_quote');
        $asset['public_payload']['raw_score'] = 68;

        $this->assertHasError('Forbidden field public_payload.raw_score', $asset);
    }

    public function test_facet_independent_claim_without_confidence_is_rejected(): void
    {
        $asset = $this->fixture('fixture.facet_pattern.o6_reframe');
        unset($asset['trigger']['facet_support']['confidence']);
        $asset['trigger']['facet_support']['claim_strength'] = 'independent_measurement';

        $this->assertHasError('facet_pattern_registry assets require item_count and confidence or inference_only=true', $asset);
        $this->assertHasError('facet_pattern_registry assets must not claim independent measurement', $asset);
    }

    public function test_norm_unavailable_percentile_is_rejected(): void
    {
        $asset = $this->fixture('fixture.method.norm_unavailable');
        $asset['public_payload']['percentile'] = 88;

        $this->assertHasError('Forbidden field public_payload.percentile', $asset);
    }

    public function test_low_quality_unsafe_asset_is_rejected(): void
    {
        $asset = $this->fixture('fixture.state_scope.low_quality');
        $asset['safety_level'] = 'standard';
        $asset['fallback_policy'] = 'omit_block';

        $this->assertHasError('low_quality selector assets must use boundary/degraded safety level', $asset);
        $this->assertHasError('low_quality selector assets must use backend_required/degrade_to_boundary fallback policy', $asset);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fixtures(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/big5_result_page_v2/selector_ready_minimal_assets.json'));
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $assetKey): array
    {
        foreach ($this->fixtures() as $asset) {
            if (($asset['asset_key'] ?? null) === $assetKey) {
                return $asset;
            }
        }

        $this->fail("Missing fixture asset {$assetKey}");
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function assertHasError(string $expectedError, array $asset): void
    {
        $this->assertContains($expectedError, $this->validator->validate($asset));
    }
}
