<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Measurement\MeasurementEvidenceHoldReasonResolver;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceLoadResult;
use Tests\TestCase;

final class SeoPlatform11FEvidenceDiagnosticReasonTest extends TestCase
{
    public function test_search_reason_codes_are_deterministic_and_fail_closed(): void
    {
        $resolver = app(MeasurementEvidenceHoldReasonResolver::class);
        $ready = [
            'schema_available' => true,
            'eligible_rows' => true,
            'stale' => false,
            'quality_passed' => true,
            'mapping_valid' => true,
            'authority_valid' => true,
            'readmodel_healthy' => true,
            'window_complete' => true,
        ];
        $cases = [
            'schema_available' => 'GSC_SCHEMA_UNAVAILABLE',
            'eligible_rows' => 'GSC_NO_ELIGIBLE_ROWS',
            'quality_passed' => 'GSC_QUALITY_HOLD',
            'window_complete' => 'GSC_WINDOW_INCOMPLETE',
            'mapping_valid' => 'GSC_MAPPING_FAILED',
            'authority_valid' => 'GSC_AUTHORITY_CONFLICT',
            'readmodel_healthy' => 'GSC_READMODEL_UNHEALTHY',
        ];
        foreach ($cases as $field => $reason) {
            $this->assertSame($reason, $resolver->search([...$ready, $field => false]), $field);
        }
        $this->assertSame('GSC_STALE', $resolver->search([...$ready, 'stale' => true]));
        $this->assertSame(MeasurementEvidenceLoadResult::NONE, $resolver->search($ready));
    }

    public function test_cro_reason_codes_are_deterministic_and_fail_closed(): void
    {
        $resolver = app(MeasurementEvidenceHoldReasonResolver::class);
        $ready = [
            'schema_available' => true,
            'stale' => false,
            'readmodel_healthy' => true,
            'window_complete' => true,
            'mapping_valid' => true,
            'stage_coverage_complete' => true,
        ];
        $cases = [
            'schema_available' => 'CRO_SCHEMA_UNAVAILABLE',
            'readmodel_healthy' => 'CRO_READMODEL_UNHEALTHY',
            'window_complete' => 'CRO_WINDOW_INCOMPLETE',
            'mapping_valid' => 'CRO_MAPPING_FAILED',
            'stage_coverage_complete' => 'CRO_STAGE_COVERAGE_INCOMPLETE',
        ];
        foreach ($cases as $field => $reason) {
            $this->assertSame($reason, $resolver->cro([...$ready, $field => false]), $field);
        }
        $this->assertSame('CRO_STALE', $resolver->cro([...$ready, 'stale' => true]));
        $this->assertSame(MeasurementEvidenceLoadResult::NONE, $resolver->cro($ready));
    }

    public function test_diagnostic_projection_contains_only_enums_boolean_and_hash(): void
    {
        foreach ([
            ...MeasurementEvidenceLoadResult::SEARCH_HOLDS,
            ...MeasurementEvidenceLoadResult::CRO_HOLDS,
            ...MeasurementEvidenceLoadResult::COMMON_HOLDS,
        ] as $reason) {
            $mode = str_starts_with($reason, 'CRO_') ? 'commercial_funnel_cro' : 'search_measurement';
            $diagnostic = MeasurementEvidenceLoadResult::make(
                $mode, [], 'unavailable', 'unknown', $reason
            )->diagnostic();
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $diagnostic['authority_revision']);
            $this->assertIsBool($diagnostic['bundle_present']);
            $encoded = json_encode($diagnostic, JSON_THROW_ON_ERROR);
            foreach (['host', 'port', 'database', 'select ', 'exception', 'trace', 'http', 'query', 'token', 'credential'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower($encoded), $reason);
            }
        }
    }
}
