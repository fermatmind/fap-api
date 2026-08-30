<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

final class MeasurementEvidenceHoldReasonResolver
{
    /** @param array<string, bool> $state */
    public function search(array $state): string
    {
        return match (true) {
            ! ($state['schema_available'] ?? false) => 'GSC_SCHEMA_UNAVAILABLE',
            ! ($state['eligible_rows'] ?? false) => 'GSC_NO_ELIGIBLE_ROWS',
            $state['stale'] ?? false => 'GSC_STALE',
            ! ($state['quality_passed'] ?? false) => 'GSC_QUALITY_HOLD',
            ! ($state['mapping_valid'] ?? false) => 'GSC_MAPPING_FAILED',
            ! ($state['authority_valid'] ?? false) => 'GSC_AUTHORITY_CONFLICT',
            ! ($state['readmodel_healthy'] ?? false) => 'GSC_READMODEL_UNHEALTHY',
            ! ($state['window_complete'] ?? false) => 'GSC_WINDOW_INCOMPLETE',
            default => MeasurementEvidenceLoadResult::NONE,
        };
    }

    /** @param array<string, bool> $state */
    public function cro(array $state): string
    {
        return match (true) {
            ! ($state['schema_available'] ?? false) => 'CRO_SCHEMA_UNAVAILABLE',
            $state['stale'] ?? false => 'CRO_STALE',
            ! ($state['readmodel_healthy'] ?? false) => 'CRO_READMODEL_UNHEALTHY',
            ! ($state['window_complete'] ?? false) => 'CRO_WINDOW_INCOMPLETE',
            ! ($state['mapping_valid'] ?? false) => 'CRO_MAPPING_FAILED',
            ! ($state['stage_coverage_complete'] ?? false) => 'CRO_STAGE_COVERAGE_INCOMPLETE',
            default => MeasurementEvidenceLoadResult::NONE,
        };
    }
}
