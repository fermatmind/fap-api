<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class AlertTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/production_rollout_alerts/v0_1';

    private const REQUIRED_ALERTS = [
        'rollout_failure_alert',
        'payload_validation_alert',
        'metadata_leak_alert',
        'fallback_generation_alert',
        'rollout_freeze_alert',
    ];

    private const REQUIRED_DASHBOARDS = [
        'rollout_health',
        'payload_integrity',
        'selector_suppression',
        'rollout_freeze',
    ];

    public function test_alert_package_exists_without_rollout_enablement(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $policy = $this->jsonFile('big5_v2_production_rollout_alerts_policy_v0_1.json');
        $dashboards = $this->jsonFile('big5_v2_production_rollout_dashboard_definitions_v0_1.json');
        $validation = $this->jsonFile('big5_v2_production_rollout_alert_validation_v0_1.json');

        $this->assertSame('big5_v2_production_rollout_alerts', $manifest['package'] ?? null);
        foreach ([$manifest, $policy, $dashboards, $validation] as $document) {
            $this->assertProductionDisabled($document);
        }
    }

    public function test_alert_thresholds_and_escalation_path_are_defined(): void
    {
        $policy = $this->jsonFile('big5_v2_production_rollout_alerts_policy_v0_1.json');
        $alerts = (array) ($policy['alert_thresholds'] ?? []);

        $this->assertSame(self::REQUIRED_ALERTS, array_keys($alerts));
        foreach (self::REQUIRED_ALERTS as $alertKey) {
            $alert = (array) ($alerts[$alertKey] ?? []);
            $this->assertNotSame('', (string) ($alert['metric'] ?? ''), $alertKey);
            $this->assertContains($alert['severity'] ?? null, ['sev0', 'sev1', 'sev2'], $alertKey);
            $this->assertGreaterThan(0, (int) ($alert['window_minutes'] ?? 0), $alertKey);
            $this->assertIsArray($alert['threshold'] ?? null, $alertKey);
            $this->assertTrue((bool) ($alert['halt_rollout_on_trigger'] ?? false), $alertKey);
        }

        $escalation = (array) ($policy['incident_escalation_path'] ?? []);
        $this->assertSame('production-rollout-oncall', $escalation['primary_channel'] ?? null);
        $this->assertContains('rollout_commander', $escalation['required_roles'] ?? []);
        $this->assertContains('backend_owner', $escalation['required_roles'] ?? []);
        $this->assertSame('rollout_commander', $escalation['release_halt_authority'] ?? null);
    }

    public function test_dashboard_and_report_definitions_cover_rollout_telemetry(): void
    {
        $dashboards = $this->jsonFile('big5_v2_production_rollout_dashboard_definitions_v0_1.json');
        $dashboardMap = (array) ($dashboards['dashboards'] ?? []);

        $this->assertSame(self::REQUIRED_DASHBOARDS, array_keys($dashboardMap));
        $this->assertSame(60, $dashboards['dashboard_refresh']['minimum_refresh_seconds'] ?? null);
        $this->assertTrue((bool) ($dashboards['dashboard_refresh']['requires_structured_events'] ?? false));
        $this->assertTrue((bool) ($dashboards['dashboard_refresh']['requires_no_pii'] ?? false));

        $allPanels = [];
        foreach ($dashboardMap as $dashboard) {
            $allPanels = array_merge($allPanels, (array) ($dashboard['panels'] ?? []));
        }

        foreach ([
            'rollout_attach_count',
            'rollout_deny_count',
            'rollout_percentage',
            'payload_validation_error_count',
            'route_lookup_failure_count',
            'composer_failure_count',
            'metadata_leak_count',
            'fallback_generation_count',
            'fail_closed_count',
        ] as $metric) {
            $this->assertContains($metric, $allPanels, $metric);
        }
    }

    public function test_alert_validation_passes_and_preserves_no_go_decision(): void
    {
        $validation = $this->jsonFile('big5_v2_production_rollout_alert_validation_v0_1.json');

        $this->assertSame(self::REQUIRED_ALERTS, $validation['validated_alerts'] ?? null);
        foreach ((array) ($validation['checks'] ?? []) as $check => $status) {
            $this->assertSame('pass', $status, (string) $check);
        }

        $this->assertSame('NO-GO', $validation['production_decision']['status'] ?? null);
    }

    public function test_alert_files_do_not_enable_production_rollout(): void
    {
        foreach ([
            'manifest.json',
            'big5_v2_production_rollout_alerts_policy_v0_1.json',
            'big5_v2_production_rollout_dashboard_definitions_v0_1.json',
            'big5_v2_production_rollout_alert_validation_v0_1.json',
        ] as $fileName) {
            $normalized = $this->normalizedFile($fileName);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"rollout_allowed":true', $normalized, $fileName);
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
     * @param  array<string,mixed>  $document
     */
    private function assertProductionDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['rollout_allowed'] ?? true));
    }

    private function normalizedFile(string $fileName): string
    {
        $contents = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($contents, $fileName);
        $normalized = preg_replace('/\s+/', '', $contents);
        $this->assertIsString($normalized, $fileName);

        return $normalized;
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
