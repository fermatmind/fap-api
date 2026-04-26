<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BigFiveResultPageV2ValidatorTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string}>
     */
    public static function validFixtureProvider(): iterable
    {
        yield 'canonical mixed signature' => ['canonical_mixed_signature.payload.json'];
        yield 'norm unavailable' => ['norm_unavailable.payload.json'];
        yield 'low quality' => ['low_quality.payload.json'];
    }

    #[DataProvider('validFixtureProvider')]
    public function test_it_accepts_valid_big5_result_page_v2_fixtures(string $fixture): void
    {
        $payload = $this->loadFixture($fixture);

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertSame([], $errors);
        $this->assertArrayHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame(
            BigFiveResultPageV2Contract::PAYLOAD_KEY,
            $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['payload_key'] ?? null
        );
    }

    public function test_it_rejects_unknown_module_key(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['module_key'] = 'module_99_unknown';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Unknown module_key: module_99_unknown', $errors);
    }

    public function test_it_rejects_duplicate_module_keys_and_empty_modules(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][] = [
            'module_key' => 'module_00_trust_bar',
            'blocks' => [],
        ];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Duplicate module_key: module_00_trust_bar', $errors);
        $this->assertContains('Module module_00_trust_bar must include at least one block', $errors);
    }

    public function test_it_rejects_unknown_block_kind(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['block_kind'] = 'type_card';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Unknown block_kind: type_card', $errors);
    }

    public function test_it_rejects_block_shape_mismatches(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['block_key'] = 'wrong_module.boundary.fixture.v1';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['content'] = 'not-an-array';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['projection_refs'] = 'projection_refs';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['registry_refs'] = 'registry_refs';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_00_trust_bar.blocks.0 block_key must start with module_key', $errors);
        $this->assertContains('module_00_trust_bar.blocks.0 content must be an array', $errors);
        $this->assertContains('module_00_trust_bar.blocks.0 projection_refs must be an array', $errors);
        $this->assertContains('module_00_trust_bar.blocks.0 registry_refs must be an array', $errors);
    }

    public function test_it_rejects_forbidden_internal_public_fields(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['editor_note'] = 'internal only';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['content']['selection_guidance'] = 'internal only';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][0]['blocks'][0]['content']['canonical_type'] = 'type-like';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field big5_result_page_v2.modules.0.blocks.0.editor_note', $errors);
        $this->assertContains('Forbidden public field big5_result_page_v2.modules.0.blocks.0.content.selection_guidance', $errors);
        $this->assertContains('Forbidden public field big5_result_page_v2.modules.0.blocks.0.content.canonical_type', $errors);
    }

    public function test_it_rejects_user_confirmed_type_in_big_five_contract(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['projection_v2']['user_confirmed_type'] = 'O-high';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field big5_result_page_v2.projection_v2.user_confirmed_type', $errors);
    }

    public function test_it_rejects_profile_signature_as_fixed_type(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['projection_v2']['profile_signature']['is_fixed_type'] = true;
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['projection_v2']['profile_signature']['system'] = 'type';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('profile_signature must not be marked as a fixed type', $errors);
        $this->assertContains('profile_signature.system must not be type', $errors);
    }

    public function test_it_rejects_percentile_and_normal_curve_when_norm_unavailable(): void
    {
        $payload = $this->loadFixture('norm_unavailable.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['projection_v2']['domains']['O']['percentile'] = 59;
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][1]['blocks'][0]['content']['show_normal_curve'] = true;

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field projection_v2.domains.O.percentile', $errors);
        $this->assertContains('Forbidden public field module_01_hero.norm_unavailable.fixture.v1.content.show_normal_curve', $errors);
    }

    public function test_it_rejects_shareable_blocks_with_raw_sensitive_scores(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][7]['blocks'][0]['content']['raw_mean'] = 4.2;

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field module_07_collaboration_manual.fixture.v1.content.raw_mean', $errors);
    }

    public function test_it_rejects_facet_reframe_without_supporting_item_count_or_confidence(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][5]['blocks'][0]['content']['facets'][0] = [
            'facet' => 'N1',
            'claim_strength' => 'independent_measurement',
        ];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('facet_reframe facet 0 missing item_count', $errors);
        $this->assertContains('facet_reframe facet 0 missing confidence', $errors);
        $this->assertContains('facet_reframe facet 0 must not claim independent measurement', $errors);
    }

    public function test_it_rejects_non_degraded_modules_for_low_quality_scope(): void
    {
        $payload = $this->loadFixture('low_quality.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][] = [
            'module_key' => 'module_03_trait_deep_dive',
            'blocks' => [
                [
                    'block_key' => 'module_03_trait_deep_dive.low_quality.invalid',
                    'block_kind' => 'trait_deep_dive',
                    'module_key' => 'module_03_trait_deep_dive',
                    'content' => ['summary_zh' => 'invalid fixture'],
                    'projection_refs' => ['domains'],
                    'registry_refs' => ['domain_registry:invalid'],
                    'safety_level' => 'standard',
                    'evidence_level' => 'computed',
                    'shareable' => false,
                    'content_source' => 'fixture',
                ],
            ],
        ];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('low_quality payload must not expose module_03_trait_deep_dive', $errors);
        $this->assertContains('low_quality block module_03_trait_deep_dive.low_quality.invalid must use boundary/degraded safety level', $errors);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadFixture(string $filename): array
    {
        $path = base_path('tests/Fixtures/big5_result_page_v2/'.$filename);
        $json = file_get_contents($path);
        $this->assertIsString($json, "Fixture missing: {$filename}");
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, "Fixture invalid JSON: {$filename}");

        return $decoded;
    }
}
