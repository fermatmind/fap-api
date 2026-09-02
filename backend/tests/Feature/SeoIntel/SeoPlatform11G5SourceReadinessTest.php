<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceIngestionService;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveGatewayReader;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveMeasurementReadiness;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourcePolicyRegistry;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use App\Services\SeoCouncil\Competitive\CompetitiveEvidenceBundleLoader;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeoPlatform11G5SourceReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.seo_intel', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('seo_intel');
        (require database_path('migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php'))->up();
    }

    public function test_live_cohort_holds_before_external_read_or_write(): void
    {
        $this->artisan('seo:competitive-evidence-ingest', [
            '--cohort' => 'competitive.big-five.live.v2',
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
        ])->expectsOutputToContain('"SEO-PLATFORM-11G":"HOLD"')
            ->assertSuccessful();

        $this->artisan('seo:competitive-evidence-ingest', [
            '--cohort' => 'competitive.big-five.live.v2',
            '--write-evidence' => true,
            '--json' => true,
        ])->expectsOutputToContain('COMPETITIVE_WRITE_BOUNDARY_HELD')
            ->assertFailed();
    }

    public function test_v3_policy_registry_is_current_hash_bound_and_domain_independent(): void
    {
        $registry = app(CompetitiveSourcePolicyRegistry::class);
        $snapshot = $registry->snapshot('competitive.big-five.live.v2');
        $this->assertSame('seo.competitive_source_policy.v3', $snapshot['source_policy_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['source_policy_set_hash']);
        $this->assertSame([], config('seo_agent_evidence.allowed_sources'));

        $competitorDomains = [];
        foreach ($registry->sourcesFor((array) app(\App\Services\SeoAgentEvidence\Competitive\CompetitiveSourceRegistry::class)->cohort('competitive.big-five.live.v2')) as $source) {
            if ($source['source_class'] === 'competitor_public') {
                $host = (string) parse_url($source['url'], PHP_URL_HOST);
                $competitorDomains[] = implode('.', array_slice(explode('.', $host), -2));
            }
        }
        $this->assertCount(2, array_unique($competitorDomains));
        $expectedEvidenceHashes = [
            'fermatmind-big-five-en' => ['58aad528ad4ea70fecd7527fdb435739ce444db9de13935f850d32ef0eeffb0a', '61ec67d56b12c396382db5473664f1457c5f37cc9d03896f331b52a0e2a1e9a3', 'c2597aba68ffc3fdb9cc697f42f98e00440d5ee86362515301c1917a6749ba0d'],
            'fermatmind-big-five-zh' => ['58aad528ad4ea70fecd7527fdb435739ce444db9de13935f850d32ef0eeffb0a', '61ec67d56b12c396382db5473664f1457c5f37cc9d03896f331b52a0e2a1e9a3', 'c2597aba68ffc3fdb9cc697f42f98e00440d5ee86362515301c1917a6749ba0d'],
            'bigfive-test' => ['b265717eeca2bceb69a8605bc3a17e8d3a961c5dfe2a49c37f42c27b0cb75869', 'b265717eeca2bceb69a8605bc3a17e8d3a961c5dfe2a49c37f42c27b0cb75869', '15441e54b432b2353bbc634cfd2c7ef22d6947ac396184ed3c2ee63fab56c564'],
            'jobcannon' => ['75fc747de7ab9b0f1ad19b18ceb4a9f001630aec95b9d93d6bdbe8313278ffbc', 'b629bbe160ab05997cea7ba7235acf410a7e7072b76dfabf70591ae2880cfb86', 'e286629fa90f3adefefa64555ed5bee3343c1062c1f2fe9895896dc6b788a85c'],
        ];
        $expectedReviewWindows = [
            'fermatmind-big-five-en' => ['2026-09-02T12:21:07Z', '2026-10-02T12:21:07Z'],
            'fermatmind-big-five-zh' => ['2026-09-02T12:21:07Z', '2026-10-02T12:21:07Z'],
            'bigfive-test' => ['2026-09-02T13:09:56Z', '2026-10-02T13:09:56Z'],
            'jobcannon' => ['2026-09-02T22:00:00Z', '2026-10-02T22:00:00Z'],
        ];
        foreach ($registry->policies() as $sourceId => $policy) {
            $this->assertSame($expectedReviewWindows[$sourceId][0], $policy['reviewed_at']);
            $this->assertSame($expectedReviewWindows[$sourceId][1], $policy['expires_at']);
            $this->assertSame($expectedEvidenceHashes[$sourceId], [
                $policy['terms_content_hash'],
                $policy['license_content_hash'],
                $policy['robots_evidence_hash'],
            ]);
            $this->assertLessThanOrEqual(2592000, strtotime($policy['expires_at']) - strtotime($policy['reviewed_at']));
            $this->assertGreaterThan(time(), strtotime($policy['expires_at']));
            $this->assertSame(['url_hash', 'content_hash', 'structural_projection', 'review_decision'], $policy['retention_scope']);
            $this->assertTrue($policy['prohibitions']['raw_html_retention']);
            $this->assertTrue($policy['prohibitions']['competitor_text_retention']);
        }
        $this->assertSame('https://bigfive-test.com/faq', $registry->policies()['bigfive-test']['license_url']);
        $this->assertSame('https://jobcannon.io/embed', $registry->policies()['jobcannon']['license_url']);
        $this->assertNotContains('https://raw.githubusercontent.com', $registry->policies()['bigfive-test']['allowed_origins']);
        $this->assertNotContains('b5-allthethings', array_keys($registry->policies()));
    }

    public function test_runtime_closeout_closes_only_for_independent_production_bundle(): void
    {
        $sha = str_repeat('a', 40);
        $builder = app(CompetitiveCloseoutBuilder::class);
        config()->set('seo_agent_evidence.allowed_sources', ['a' => [], 'b' => [], 'c' => [], 'd' => []]);
        $ingestion = [
            'status' => 'READY',
            'hold_reason' => 'NONE',
            'bundle_verification' => 'valid',
            'competitive_output' => [
                'status' => 'READY',
                '11i_handoff' => ['source_freshness' => 'fresh', 'source_count' => 2],
            ],
            'dependency_ingestion' => [
                'external_reads' => 12,
                'bundle_hash' => str_repeat('d', 64),
                'release_ref' => app(\App\Services\SeoAgentEvidence\Competitive\CompetitiveReleaseIdentity::class)->reference('production', $sha),
            ],
            'policy_snapshot' => app(CompetitiveSourcePolicyRegistry::class)->snapshot('competitive.big-five.live.v2'),
            'measurement' => $this->readyMeasurement(),
        ];

        $staging = $builder->buildRuntime($ingestion, $sha, 'staging');
        $this->assertSame('HOLD', $staging['SEO-PLATFORM-11G']);
        $this->assertSame('STAGING_VALIDATED', $staging['closeout_state']);
        $this->assertSame('NONE', $staging['competitive_hold_reason']);
        $this->assertSame('READY', $staging['cro_measurement']['context_status']);
        $this->assertSame('NONE', $staging['cro_measurement']['hold_reason']);
        $this->assertSame(12, $staging['dependency_ingestion']['external_reads']);

        $preactivation = $builder->buildRuntime($ingestion, $sha, 'production');
        $this->assertSame('HOLD', $preactivation['closeout_state']);
        $this->assertNull($preactivation['production_sha']);
        $mismatch = $builder->finalizeRuntime($preactivation, str_repeat('b', 40));
        $this->assertSame('HOLD', $mismatch['closeout_state']);
        $this->assertNull($mismatch['production_sha']);
        $production = $builder->finalizeRuntime($preactivation, $sha);
        $this->assertTrue($builder->verify($production, $sha));
        $this->assertSame('CLOSED', $production['SEO-PLATFORM-11G']);
        $this->assertTrue($production['ready_for_11H']);
        $this->assertTrue($production['11i_handoff_ready']);
        $this->assertSame('NONE', $production['competitive_hold_reason']);
        $this->assertSame($ingestion['measurement']['measurement_bundle_set_hash'], $production['measurement_bundle_set_hash']);
        $this->assertSame('available', $production['search_measurement']['source_state']);
        $this->assertSame('available', $production['cro_measurement']['source_state']);
        $this->assertSame(0, $production['external_calls']);
        $this->assertSame(0, $production['production_permissions']);
    }

    public function test_v3_policy_registry_rejects_future_review_dates(): void
    {
        $registry = app(CompetitiveSourcePolicyRegistry::class);
        $sourceRegistry = $registry->sourceRegistry();
        $source = (array) $sourceRegistry['sources'][0];
        $policy = $registry->policies()[(string) $source['source_id']];
        $policy['reviewed_at'] = now('UTC')->addDay()->format('Y-m-d\TH:i:s\Z');
        $policy['expires_at'] = now('UTC')->addDays(2)->format('Y-m-d\TH:i:s\Z');

        $method = new \ReflectionMethod($registry, 'assertPolicy');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COMPETITIVE_SOURCE_POLICY_INVALID');
        $method->invoke($registry, $policy, $source, (string) $sourceRegistry['registry_revision']);
    }

    public function test_v3_policy_registry_rejects_license_masquerading_as_terms(): void
    {
        $registry = app(CompetitiveSourcePolicyRegistry::class);
        $sourceRegistry = $registry->sourceRegistry();
        $source = collect($sourceRegistry['sources'])->firstWhere('source_id', 'bigfive-test');
        $policy = $registry->policies()['bigfive-test'];
        $policy['combined_terms_license_scope'] = false;

        $method = new \ReflectionMethod($registry, 'assertPolicy');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COMPETITIVE_SOURCE_POLICY_LICENSE_AS_TERMS');
        $method->invoke($registry, $policy, $source, (string) $sourceRegistry['registry_revision']);
    }

    public function test_measurement_hold_prevents_all_external_reads(): void
    {
        $this->app->bind(CompetitiveGatewayReader::class, static fn (): CompetitiveGatewayReader => new class implements CompetitiveGatewayReader
        {
            public function fetch(string $sourceId, string $url, array $context, array $semantic): array
            {
                return [
                    'status' => 'held',
                    'safe_error_code' => 'TERMS_POLICY_DRIFT',
                    'dependency_ingestion' => ['external_reads' => 2],
                ];
            }
        });

        $result = app(CompetitiveEvidenceIngestionService::class)->ingest(
            ['cohort_id' => 'test-approved', 'page_family' => 'tests', 'collection_state' => 'approved', 'minimum_competitor_sources' => 1],
            [['source_id' => 'competitor-a', 'source_class' => 'competitor_public', 'page_family' => 'tests', 'locale' => 'en', 'url' => 'https://example.com/test']],
            'staging',
            str_repeat('a', 40),
            false,
        );

        $this->assertSame('HOLD', $result['status']);
        $this->assertNotSame('TERMS_POLICY_DRIFT', $result['hold_reason']);
        $this->assertSame(0, $result['dependency_ingestion']['external_reads']);
        $this->assertFalse($result['write_performed']);
    }

    public function test_measurement_mission_release_token_cannot_resemble_private_data(): void
    {
        $releaseSha = '4d92ffa1b5cf247f12441371647fade8043f5cc6';
        $method = new \ReflectionMethod(app(CompetitiveMeasurementReadiness::class), 'releaseToken');
        $token = $method->invoke(app(CompetitiveMeasurementReadiness::class), $releaseSha);

        $this->assertMatchesRegularExpression('/^[a-p]{64}$/D', $token);
        $this->assertSame(
            'pass',
            app(SeoPrivateDataScanner::class)->scan('competitive:'.$token.':search_measurement')['decision'],
        );
        $releaseRef = app(\App\Services\SeoAgentEvidence\Competitive\CompetitiveReleaseIdentity::class)->reference('staging', $releaseSha);
        $this->assertMatchesRegularExpression('/^release_[a-p]{64}$/D', $releaseRef);
        $this->assertSame('pass', app(SeoPrivateDataScanner::class)->scan($releaseRef)['decision']);
    }

    /** @return array<string, mixed> */
    private function readyMeasurement(): array
    {
        $mode = [
            'source_state' => 'available',
            'freshness_state' => 'fresh',
            'bundle_verification' => 'valid',
            'context_status' => 'READY',
            'hold_reason' => 'NONE',
            'bundle_hash' => str_repeat('e', 64),
        ];

        return [
            'status' => 'READY',
            'hold_reason' => 'NONE',
            'measurement_bundle_set_hash' => str_repeat('f', 64),
            'search_measurement' => $mode,
            'cro_measurement' => $mode,
            'bundles' => [],
        ];
    }

    public function test_council_loader_rejects_staging_bundle_in_production(): void
    {
        $sha = str_repeat('b', 40);
        $releaseRef = app(\App\Services\SeoAgentEvidence\Competitive\CompetitiveReleaseIdentity::class)->reference('staging', $sha);
        $bundle = app(SeoEvidenceBundleFactory::class)->create([
            'bundle_id' => 'competitive:staging:'.$releaseRef,
            'bundle_version' => 1,
            'mission_id' => 'competitive:ingestion:'.$releaseRef,
            'source_type' => 'external_gateway',
            'source_ref' => hash('sha256', 'staging|'.$sha),
            'authority_type' => 'competitive_structural_projection',
            'captured_at' => '2026-09-01T00:00:00Z',
            'evidence_state' => 'verified',
            'freshness_state' => 'fresh',
            'source_capability_state' => 'available',
            'retention_class' => 'external_structured_fact',
            'page_family' => 'tests',
            'locale' => 'en',
            'authority_revision' => str_repeat('c', 64),
            'source_license_class' => 'public_fact_permitted',
            'data_usage_purpose' => 'competitive_evidence',
            'egress_decision' => 'allowed_by_gateway',
            'lineage_refs' => [],
            'payload' => [
                'environment' => 'staging',
                'release_ref' => $releaseRef,
                'competitive_output' => [],
            ],
        ]);
        DB::connection('seo_intel')->table('seo_evidence_bundles')->insert([
            'bundle_id' => $bundle['bundle_id'],
            'bundle_version' => 1,
            'bundle_hash' => $bundle['bundle_hash'],
            'mission_id' => $bundle['mission_id'],
            'page_family' => 'tests',
            'locale' => 'en',
            'source_type' => 'external_gateway',
            'expires_at' => $bundle['expires_at'],
            'bundle_json' => json_encode($bundle, JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'),
        ]);

        $request = MissionRequestData::fromInput([
            'mission_id' => 'mission:11g:cross-environment',
            'idempotency_key' => 'idempotency:11g:cross-environment',
            'mission_type' => 'bounded_review',
            'family' => 'tests',
            'locale' => 'en',
            'review_domain' => 'competitor',
            'requested_role' => null,
            'evidence_bundle_refs' => [[
                'bundle_id' => $bundle['bundle_id'],
                'bundle_version' => 1,
                'bundle_hash' => $bundle['bundle_hash'],
                'evidence_type' => 'gateway_competitor_public',
                'status' => 'READY',
                'authority_revision' => str_repeat('c', 64),
            ], [
                'bundle_id' => 'measurement:'.$sha,
                'bundle_version' => 1,
                'bundle_hash' => str_repeat('d', 64),
                'evidence_type' => 'search_measurement',
                'status' => 'READY',
                'authority_revision' => str_repeat('d', 64),
            ]],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ], 'cli', app(CouncilContractValidator::class), app(SeoRegistryHasher::class));

        $this->assertSame([], app(CompetitiveEvidenceBundleLoader::class)->load($request, $sha, 'production_runtime'));
        $this->assertCount(1, app(CompetitiveEvidenceBundleLoader::class)->load($request, $sha, 'staging_runtime'));
    }
}
