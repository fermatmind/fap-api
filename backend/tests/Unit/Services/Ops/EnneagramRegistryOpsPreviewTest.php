<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\EnneagramRegistryOpsService;
use Tests\TestCase;

final class EnneagramRegistryOpsPreviewTest extends TestCase
{
    public function test_preview_exposes_technical_note_sample_reports_and_registry_coverage(): void
    {
        $service = app(EnneagramRegistryOpsService::class);

        $preview = $service->preview();

        $this->assertSame(9, (int) data_get($preview, 'coverage.type_count'));
        $this->assertSame(15, (int) data_get($preview, 'coverage.p0_pair_coverage_count'));
        $this->assertSame(7, (int) data_get($preview, 'coverage.observation_day_coverage'));
        $this->assertSame(3, (int) data_get($preview, 'coverage.sample_report_count'));
        $this->assertGreaterThanOrEqual(13, (int) data_get($preview, 'coverage.technical_note_sections_count'));
        $this->assertTrue((bool) data_get($preview, 'technical_note_preview.disclaimers_present'));
        $this->assertTrue((bool) data_get($preview, 'technical_note_preview.unsupported_claims_guard.no_clinical_claim'));
        $this->assertTrue((bool) data_get($preview, 'technical_note_preview.unsupported_claims_guard.no_hiring_screening_claim'));
    }
}
