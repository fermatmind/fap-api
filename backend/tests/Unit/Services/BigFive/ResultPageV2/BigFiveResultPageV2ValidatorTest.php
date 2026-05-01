<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SourceAuthorityMap;
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

    public function test_source_authority_map_covers_required_registries_and_modules(): void
    {
        $registries = BigFiveResultPageV2SourceAuthorityMap::registries();

        $this->assertSame([
            'domain_registry',
            'facet_registry',
            'coupling_registry',
            'scenario_registry',
            'profile_signature_registry',
            'state_scope_registry',
            'observation_feedback_registry',
            'share_safety_registry',
            'boundary_registry',
            'method_registry',
        ], array_keys($registries));
        foreach ($registries as $registryKey => $registry) {
            $this->assertSame($registryKey, $registry['registry_key'] ?? null);
            foreach ([
                'purpose',
                'owner',
                'input_fields',
                'output_block_kinds',
                'allowed_modules',
                'current_code_basis',
                'missing_pieces',
                'public_allowed_fields',
                'internal_only_fields',
                'safety_constraints',
                'evidence_level_requirement',
                'shareable_policy',
                'fallback_policy',
                'versioning_policy',
            ] as $requiredField) {
                $this->assertArrayHasKey($requiredField, $registry, "{$registryKey} missing {$requiredField}");
            }
        }

        $this->assertSame(BigFiveResultPageV2Contract::MODULE_KEYS, array_keys(BigFiveResultPageV2SourceAuthorityMap::moduleRegistryMap()));
    }

    public function test_old_v2_registry_map_marks_existing_registry_groups_as_non_direct_sources(): void
    {
        $mapping = BigFiveResultPageV2SourceAuthorityMap::oldV2RegistryMap();

        $this->assertSame([
            'atomic',
            'modifiers',
            'synergies',
            'facet_glossary',
            'facet_precision',
            'action_rules',
            'shared_methodology',
            'shared_labels',
        ], array_keys($mapping));
        $this->assertSame('transform_required', $mapping['atomic']['reuse_status']);
        $this->assertSame('transform_required', $mapping['synergies']['reuse_status']);
        $this->assertSame('internal_only', $mapping['shared_methodology']['reuse_status']);
        $this->assertSame('not_v2_ready', $mapping['shared_labels']['reuse_status']);
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

    public function test_it_rejects_unknown_registry_refs_and_invalid_content_source(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['registry_refs'] = ['unknown_registry:O'];
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['content_source'] = 'frontend_fallback';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 registry_ref uses unknown registry: unknown_registry', $errors);
        $this->assertContains('module_03_trait_deep_dive.blocks.0 content_source is invalid: frontend_fallback', $errors);
    }

    public function test_it_rejects_registry_refs_that_are_not_authorized_for_the_module(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['registry_refs'] = ['share_safety_registry:summary_card'];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 registry share_safety_registry is not allowed for module module_03_trait_deep_dive', $errors);
    }

    public function test_it_rejects_old_v2_registry_refs_as_direct_v2_source_authority(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['registry_refs'] = ['old_v2_atomic:O.high'];
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['content_source'] = 'transformed_old_v2_registry';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 must not reference old_v2_atomic directly; use transformed_old_v2_registry with a mapped V2.0 registry', $errors);
    }

    public function test_it_accepts_transformed_old_v2_source_with_explicit_source_authority(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['content_source'] = 'transformed_old_v2_registry';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['source_authority'] = [
            'old_v2_group' => 'atomic',
            'mapped_registry' => 'domain_registry',
            'reuse_status' => 'transform_required',
        ];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertSame([], $errors);
    }

    public function test_it_rejects_transformed_old_v2_source_without_valid_mapping_provenance(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['content_source'] = 'transformed_old_v2_registry';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 transformed_old_v2_registry requires source_authority', $errors);

        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][3]['blocks'][0]['source_authority'] = [
            'old_v2_group' => 'synergies',
            'mapped_registry' => 'domain_registry',
            'reuse_status' => 'direct_reuse',
        ];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_03_trait_deep_dive.blocks.0 source_authority synergies does not map to domain_registry', $errors);
        $this->assertContains('module_03_trait_deep_dive.blocks.0 source_authority.reuse_status is invalid: direct_reuse', $errors);
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

    public function test_it_rejects_profile_signature_public_fixed_type_wording(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][1]['blocks'][0]['content']['fixed_type'] = 'not allowed';
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][1]['blocks'][0]['content']['type_name'] = 'not allowed';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field big5_result_page_v2.modules.1.blocks.0.content.fixed_type', $errors);
        $this->assertContains('Forbidden public field big5_result_page_v2.modules.1.blocks.0.content.type_name', $errors);
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
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['projection_v2']['domains']['O']['score'] = 59;
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['projection_v2']['domains']['O']['percentile'] = 59;
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][1]['blocks'][0]['content']['show_normal_curve'] = true;

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field projection_v2.domains.O.score', $errors);
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

    public function test_it_rejects_shareable_blocks_without_share_safety_registry(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][7]['blocks'][0]['registry_refs'] = ['scenario_registry:collaboration'];

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_07_collaboration_manual.blocks.0 shareable block must reference share_safety_registry', $errors);
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

    public function test_it_rejects_observation_feedback_user_confirmed_type(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][9]['blocks'][0]['content']['user_confirmed_type'] = 'not allowed';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('Forbidden public field big5_result_page_v2.modules.9.blocks.0.content.user_confirmed_type', $errors);
    }

    public function test_it_rejects_boundary_or_method_frontend_fallback_policy(): void
    {
        $payload = $this->loadFixture('canonical_mixed_signature.payload.json');
        $payload[BigFiveResultPageV2Contract::PAYLOAD_KEY]['modules'][10]['blocks'][0]['fallback_policy'] = 'frontend_fallback';

        $errors = app(BigFiveResultPageV2Validator::class)->validateEnvelope($payload);

        $this->assertContains('module_10_method_privacy.blocks.0 fallback_policy is invalid: frontend_fallback', $errors);
        $this->assertContains('module_10_method_privacy.blocks.0 method_boundary must not use frontend fallback', $errors);
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
