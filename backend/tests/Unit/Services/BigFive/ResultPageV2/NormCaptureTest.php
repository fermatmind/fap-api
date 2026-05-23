<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormObservationCaptureWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NormCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_is_default_off(): void
    {
        $result = $this->writer()->capture($this->scoreResult(), $this->context([
            'capture_enabled' => false,
        ]));

        $this->assertFalse($result->captured);
        $this->assertSame('skipped', $result->status);
        $this->assertSame('capture_default_off', $result->reason);
        $this->assertSame(0, BigFiveNormObservation::query()->count());
    }

    public function test_capture_inserts_eligible_observation_once(): void
    {
        $result = $this->writer()->capture($this->scoreResult(), $this->context());

        $this->assertTrue($result->captured);
        $this->assertSame('captured', $result->status);

        $observation = BigFiveNormObservation::query()->firstOrFail();
        $this->assertSame($result->observationId, $observation->getKey());
        $this->assertSame(BigFiveNormObservationCaptureWriter::SCHEMA_VERSION, $observation->observation_schema_version);
        $this->assertSame('eligible', $observation->norm_eligibility_status);
        $this->assertFalse((bool) $observation->norm_excluded);
        $this->assertSame('A', $observation->quality_level);
        $this->assertSame(['A' => 69, 'C' => 64, 'E' => 58, 'N' => 31, 'O' => 71], $observation->raw_domain_scores_json);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $observation->score_trace_hash);
    }

    public function test_duplicate_capture_is_idempotent_without_mutation(): void
    {
        $writer = $this->writer();
        $first = $writer->capture($this->scoreResult(), $this->context());
        $second = $writer->capture($this->scoreResult([
            'raw_domain_scores' => ['O' => 1, 'C' => 2, 'E' => 3, 'A' => 4, 'N' => 5],
        ]), $this->context());

        $this->assertTrue($first->captured);
        $this->assertFalse($second->captured);
        $this->assertSame('duplicate_replay', $second->status);
        $this->assertSame($first->observationId, $second->observationId);
        $this->assertSame(1, BigFiveNormObservation::query()->count());
        $this->assertSame(71, BigFiveNormObservation::query()->firstOrFail()->raw_domain_scores_json['O']);
    }

    public function test_capture_rejects_fail_closed_inputs(): void
    {
        $cases = [
            'external_scope' => [$this->scoreResult(), ['operation_scope' => 'external']],
            'unsupported_schema' => [$this->scoreResult(), ['observation_schema_version' => 'unsupported']],
            'missing_score_version' => [$this->scoreResult(), ['score_version' => '']],
            'invalid_eligibility' => [$this->scoreResult(), ['norm_eligibility_status' => 'excluded']],
            'source_excluded' => [$this->scoreResult(), ['attempt_source' => 'fixture']],
            'source_excluded_case_insensitive' => [$this->scoreResult(), ['attempt_source' => 'Staging']],
            'low_quality' => [$this->scoreResult(), ['quality_level' => 'C']],
            'quality_flag_excluded' => [$this->scoreResult(), ['quality_flags' => ['SPEEDING']]],
            'quality_flag_excluded_case_insensitive' => [$this->scoreResult(), ['quality_flags' => ['attention_check_failed']]],
            'unsupported_scale_code' => [$this->scoreResult(), ['scale_code' => 'RIASEC']],
            'unsupported_form_code' => [$this->scoreResult(), ['form_code' => 'big5_legacy']],
            'missing_raw_domain_scores' => [$this->scoreResult(['raw_domain_scores' => []]), []],
            'missing_raw_facet_scores' => [$this->scoreResult(['raw_facet_scores' => []]), []],
            'incomplete_raw_domain_scores' => [$this->scoreResult(['raw_domain_scores' => ['O' => 71, 'C' => 64, 'E' => 58, 'A' => 69]]), []],
            'incomplete_raw_facet_scores' => [$this->scoreResult(['raw_facet_scores' => ['O1' => 15]]), []],
            'invalid_raw_domain_scores' => [$this->scoreResult(['raw_domain_scores' => ['O' => 71, 'C' => 64, 'E' => 58, 'A' => 69, 'N' => 'NaN']]), []],
            'invalid_raw_facet_scores' => [$this->scoreResult(['raw_facet_scores' => array_replace($this->facetScores(), ['C6' => null])]), []],
        ];

        foreach ($cases as $case => [$scoreResult, $overrides]) {
            $result = $this->writer()->capture($scoreResult, $this->context(array_merge([
                'observation_idempotency_key' => 'norm-capture-'.$case,
            ], $overrides)));

            $this->assertFalse($result->captured, $case);
            $this->assertSame('rejected', $result->status, $case);
        }

        $this->assertSame(0, BigFiveNormObservation::query()->count());
    }

    public function test_capture_ignores_non_whitelisted_context_fields(): void
    {
        $this->writer()->capture($this->scoreResult(), $this->context([
            'direct_subject_reference' => 'do-not-store',
            'network_address' => '127.0.0.1',
        ]));

        $payload = BigFiveNormObservation::query()->firstOrFail()->getAttributes();

        $this->assertArrayNotHasKey('direct_subject_reference', $payload);
        $this->assertArrayNotHasKey('network_address', $payload);
    }

    private function writer(): BigFiveNormObservationCaptureWriter
    {
        return new BigFiveNormObservationCaptureWriter;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function scoreResult(array $overrides = []): array
    {
        return array_replace([
            'raw_domain_scores' => ['O' => 71, 'C' => 64, 'E' => 58, 'A' => 69, 'N' => 31],
            'raw_facet_scores' => $this->facetScores(),
        ], $overrides);
    }

    /**
     * @return array<string,int>
     */
    private function facetScores(): array
    {
        return [
            'N1' => 7, 'E1' => 12, 'O1' => 15, 'A1' => 14, 'C1' => 13,
            'N2' => 8, 'E2' => 11, 'O2' => 16, 'A2' => 13, 'C2' => 14,
            'N3' => 9, 'E3' => 10, 'O3' => 17, 'A3' => 12, 'C3' => 15,
            'N4' => 10, 'E4' => 9, 'O4' => 18, 'A4' => 11, 'C4' => 16,
            'N5' => 11, 'E5' => 8, 'O5' => 19, 'A5' => 10, 'C5' => 17,
            'N6' => 12, 'E6' => 7, 'O6' => 20, 'A6' => 9, 'C6' => 18,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function context(array $overrides = []): array
    {
        return array_replace([
            'capture_enabled' => true,
            'operation_scope' => 'internal_only',
            'observation_schema_version' => BigFiveNormObservationCaptureWriter::SCHEMA_VERSION,
            'observation_idempotency_key' => 'norm-capture-o59-v1',
            'observation_source' => 'unit_test',
            'environment' => 'testing',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_120',
            'content_version' => 'big5.result_page_v2.content.v0.1',
            'score_version' => 'big5.scoring.v1',
            'norm_version_at_scoring' => 'norms.disabled',
            'norm_eligibility_status' => 'eligible',
            'norm_excluded' => false,
            'quality_level' => 'A',
            'quality_flags' => [],
            'locale' => 'zh-CN',
            'region' => 'CN',
            'age_band' => '25_34',
            'gender_bucket' => 'unspecified',
            'occupation_bucket' => 'not_collected',
            'attempt_source' => 'real',
            'attempt_submitted_at' => now(),
            'observed_at' => now(),
        ], $overrides);
    }
}
