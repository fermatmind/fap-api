<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2ProductionOpsMetrics;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_report_snapshots_metrics_service_returns_redacted_count_rate_enum_summary(): void
    {
        $this->ensureReportSnapshotsTable();

        $now = now();
        $attemptIds = [];
        $rows = [
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 5, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 6, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'fallback', 'reason' => 'production_rollout_denied', 'errors' => 0, 'minutes_ago' => 7, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'fallback', 'reason' => 'locked_or_free_preview', 'errors' => 0, 'minutes_ago' => 8, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'invalid', 'reason' => 'payload_validation_failed', 'errors' => 3, 'minutes_ago' => 9, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'disabled', 'reason' => 'production_runtime_disabled', 'errors' => 0, 'minutes_ago' => 10, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'invalid', 'reason' => 'route_input_invalid', 'errors' => 2, 'minutes_ago' => 60 * 24 * 50, 'scale' => 'BIG5_OCEAN'],
            ['status' => 'attached', 'reason' => 'v2_attached', 'errors' => 0, 'minutes_ago' => 4, 'scale' => 'MBTI_16'],
        ];

        foreach ($rows as $index => $row) {
            $attemptId = (string) Str::uuid();
            $attemptIds[] = $attemptId;

            DB::table('report_snapshots')->insert([
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'order_no' => null,
                'scale_code' => $row['scale'],
                'pack_id' => $row['scale'],
                'dir_version' => 'v1',
                'scoring_spec_version' => 'big5_spec_2026Q2_form90_v1',
                'report_engine_version' => 'v1.2',
                'big5_result_page_v2_status' => $row['status'],
                'big5_result_page_v2_fallback_reason' => $row['reason'],
                'big5_result_page_v2_validation_error_count' => $row['errors'],
                'big5_result_page_v2_audited_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
                'snapshot_version' => 'v1',
                'report_json' => json_encode(['variant' => 'full', 'row' => $index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'ready',
                'last_error' => null,
                'created_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
                'updated_at' => $now->copy()->subMinutes((int) $row['minutes_ago']),
            ]);
        }

        try {
            $summary = app(BigFiveResultPageV2ProductionOpsMetrics::class)->summarize(45);

            $this->assertSame('report_snapshots', $summary['source'] ?? null);
            $this->assertSame('ready', $summary['query_status'] ?? null);
            $this->assertSame([], $summary['blockers'] ?? ['unexpected']);
            $this->assertSame(6, data_get($summary, 'metrics.total_big5_reports'));
            $this->assertSame(2, data_get($summary, 'metrics.attached_count'));
            $this->assertSame(2, data_get($summary, 'metrics.fallback_count'));
            $this->assertSame(1, data_get($summary, 'metrics.invalid_count'));
            $this->assertSame(1, data_get($summary, 'metrics.disabled_or_not_evaluated_count'));
            $this->assertSame('33.3%', data_get($summary, 'metrics.v2_payload_coverage_rate'));
            $this->assertSame('50.0%', data_get($summary, 'metrics.fallback_hit_rate'));
            $this->assertSame(3, data_get($summary, 'metrics.validation_error_count'));
            $this->assertSame([
                'payload_validation_failed' => 1,
            ], data_get($summary, 'metrics.malformed_rejection_reasons'));
            $this->assertSame([
                'locked_or_free_preview' => 1,
                'production_rollout_denied' => 1,
            ], data_get($summary, 'metrics.fallback_reasons'));
            $this->assertSame('not_returned', data_get($summary, 'redaction.report_body_fields'));

            $encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertIsString($encoded);
            foreach (['attempt_id', 'private_url', 'report_json', 'report_full_json', 'raw_score', '[object Object]'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $encoded, $forbiddenToken);
            }
        } finally {
            DB::table('report_snapshots')->whereIn('attempt_id', $attemptIds)->delete();
        }
    }

    public function test_report_snapshots_metrics_service_fails_closed_when_table_is_missing(): void
    {
        if (Schema::hasTable('report_snapshots')) {
            Schema::drop('report_snapshots');
        }

        $summary = app(BigFiveResultPageV2ProductionOpsMetrics::class)->summarize(45);

        $this->assertSame('blocked', $summary['query_status'] ?? null);
        $this->assertSame(0, data_get($summary, 'metrics.total_big5_reports'));
        $this->assertSame('missing_report_snapshots_table', data_get($summary, 'blockers.0.code'));
        $this->assertSame('not_returned', data_get($summary, 'redaction.report_body_fields'));
    }

    private function ensureReportSnapshotsTable(): void
    {
        if (Schema::hasTable('report_snapshots')) {
            return;
        }

        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('org_id')->default(0);
            $table->string('attempt_id');
            $table->string('order_no')->nullable();
            $table->string('scale_code')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('dir_version')->nullable();
            $table->string('scoring_spec_version')->nullable();
            $table->string('report_engine_version')->nullable();
            $table->string('big5_result_page_v2_status')->nullable();
            $table->string('big5_result_page_v2_fallback_reason')->nullable();
            $table->unsignedSmallInteger('big5_result_page_v2_validation_error_count')->default(0);
            $table->timestamp('big5_result_page_v2_audited_at')->nullable();
            $table->string('snapshot_version')->nullable();
            $table->json('report_json')->nullable();
            $table->string('status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
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
