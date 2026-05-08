<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutGate;
use Tests\TestCase;

final class RolloutDryRunTest extends TestCase
{
    private const PACKAGE_PATH = 'content_assets/big5/result_page_v2/qa/production_rollout_real_user_dry_run/v0_1';

    public function test_dry_run_package_exists_without_real_rollout(): void
    {
        $manifest = $this->loadJsonDocument('manifest.json');
        $report = $this->loadJsonDocument('big5_v2_production_rollout_real_user_dry_run_report_v0_1.json');

        $this->assertSame('production_rollout_real_user_dry_run', $manifest['package']);
        $this->assertSame('NO_GO', $manifest['production_decision']);
        $this->assertSame('none', $manifest['real_user_exposure']);
        $this->assertSame('simulated_only', $report['scope']['audience']);
        $this->assertContains('explicit_human_production_rollout_approval', $report['required_before_real_exposure']);
        $this->assertDisabled($manifest);
        $this->assertDisabled($report);

        foreach ($manifest['files'] as $file) {
            $this->assertFileExists($this->packagePath($file));
        }
    }

    public function test_simulation_matrix_covers_required_dry_run_scenarios(): void
    {
        $matrix = $this->loadJsonDocument('big5_v2_production_rollout_real_user_dry_run_simulation_matrix_v0_1.json');

        $this->assertSame([
            'allowlisted_rollout_simulation',
            'percentage_rollout_simulation',
            'rollback_simulation',
            'blast_radius_simulation',
            'fail_closed_simulation',
            'incident_halt_simulation',
        ], array_column($matrix['simulations'], 'id'));

        foreach ($matrix['simulations'] as $simulation) {
            $this->assertSame('none', $simulation['real_user_exposure']);
            $this->assertSame('pass', $simulation['status']);
        }

        $this->assertContains('production_rollout_enabled', $matrix['must_not_enable']);
        $this->assertContains('production_runtime_enabled', $matrix['must_not_enable']);
    }

    public function test_validation_blocks_actual_rollout_and_real_user_exposure(): void
    {
        $validation = $this->loadJsonDocument('big5_v2_production_rollout_real_user_dry_run_validation_v0_1.json');

        $this->assertSame('NO_GO', $validation['production_decision']);
        $this->assertSame('pass', $validation['validation_status']);
        $this->assertSame('blocked', $validation['checks']['actual_rollout_enabled']);
        $this->assertSame('blocked', $validation['checks']['real_user_exposure']);
        $this->assertSame('pass', $validation['checks']['incident_halt_simulation']);
        $this->assertDisabled($validation);
    }

