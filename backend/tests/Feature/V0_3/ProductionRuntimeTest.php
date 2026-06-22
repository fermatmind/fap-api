<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2AuditFields;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2TransformerContract;
use App\Services\Report\ReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class ProductionRuntimeTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    private const RUNTIME_ENABLEMENT_PREP_PATH = 'content_assets/big5/result_page_v2/qa/production_runtime_enablement_prep/v0_1';

    public function test_production_runtime_defaults_disabled(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_configured'));
        $this->assertFalse((bool) config('big5_result_page_v2.production_import_gate_passed'));
        $this->assertSame('', config('big5_result_page_v2.production_release_snapshot_id'));
        $this->assertSame([], config('big5_result_page_v2.production_approved_release_snapshot_ids'));
    }

    public function test_runtime_enablement_prep_package_documents_switch_path_without_enabling_runtime(): void
    {
        $manifest = $this->loadRuntimePrepJson('manifest.json');
        $prep = $this->loadRuntimePrepJson('big5_v2_production_runtime_enablement_prep_v0_1.json');
        $validation = $this->loadRuntimePrepJson('big5_v2_production_runtime_enablement_validation_v0_1.json');

        $this->assertSame('production_runtime_enablement_prep', $manifest['package'] ?? null);
        $this->assertSame('PREP_ONLY_RUNTIME_DISABLED', $manifest['production_decision'] ?? null);
        $this->assertSame('big5_result_page_v2_rc_0_3', $manifest['release_snapshot_id'] ?? null);
        $this->assertSame('big5_result_page_v2_rc_0_3', data_get($prep, 'release_snapshot.snapshot_id'));
        $this->assertSame(
            'BIG5_RESULT_PAGE_V2_PRODUCTION_RUNTIME_ENABLED',
            data_get($prep, 'runtime_switch_path.env_keys.runtime_enabled'),
        );
        $this->assertSame(
            'BIG5_RESULT_PAGE_V2_PRODUCTION_IMPORT_GATE_PASSED',
            data_get($prep, 'runtime_switch_path.env_keys.import_gate_passed'),
        );
        $this->assertTrue((bool) data_get($prep, 'runtime_switch_path.required_values_before_runtime_enablement.production_import_gate_passed'));
        $this->assertFalse((bool) data_get($prep, 'runtime_switch_path.required_values_remaining_disabled_in_this_pr.production_runtime_enabled'));
        $this->assertFalse((bool) data_get($prep, 'runtime_switch_path.required_values_remaining_disabled_in_this_pr.production_rollout_enabled'));
        $this->assertSame('blocked', data_get($validation, 'checks.production_runtime_enablement'));
        $this->assertSame('blocked', data_get($validation, 'checks.production_rollout_enablement'));
        $this->assertRuntimePrepDisabled($manifest);
        $this->assertRuntimePrepDisabled($prep);
        $this->assertRuntimePrepDisabled($validation);
    }

    public function test_runtime_enablement_prep_fail_closed_matrix_and_files_remain_redacted(): void
    {
        $matrix = $this->loadRuntimePrepJson('big5_v2_production_runtime_fail_closed_matrix_v0_1.json');

        $this->assertSame([
            'runtime_flag_default_off',
            'import_gate_missing',
            'snapshot_id_missing',
            'snapshot_not_approved',
            'release_disabled',
            'emergency_disabled',
            'rollout_not_configured',
        ], array_column($matrix['fail_closed_matrix'] ?? [], 'id'));
        $this->assertRuntimePrepDisabled($matrix);

        foreach (glob($this->runtimePrepPath('*')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $contents = (string) file_get_contents($file);
            $normalized = preg_replace('/\s+/', '', $contents);

            foreach ([
                'attempt_id',
                'private_url',
                'report_json',
                'report_full_json',
                'report_free_json',
                'payload_json',
                'raw_scores',
                'Big Five Report Engine',
                'PR3B',
                'AttemptReadController',
                '[object Object]',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $contents, $file);
            }

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $file);
            $this->assertStringNotContainsString('"ready_for_runtime":true', $normalized, $file);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $file);
            $this->assertStringNotContainsString('"production_runtime_enabled":true', $normalized, $file);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $file);
            $this->assertStringNotContainsString('"rollout_allowed":true', $normalized, $file);
        }
    }

    public function test_runtime_enablement_prep_sha256sums_are_reproducible(): void
    {
        $lines = array_values(array_filter(array_map('trim', explode(
            "\n",
            (string) file_get_contents($this->runtimePrepPath('SHA256SUMS')),
        ))));

        $this->assertCount(5, $lines);

        foreach ($lines as $line) {
            [$hash, $file] = preg_split('/\s+/', $line, 2) ?: ['', ''];
            $file = trim($file);

            $this->assertSame(
                hash_file('sha256', $this->runtimePrepPath($file)),
                $hash,
                $file,
            );
        }
    }

    public function test_production_legacy_runtime_fails_closed_without_governance_gates(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_result_page_v2.production_runtime_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $this->app->bind(BigFiveResultPageV2TransformerContract::class, static fn (): BigFiveResultPageV2TransformerContract => new class implements BigFiveResultPageV2TransformerContract
        {
            public function transform(array $input): array
            {
                throw new \RuntimeException('production runtime gate must fail closed before transformer');
            }
        });
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_default_blocked');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }

    public function test_production_runtime_requires_import_gate_and_approved_snapshot(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => false,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_missing_import_gate');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
    }

    public function test_production_runtime_attaches_only_when_all_governance_gates_pass(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_all_gates_pass');

        $payload = $this->appendRuntime($fixture);

        $this->assertIsArray($payload[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null);
    }

    public function test_production_runtime_audit_records_attach_and_rollout_fallback(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_audit_attach');

        $attached = $this->appendRuntimeWithAudit($fixture);

        $this->assertIsArray(data_get($attached, 'payload.'.BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame('attached', data_get($attached, 'audit.status'));
        $this->assertSame('v2_attached', data_get($attached, 'audit.fallback_reason'));
        $this->assertSame(0, data_get($attached, 'audit.validation_error_count'));

        config()->set('big5_result_page_v2.production_rollout_percentage', 0);

        $fallback = $this->appendRuntimeWithAudit($fixture);

        $this->assertNull(data_get($fallback, 'payload.'.BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame('fallback', data_get($fallback, 'audit.status'));
        $this->assertSame('production_rollout_denied', data_get($fallback, 'audit.fallback_reason'));
    }

    public function test_production_runtime_audit_records_locked_preview_fallback(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_audit_locked');

        $payload = app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabledWithAudit(
            $fixture['attempt'],
            $fixture['result'],
            [
                'report' => ['sections' => $fixture['legacy_sections']],
                'locked' => true,
                'access_level' => ReportAccess::REPORT_ACCESS_FREE,
                'modules_allowed' => [ReportAccess::MODULE_BIG5_CORE],
            ],
        );

        $this->assertNull(data_get($payload, 'payload.'.BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame('fallback', data_get($payload, 'audit.status'));
        $this->assertSame('locked_or_free_preview', data_get($payload, 'audit.fallback_reason'));
    }

    public function test_big5_v2_audit_fields_map_attached_report_payload_for_snapshots(): void
    {
        $fields = app(BigFiveResultPageV2AuditFields::class)->fromReportPayload([
            BigFiveResultPageV2Contract::PAYLOAD_KEY => [
                'payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
            ],
        ], BigFiveResultPageV2Contract::SCALE_CODE);

        $this->assertSame('attached', $fields['big5_result_page_v2_status']);
        $this->assertSame('v2_attached', $fields['big5_result_page_v2_fallback_reason']);
        $this->assertSame(0, $fields['big5_result_page_v2_validation_error_count']);
        $this->assertArrayHasKey('big5_result_page_v2_audited_at', $fields);
    }

    public function test_production_runtime_supports_rollback_by_release_disable(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
            'production_disabled_release_snapshot_ids' => ['snapshot_rc_test'],
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_release_disabled');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
    }

    public function test_production_runtime_supports_emergency_disable(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->setProductionRuntimeConfig([
            'production_runtime_enabled' => true,
            'production_rollout_configured' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
            'production_emergency_disabled' => true,
        ]);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_production_emergency_disabled');

        $payload = $this->appendRuntime($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    private function setProductionRuntimeConfig(array $values): void
    {
        $defaults = [
            'production_rollout_enabled' => true,
            'production_rollout_manual_approval_granted' => true,
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 100,
            'production_rollout_max_percentage' => 100,
            'production_rollout_allowed_scale_codes' => ['BIG5_OCEAN'],
            'production_rollout_allowed_form_codes' => ['big5_90'],
            'production_rollout_allowed_locales' => ['zh-CN'],
            'production_rollout_require_tenant_scope' => false,
        ];

        foreach (array_merge($defaults, $values) as $key => $value) {
            config()->set('big5_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function appendRuntime(array $fixture): array
    {
        return $this->appendRuntimeWithAudit($fixture)['payload'];
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array{payload:array<string,mixed>,audit:array<string,mixed>}
     */
    private function appendRuntimeWithAudit(array $fixture): array
    {
        return app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabledWithAudit(
            $fixture['attempt'],
            $fixture['result'],
            [
                'report' => ['sections' => $fixture['legacy_sections']],
                'locked' => false,
                'access_level' => ReportAccess::REPORT_ACCESS_FULL,
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                ],
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function loadRuntimePrepJson(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($this->runtimePrepPath($file)), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function runtimePrepPath(string $file): string
    {
        return base_path(self::RUNTIME_ENABLEMENT_PREP_PATH.'/'.$file);
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertRuntimePrepDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_runtime_enabled'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['rollout_allowed'] ?? true));
    }
}
