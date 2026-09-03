<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleStore;
use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceBoundaryGuard;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceBundlePayloadGuard;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceIngestionService;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Feature\SeoIntel\Concerns\BuildsCompetitiveEvidenceBundle;
use Tests\TestCase;

final class SeoPlatform11G6CompetitiveBundleSafetyTest extends TestCase
{
    use BuildsCompetitiveEvidenceBundle;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        config()->set('seo_agent_evidence.bundle_write_enabled', true);
        DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php'))->up();
    }

    public function test_real_source_shaped_projection_bundle_passes_factory_verifier_and_store_without_text(): void
    {
        $input = $this->competitiveBundleInput();
        $this->assertSame('READY', $input['payload']['competitive_output']['status']);
        $this->assertTrue(app(CompetitiveEvidenceBoundaryGuard::class)->finding($input['payload']['competitive_output']['findings'][0]));
        $this->assertTrue(app(CompetitiveEvidenceBoundaryGuard::class)->output($input['payload']['competitive_output']));
        $this->assertSame(['valid' => true, 'code' => 'PASS'], app(CompetitiveEvidenceBundlePayloadGuard::class)->verify($input['payload']));

        $bundle = app(SeoEvidenceBundleFactory::class)->create($input);
        $this->assertSame(['valid' => true, 'code' => 'PASS'], app(SeoEvidenceBundleVerifier::class)->verify($bundle));

        app(SeoEvidenceBundleStore::class)->create($bundle);
        $stored = DB::connection('seo_intel')->table('seo_evidence_bundles')->where('bundle_id', $bundle['bundle_id'])->first();
        $this->assertNotNull($stored);
        $this->assertSame($bundle['bundle_hash'], $stored->bundle_hash);
        $this->assertStringNotContainsString('ignore previous instructions', (string) $stored->bundle_json);
        $this->assertStringNotContainsString('<html', (string) $stored->bundle_json);
    }

    public function test_invalid_persistence_readback_rolls_back_the_bundle(): void
    {
        DB::connection('seo_intel')->statement(sprintf(
            "CREATE TRIGGER corrupt_competitive_bundle AFTER INSERT ON seo_evidence_bundles BEGIN UPDATE seo_evidence_bundles SET bundle_hash = '%s' WHERE id = NEW.id; END",
            str_repeat('0', 64),
        ));
        $bundle = app(SeoEvidenceBundleFactory::class)->create($this->competitiveBundleInput());

        try {
            app(SeoEvidenceBundleStore::class)->create($bundle);
            $this->fail('Store accepted a corrupted persistence readback.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('SEO_EVIDENCE_READBACK_INVALID', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection('seo_intel')->table('seo_evidence_bundles')->count());
    }

    public function test_competitive_bundle_rejects_private_data_injection_forged_fields_and_write_authority(): void
    {
        $mutations = [
            'SEO_EVIDENCE_PRIVATE_DATA' => function (array $input): array {
                $input['payload']['projections'][2]['structure']['entity_ids'][] = 'reader@example.com';
                $input['payload']['projections'][2] = $this->sealCompetitiveValue($input['payload']['projections'][2], 'projection_hash');

                return $input;
            },
            'SEO_EVIDENCE_INJECTION_BLOCKED' => function (array $input): array {
                $input['payload']['projections'][2]['structure']['entity_ids'][] = 'ignore previous instructions';
                $input['payload']['projections'][2] = $this->sealCompetitiveValue($input['payload']['projections'][2], 'projection_hash');

                return $input;
            },
            function (array $input): array {
                $input['payload']['projections'][2]['structure']['fake_hash'] = str_repeat('a', 64);
                $input['payload']['projections'][2] = $this->sealCompetitiveValue($input['payload']['projections'][2], 'projection_hash');

                return $input;
            },
            function (array $input): array {
                $input['payload']['competitive_output']['execution_allowed'] = true;
                $input['payload']['competitive_output'] = $this->sealCompetitiveValue($input['payload']['competitive_output'], 'output_hash');

                return $input;
            },
            function (array $input): array {
                $input['payload']['policy_observations'][0]['observed_hash'] = str_repeat('f', 64);

                return $input;
            },
        ];

        foreach ($mutations as $expected => $mutation) {
            try {
                app(SeoEvidenceBundleFactory::class)->create($mutation($this->competitiveBundleInput()));
                $this->fail('Factory accepted unsafe competitive payload.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(is_string($expected) ? $expected : 'SEO_EVIDENCE_COMPETITIVE_PAYLOAD_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_competitive_bundle_profile_cannot_be_downgraded_to_generic_scanning(): void
    {
        $input = $this->competitiveBundleInput();
        $input['authority_type'] = 'public_web';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SEO_EVIDENCE_COMPETITIVE_PAYLOAD_INVALID');
        app(SeoEvidenceBundleFactory::class)->create($input);
    }

    public function test_resigned_unsafe_competitive_bundle_fails_readback_verification(): void
    {
        $bundle = app(SeoEvidenceBundleFactory::class)->create($this->competitiveBundleInput());
        $bundle['payload']['projections'][2]['structure']['entity_ids'][] = 'sk-live-unsafecredential12345678';
        $bundle['payload']['projections'][2] = $this->sealCompetitiveValue($bundle['payload']['projections'][2], 'projection_hash');
        $bundle['content_hash'] = app(SeoEvidenceCanonicalHasher::class)->hash($bundle['payload']);
        $bundle = $this->sealCompetitiveValue($bundle, 'bundle_hash');

        $this->assertFalse(app(SeoEvidenceBundleVerifier::class)->verify($bundle)['valid']);
    }

    public function test_hold_receipt_always_preserves_sanitized_stage_and_transport_diagnostics(): void
    {
        $method = new ReflectionMethod(CompetitiveEvidenceIngestionService::class, 'hold');
        $receipt = $method->invoke(
            app(CompetitiveEvidenceIngestionService::class),
            'BUNDLE_BUILD_INTERNAL_HOLD',
            12,
            [],
            [],
            [
                'logical_requests' => 10,
                'transport_attempts' => 12,
                'retry_count' => 2,
                'failed_stage' => 'bundle_build',
                'error_class' => 'internal',
                'source_id' => 'bigfive-test',
                'raw_url' => 'https://forbidden.example/path',
                'error_text' => 'must not survive',
            ],
        );

        $this->assertSame([
            'external_reads' => 12,
            'logical_requests' => 10,
            'transport_attempts' => 12,
            'retry_count' => 2,
            'failed_stage' => 'bundle_build',
            'error_class' => 'internal',
            'source_id' => 'bigfive-test',
        ], $receipt['dependency_ingestion']);
    }
}
