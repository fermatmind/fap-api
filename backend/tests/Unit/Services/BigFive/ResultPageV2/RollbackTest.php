<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Services\BigFive\ResultPageV2\Observability\BigFiveV2ProductionRolloutTelemetry;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class RollbackTest extends TestCase
{
    private const PACKAGE_PATH = 'content_assets/big5/result_page_v2/qa/production_rollout_rollback_kill_switch/v0_1';

    public function test_rollback_evidence_package_exists_without_rollout_enablement(): void
    {
        $manifest = $this->loadJsonDocument('manifest.json');

        $this->assertSame('production_rollout_rollback_kill_switch', $manifest['package']);
        $this->assertSame('NO_GO', $manifest['decision']);
        $this->assertDisabled($manifest);

        foreach ($manifest['files'] as $file) {
            $this->assertFileExists($this->packagePath($file));
        }
    }

    public function test_rollback_and_kill_switch_drills_are_complete(): void
    {
        $drills = $this->loadJsonDocument('big5_v2_production_rollout_rollback_kill_switch_drills_v0_1.json');

        $this->assertSame([
            'rollout_percentage_rollback',
            'allowlist_revoke',
            'release_snapshot_rollback',
            'fail_closed_recovery',
        ], array_column($drills['rollout_rollback_drills'], 'id'));

        $this->assertSame([
            'global_emergency_disable',
            'release_disable',
            'rollout_disabled_default',
        ], array_column($drills['kill_switch_drills'], 'id'));

        foreach (array_merge($drills['rollout_rollback_drills'], $drills['kill_switch_drills']) as $drill) {
            $this->assertSame('none', $drill['destructive_change'] ?? 'none');
        }

        $this->assertContains('fail_closed', $drills['observability_required']);
        $this->assertContains('payload_attached_false', $drills['observability_required']);
    }

    public function test_validation_preserves_no_go_and_default_off_runtime(): void
    {
        $validation = $this->loadJsonDocument('big5_v2_production_rollout_rollback_kill_switch_validation_v0_1.json');

        $this->assertSame('pass', $validation['validation_status']);
        $this->assertSame('NO_GO', $validation['production_decision']);
        $this->assertSame('pass', $validation['checks']['rollback_observability']);
        $this->assertSame('pass', $validation['checks']['runtime_enablement_absent']);
        $this->assertFalse($validation['required_runtime_defaults']['production_runtime_enabled']);
        $this->assertFalse($validation['required_runtime_defaults']['production_rollout_enabled']);
        $this->assertSame(0, $validation['required_runtime_defaults']['production_rollout_percentage']);
        $this->assertDisabled($validation);
    }

    public function test_rollback_observability_records_fail_closed_without_payload(): void
    {
        Log::spy();

        app(BigFiveV2ProductionRolloutTelemetry::class)->recordFailClosed(
            new Attempt([
                'id' => 'rollback-attempt',
                'anon_id' => 'rollback-anon',
                'user_id' => 'rollback-user',
                'org_id' => 42,
                'scale_code' => 'BIG5_OCEAN',
                'locale' => 'zh-CN',
                'answers_summary_json' => ['meta' => ['form_code' => 'big5_90']],
            ]),
            null,
            'production_rollout_emergency_disabled',
        );

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2ProductionRolloutTelemetry::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['metric_name'] ?? null) === 'fail_closed'
                && ($context['fail_closed_reason'] ?? null) === 'production_rollout_emergency_disabled'
                && ($context['payload_attached'] ?? null) === false
                && ($context['fail_closed_count'] ?? null) === 1
                && ! array_key_exists('payload', $context)
                && ! array_key_exists('internal_metadata', $context)),
        )->once();
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
