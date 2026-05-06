<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2PdfSurfacePolicyTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/pdf_surface_policy/v0_1';

    public function test_pdf_policy_is_advisory_only_and_not_runtime(): void
    {
        $policy = $this->jsonFile('big5_pdf_surface_policy_v0_1.json');
        $summary = $this->jsonFile('big5_pdf_surface_policy_summary_v0_1.json');

        foreach ([$policy, $summary] as $document) {
            $this->assertSame('pdf_surface_policy_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertSame('pdf', $document['surface_key'] ?? null);
            $this->assertSame('no_go', $document['production_status'] ?? null);
        }
    }

    public function test_pdf_surface_remains_pending_until_adapter_and_rendered_qa_exist(): void
    {
        $policy = $this->jsonFile('big5_pdf_surface_policy_v0_1.json');
        $summary = $this->jsonFile('big5_pdf_surface_policy_summary_v0_1.json');

        $this->assertSame('disabled_or_pending', $policy['policy_status'] ?? null);
        $this->assertSame('pending_surface', $policy['rendered_status'] ?? null);
        $this->assertFalse((bool) ($policy['can_count_as_pass'] ?? true));
        $this->assertSame('no_go', $policy['pdf_surface_public_pilot_status'] ?? null);
        $this->assertTrue((bool) ($summary['adapter_required'] ?? false));
        $this->assertTrue((bool) ($summary['rendered_qa_required'] ?? false));
        $this->assertTrue((bool) ($summary['metadata_leak_scan_required'] ?? false));
        $this->assertTrue((bool) ($summary['fail_closed_required'] ?? false));
    }

    public function test_pdf_policy_requires_backend_payload_only_and_fail_closed_behavior(): void
    {
        $policy = $this->jsonFile('big5_pdf_surface_policy_v0_1.json');

        $this->assertSame('validated_route_driven_big5_result_page_v2_payload', data_get($policy, 'adapter_policy.input'));
        $this->assertSame('backend_payload_only', data_get($policy, 'adapter_policy.allowed_content_source'));
        $this->assertFalse((bool) data_get($policy, 'adapter_policy.frontend_authored_body_allowed', true));
        $this->assertSame('fail_closed', data_get($policy, 'adapter_policy.invalid_payload_behavior'));
        $this->assertTrue((bool) data_get($policy, 'adapter_policy.metadata_filter_required'));
        $this->assertFalse((bool) data_get($policy, 'adapter_policy.production_enablement_allowed', true));
    }

    public function test_forbidden_terms_are_tracked_for_future_rendered_qa(): void
    {
        $policy = $this->jsonFile('big5_pdf_surface_policy_v0_1.json');
        $forbidden = (array) ($policy['forbidden_public_terms'] ?? []);

        foreach ([
            'frontend_fallback',
            'internal_metadata',
            'selector_basis',
            'source_reference',
            'production_use_allowed',
            'runtime_use',
            'review_status',
            'qa_notes',
            '[object Object]',
        ] as $term) {
            $this->assertContains($term, $forbidden);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