    public function test_allowlist_and_percentage_dry_run_use_existing_gate_without_default_enablement(): void
    {
        $this->assertFalse((bool) config('big5_result_page_v2.production_rollout_enabled'));

        $this->simulateRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
        ]);

        $allowlisted = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $denied = $this->gate()->decide($this->attempt(['id' => 'attempt_denied']));

        $this->assertTrue($allowlisted->allowed);
        $this->assertSame('production_rollout_allowed', $allowlisted->reason);
        $this->assertFalse($denied->allowed);
        $this->assertSame('production_rollout_allowlist_denied', $denied->reason);

        $allowedSeed = $this->seedForPercentage(5, true);
        $deniedSeed = $this->seedForPercentage(5, false);
        $this->simulateRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 5,
            'production_rollout_max_percentage' => 5,
        ]);

        $percentageAllowed = $this->gate()->decide($this->attempt(['id' => $allowedSeed]));
        $percentageDenied = $this->gate()->decide($this->attempt(['id' => $deniedSeed]));

        $this->assertTrue($percentageAllowed->allowed);
        $this->assertSame('rollout_percentage', $percentageAllowed->matchedBy);
        $this->assertFalse($percentageDenied->allowed);
        $this->assertSame('production_rollout_percentage_denied', $percentageDenied->reason);
        $this->assertFalse((bool) config('big5_result_page_v2.production_runtime_enabled'));
    }

    public function test_rollback_blast_radius_fail_closed_and_incident_halt_are_simulated_only(): void
    {
        $seed = $this->seedForPercentage(20, true);
        $this->simulateRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 20,
            'production_rollout_max_percentage' => 20,
        ]);

        $this->assertTrue($this->gate()->decide($this->attempt(['id' => $seed]))->allowed);

        config()->set('big5_result_page_v2.production_rollout_percentage', 0);
        $rolledBack = $this->gate()->decide($this->attempt(['id' => $seed]));
        $this->assertFalse($rolledBack->allowed);
        $this->assertSame('production_rollout_percentage_denied', $rolledBack->reason);

        $this->simulateRollout([
            'production_rollout_mode' => 'percentage',
            'production_rollout_percentage' => 50,
            'production_rollout_max_percentage' => 10,
        ]);
        $blastRadius = $this->gate()->decide($this->attempt());
        $this->assertFalse($blastRadius->allowed);
        $this->assertSame('production_rollout_invalid_config', $blastRadius->reason);
        $this->assertContains('production_rollout_blast_radius_exceeded', $blastRadius->errors);

        $this->simulateRollout([
            'production_rollout_manual_approval_granted' => false,
        ]);
        $this->assertSame(
            'production_rollout_manual_approval_missing',
            $this->gate()->decide($this->attempt())->reason,
        );

        config()->set('big5_result_page_v2.production_rollout_manual_approval_granted', true);
        config()->set('big5_result_page_v2.production_release_snapshot_id', '');
        $this->assertSame(
            'production_rollout_snapshot_missing',
            $this->gate()->decide($this->attempt())->reason,
        );

        $this->simulateRollout([
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_allowed_attempt_ids' => ['attempt_allowed'],
            'production_emergency_disabled' => true,
        ]);
        $halted = $this->gate()->decide($this->attempt(['id' => 'attempt_allowed']));
        $this->assertFalse($halted->allowed);
        $this->assertSame('production_rollout_emergency_disabled', $halted->reason);
    }

    public function test_files_do_not_enable_production_rollout(): void
    {
        foreach (glob($this->packagePath('*')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $normalized = preg_replace('/\s+/', '', (string) file_get_contents($file));

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $file);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $file);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $file);
            $this->assertStringNotContainsString('"rollout_allowed":true', $normalized, $file);
            $this->assertStringNotContainsString('[objectObject]', $normalized, $file);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $lines = array_values(array_filter(array_map('trim', explode(
            "\n",
            (string) file_get_contents($this->packagePath('SHA256SUMS')),
        ))));

        $this->assertNotSame([], $lines);

        foreach ($lines as $line) {
            [$hash, $file] = preg_split('/\s+/', $line, 2) ?: ['', ''];
            $file = trim($file);

            $this->assertSame(
                hash_file('sha256', $this->packagePath($file)),
                $hash,
                $file,
            );
        }
    }

    private function gate(): BigFiveV2ProductionRolloutGate
    {
        return new BigFiveV2ProductionRolloutGate;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function simulateRollout(array $overrides = []): void
    {
        foreach (array_merge([
            'production_rollout_enabled' => true,
            'production_rollout_configured' => true,
            'production_rollout_manual_approval_granted' => true,
            'production_import_gate_passed' => true,
            'production_release_snapshot_id' => 'snapshot_rc_test',
            'production_approved_release_snapshot_ids' => ['snapshot_rc_test'],
            'production_disabled_release_snapshot_ids' => [],
            'production_emergency_disabled' => false,
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_percentage' => 0,
            'production_rollout_max_percentage' => 0,
            'production_rollout_allowed_attempt_ids' => [],
            'production_rollout_allowed_user_ids' => [],
            'production_rollout_allowed_anon_ids' => [],
            'production_rollout_allowed_org_ids' => [],
            'production_rollout_allowed_tenant_ids' => ['42'],
            'production_rollout_allowed_scale_codes' => ['BIG5_OCEAN'],
            'production_rollout_allowed_form_codes' => ['big5_90'],
            'production_rollout_allowed_locales' => ['zh-CN'],
        ], $overrides) as $key => $value) {
            config()->set('big5_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function attempt(array $overrides = []): Attempt
    {
        return new Attempt(array_merge([
            'id' => 'attempt_default',
            'anon_id' => 'anon_default',
            'user_id' => 'user_default',
            'org_id' => 42,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'answers_summary_json' => ['meta' => ['form_code' => 'big5_90']],
        ], $overrides));
    }

    private function seedForPercentage(int $percentage, bool $allowed): string
    {
        for ($i = 0; $i < 10000; $i++) {
            $seed = 'dry_run_seed_'.$i;
            $bucket = hexdec(substr(hash('sha256', $seed), 0, 8)) % 10000;
            $isAllowed = $bucket < ($percentage * 100);

            if ($isAllowed === $allowed) {
                return $seed;
            }
        }

        $this->fail('Unable to find deterministic rollout seed.');
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJsonDocument(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($this->packagePath($file)), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function packagePath(string $file): string
    {
        return base_path(self::PACKAGE_PATH.'/'.$file);
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use']);
        $this->assertFalse((bool) $document['production_use_allowed']);
        $this->assertFalse((bool) $document['ready_for_production']);
        $this->assertFalse((bool) $document['production_rollout_enabled']);
        $this->assertFalse((bool) $document['rollout_allowed']);
    }
}
