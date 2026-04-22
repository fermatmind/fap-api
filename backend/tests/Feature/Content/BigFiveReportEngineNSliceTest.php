<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use Tests\TestCase;

final class BigFiveReportEngineNSliceTest extends TestCase
{
    public function test_it_generates_the_canonical_n_slice_payload_snapshot(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

        $this->assertSame('fap.big5.report.v1', $payload['schema_version']);
        $this->assertSame(['N', 'O'], $payload['engine_decisions']['dominant_traits']);
        $this->assertSame('n_high_x_e_low', data_get($payload, 'engine_decisions.selected_synergies.0.synergy_id'));
        $this->assertSame(
            ['n1_high_spike', 'n3_high_spike', 'n5_high_spike', 'n4_low_with_n_high', 'n6_low_with_n_high'],
            array_map(static fn (array $match): string => (string) $match['rule_id'], $payload['engine_decisions']['facet_anomalies'])
        );
        $this->assertCount(8, $payload['sections']);
        $this->assertSame('not_populated_in_pr1', data_get($payload, 'sections.1.status'));
        $this->assertCount(4, $payload['action_matrix']['workplace']);

        $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/big5_engine/expected_canonical_n_slice_payload.json')), true);
        $this->assertSame($expected, $payload);
    }

    public function test_registry_fixture_declares_expected_hits_that_match_engine_output(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $fixture = (array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent');
        $payload = app(BigFiveReportEngine::class)->generate($fixture);

        $this->assertSame(data_get($fixture, 'expected_hits.selected_synergies'), [
            data_get($payload, 'engine_decisions.selected_synergies.0.synergy_id'),
        ]);
        $this->assertSame(
            data_get($fixture, 'expected_hits.facet_anomalies'),
            array_map(static fn (array $match): string => (string) $match['rule_id'], $payload['engine_decisions']['facet_anomalies'])
        );
    }
}
