<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2ProductionOpsTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/production_ops/v0_1';

    public function test_production_ops_package_defines_redacted_metrics_without_enabling_rollout(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_production_ops_report_v0_1.json');
        $validation = $this->jsonFile('big5_v2_production_ops_validation_v0_1.json');

        foreach ([$manifest, $report, $validation] as $document) {
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_pilot'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
            $this->assertTrue((bool) ($document['production_ops_reporting_ready'] ?? false));
        }

        $this->assertSame('count_and_rate_only', data_get($report, 'metrics.v2_payload_coverage_rate.redaction'));
        $this->assertSame('count_and_rate_only', data_get($report, 'metrics.fallback_hit_rate.redaction'));
        $this->assertSame('enum_counts_only', data_get($report, 'metrics.malformed_rejection_reasons.redaction'));
        $this->assertSame('integer_count_only', data_get($report, 'metrics.validation_error_count.redaction'));
        $this->assertSame('timestamp_only', data_get($report, 'metrics.audited_at_freshness.redaction'));
        $this->assertContains('report_json', data_get($report, 'ops_surfaces.report_pdf_center.raw_fields_hidden', []));
        $this->assertContains('payload_json', data_get($report, 'ops_surfaces.report_pdf_center.raw_fields_hidden', []));
        $this->assertSame('pass', data_get($validation, 'checks.raw_fields_hidden'));
        $this->assertSame('pass', data_get($validation, 'checks.production_rollout_not_enabled'));
    }

    public function test_production_ops_smoke_requires_redacted_live_result_and_pdf_checks(): void
    {
        $smoke = $this->jsonFile('big5_v2_production_ops_smoke_v0_1.json');

        $this->assertSame('required_for_operator_run', data_get($smoke, 'smoke_contract.fresh_anonymous_big5_live_sample'));
        $this->assertSame('redacted_evidence_only', data_get($smoke, 'smoke_contract.report_json_fetch'));
        $this->assertSame('redacted_text_evidence_only', data_get($smoke, 'smoke_contract.report_pdf_fetch'));
        $this->assertSame('must_not_appear', data_get($smoke, 'smoke_contract.pdf_private_link_check'));
        $this->assertSame('must_not_expose_internal_tokens', data_get($smoke, 'smoke_contract.footer_check'));
        $this->assertSame('must_not_appear', data_get($smoke, 'smoke_contract.legacy_engine_label_check'));

        foreach ([
            'stores_real_attempt_identifier',
            'stores_private_link',
            'stores_pdf_file',
            'stores_raw_report_body',
            'stores_user_score_values',
        ] as $policy) {
            $this->assertFalse((bool) data_get($smoke, "evidence_output_policy.{$policy}", true), $policy);
        }

        foreach ([
            'private URL',
            'attempt id',
            'Big Five Report Engine',
            'PR3B',
            'AttemptReadController',
            'payload',
            'registry',
            'raw scores',
            'shareable percentiles',
            'internal metadata',
            '[object Object]',
        ] as $token) {
            $this->assertContains($token, $smoke['forbidden_public_text_tokens'] ?? []);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);
        $this->assertCount(5, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::BASE_PATH.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
