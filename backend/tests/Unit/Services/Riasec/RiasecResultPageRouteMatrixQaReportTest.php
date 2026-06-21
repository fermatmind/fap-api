<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use PHPUnit\Framework\TestCase;

final class RiasecResultPageRouteMatrixQaReportTest extends TestCase
{
    public function test_route_matrix_and_golden_case_qa_reports_are_staging_only(): void
    {
        $base = dirname(__DIR__, 4).'/content_assets/riasec/result_page_v2';

        $routeRows = $this->readJson($base.'/route_matrix/staging_qa_v0_1/route_matrix_rows.json');
        $canonicalProfiles = $this->readJson($base.'/route_matrix/staging_qa_v0_1/canonical_profiles.json');
        $goldenCases = $this->readJson($base.'/route_matrix/staging_qa_v0_1/golden_cases.json');
        $routeReport = $this->readJson($base.'/qa/route_matrix_golden_case_qa/v0_1/route_matrix_report.json');
        $goldenReport = $this->readJson($base.'/qa/route_matrix_golden_case_qa/v0_1/golden_case_report.json');
        $selectorReport = $this->readJson($base.'/qa/route_matrix_golden_case_qa/v0_1/selector_ref_resolution_report.json');
        $conflictReport = $this->readJson($base.'/qa/route_matrix_golden_case_qa/v0_1/conflict_resolution_report.json');

        foreach ([$routeRows, $canonicalProfiles, $goldenCases, $routeReport, $goldenReport, $selectorReport, $conflictReport] as $payload) {
            self::assertSame('staging_only', $payload['runtime_use']);
            self::assertFalse($payload['production_use_allowed']);
            self::assertFalse($payload['ready_for_runtime']);
            self::assertFalse($payload['ready_for_production']);
            self::assertFalse($payload['cms_write_performed']);
            self::assertFalse($payload['runtime_change_performed']);
            self::assertFalse($payload['frontend_fallback_allowed']);
            self::assertFalse($payload['private_payload_exported']);
        }

        self::assertSame(7, $routeRows['row_count']);
        self::assertSame(7, $canonicalProfiles['canonical_profile_count']);
        self::assertSame(7, $goldenCases['golden_case_count']);
        self::assertSame(7, $routeReport['route_matrix_row_count']);
        self::assertSame(7, $routeReport['canonical_profile_count']);
        self::assertSame(7, $goldenReport['golden_case_count']);
        self::assertSame('partial_go_share_safety_only', $routeReport['staging_selector_input_readiness']);
        self::assertSame('no_go', $routeReport['runtime_selector_input_readiness']);
        self::assertSame('partial_pass_share_safety_only', $selectorReport['resolution_status']);

        $resolvedRefs = array_column($selectorReport['resolved_refs'], 'selector_ref');
        self::assertContains('candidate.share_safety_registry.share_boundary_summary.final.v0_1', $resolvedRefs);
        self::assertCount(6, $selectorReport['fail_closed_routes_without_refs']);
        self::assertSame(0, $conflictReport['mismatch_count']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
