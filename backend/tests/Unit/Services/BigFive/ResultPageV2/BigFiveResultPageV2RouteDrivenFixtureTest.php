<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use Tests\TestCase;

final class BigFiveResultPageV2RouteDrivenFixtureTest extends TestCase
{
    private const FIXTURES = [
        'o59_canonical' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_o59_canonical_pilot_payload_v0_1.payload.json',
            'profile' => 'sensitive_independent_thinker',
            'combination_key' => BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY,
        ],
        'sensitive_independent_thinker' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_sensitive_independent_thinker_pilot_payload_v0_1.payload.json',
            'profile' => 'sensitive_independent_thinker',
        ],
        'vigilant_perfectionist' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_vigilant_perfectionist_pilot_payload_v0_1.payload.json',
            'profile' => 'vigilant_perfectionist',
        ],
        'complex_explorer_low_structure' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_complex_explorer_low_structure_pilot_payload_v0_1.payload.json',
            'profile' => 'complex_explorer_low_structure',
        ],
        'quiet_deep_worker' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_quiet_deep_worker_pilot_payload_v0_1.payload.json',
            'profile' => 'quiet_deep_worker',
        ],
        'connective_coordinator' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_connective_coordinator_pilot_payload_v0_1.payload.json',
            'profile' => 'connective_coordinator',
        ],
        'sharp_exploratory_driver' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_sharp_exploratory_driver_pilot_payload_v0_1.payload.json',
            'profile' => 'sharp_exploratory_driver',
        ],
        'orderly_supporter' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_orderly_supporter_pilot_payload_v0_1.payload.json',
            'profile' => 'orderly_supporter',
        ],
        'overloaded_internalizer' => [
            'path' => 'tests/Fixtures/big5_result_page_v2/route_driven_overloaded_internalizer_pilot_payload_v0_1.payload.json',
            'profile' => 'overloaded_internalizer',
        ],
    ];

    public function test_route_driven_fixture_set_covers_o59_and_eight_profile_families(): void
    {
        $this->assertCount(9, self::FIXTURES);
        $profiles = array_values(array_unique(array_map(
            static fn (array $fixture): string => (string) $fixture['profile'],
            self::FIXTURES,
        )));
        sort($profiles);

        $this->assertSame([
            'complex_explorer_low_structure',
            'connective_coordinator',
            'orderly_supporter',
            'overloaded_internalizer',
            'quiet_deep_worker',
            'sensitive_independent_thinker',
            'sharp_exploratory_driver',
            'vigilant_perfectionist',
        ], $profiles);
    }

    public function test_route_driven_fixtures_are_validator_clean_and_profile_aligned(): void
    {
        $validator = app(BigFiveResultPageV2Validator::class);

        foreach (self::FIXTURES as $fixtureKey => $fixture) {
            $envelope = $this->decodeJson((string) $fixture['path']);
            $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
            $this->assertIsArray($payload, $fixtureKey);

            $this->assertSame([], $validator->validateEnvelope($envelope), $fixtureKey);
            $this->assertSame($fixture['profile'], $payload['canonical_profile_key'] ?? null, $fixtureKey);

            if (isset($fixture['combination_key'])) {
                $this->assertSame($fixture['profile'], data_get($payload, 'projection_v2.profile_signature.signature_key'), $fixtureKey);
                $this->assertSame(['O' => 3, 'C' => 2, 'E' => 2, 'A' => 3, 'N' => 4], [
                    'O' => data_get($payload, 'projection_v2.domains.O.score'),
                    'C' => data_get($payload, 'projection_v2.domains.C.score'),
                    'E' => data_get($payload, 'projection_v2.domains.E.score'),
                    'A' => data_get($payload, 'projection_v2.domains.A.score'),
                    'N' => data_get($payload, 'projection_v2.domains.N.score'),
                ], $fixtureKey);
            }
        }
    }

    public function test_route_driven_fixtures_have_content_blocks_without_metadata_leaks(): void
    {
        foreach (self::FIXTURES as $fixtureKey => $fixture) {
            $payload = $this->decodeJson((string) $fixture['path'])[BigFiveResultPageV2Contract::PAYLOAD_KEY];
            $modulesByKey = [];
            foreach ((array) ($payload['modules'] ?? []) as $module) {
                $modulesByKey[(string) ($module['module_key'] ?? '')] = $module;
            }

            $this->assertNotSame('pending_asset_resolution', data_get($modulesByKey, 'module_01_hero.blocks.0.content.availability'), $fixtureKey);
            $this->assertNotSame('pending_asset_resolution', data_get($modulesByKey, 'module_03_trait_deep_dive.blocks.0.content.availability'), $fixtureKey);
            $this->assertNotSame('pending_asset_resolution', data_get($modulesByKey, 'module_06_application_matrix.blocks.0.content.availability'), $fixtureKey);
            if ($fixtureKey === 'o59_canonical') {
                $this->assertNotSame('pending_asset_resolution', data_get($modulesByKey, 'module_04_coupling.blocks.0.content.availability'), $fixtureKey);
            }

            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            foreach ($this->forbiddenPublicTerms() as $forbiddenTerm) {
                $this->assertStringNotContainsString($forbiddenTerm, $encoded, "{$fixtureKey}: {$forbiddenTerm}");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function forbiddenPublicTerms(): array
    {
        return [
            'source_reference',
            'selector_basis',
            'qa_notes',
            'editor_notes',
            'internal_metadata',
            'review_status',
            'production_use_allowed',
            'runtime_use',
            'ready_for_pilot',
            'ready_for_runtime',
            'ready_for_production',
            'frontend_fallback',
            'source_trace',
            'repair_log_refs',
            '[object Object]',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $relativePath): array
    {
        $json = file_get_contents(base_path($relativePath));
        $this->assertIsString($json, $relativePath);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $relativePath);

        return $decoded;
    }
}
