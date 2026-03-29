<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ScaleIdentityGateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_command_outputs_expected_metrics_in_json_payload(): void
    {
        $this->insertAttempt('MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '11111111-1111-4111-8111-111111111111');
        $this->insertAttempt('DEMO_ANSWERS', null, null);

        $exitCode = Artisan::call('ops:scale-identity-gate', [
            '--json' => '1',
            '--hours' => '336',
            '--max-rows' => '1000',
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertTrue(array_key_exists('metrics', $payload));
        $this->assertTrue(array_key_exists('thresholds', $payload));

        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
        $this->assertArrayHasKey('identity_resolve_mismatch_rate', $metrics);
        $this->assertArrayHasKey('dual_write_mismatch_rate', $metrics);
        $this->assertArrayHasKey('content_path_fallback_rate', $metrics);
        $this->assertArrayHasKey('legacy_code_hit_rate', $metrics);
        $this->assertArrayHasKey('demo_scale_hit_rate', $metrics);

        $demo = is_array($metrics['demo_scale_hit_rate'] ?? null) ? $metrics['demo_scale_hit_rate'] : [];
        $this->assertSame(1, (int) ($demo['numerator'] ?? 0));
        $this->assertSame(2, (int) ($demo['denominator'] ?? 0));
        $this->assertSame(0.5, (float) ($demo['rate'] ?? -1));
    }

    public function test_gate_command_strict_mode_fails_when_dual_write_mismatch_exists(): void
    {
        $this->insertAttempt('MBTI', 'EQ_EMOTIONAL_INTELLIGENCE', '11111111-1111-4111-8111-111111111111');

        $previous = [
            'FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX' => getenv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX'),
            'FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX' => getenv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX'),
            'FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX' => getenv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX'),
            'FAP_GATE_LEGACY_CODE_HIT_RATE_MAX' => getenv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX'),
            'FAP_GATE_DEMO_SCALE_HIT_RATE_MAX' => getenv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX'),
        ];

        putenv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX=1');
        putenv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX=0');
        putenv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX=1');
        putenv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX=1');
        putenv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX=1');

        try {
            $exitCode = Artisan::call('ops:scale-identity-gate', [
                '--json' => '1',
                '--strict' => '1',
                '--hours' => '336',
                '--max-rows' => '1000',
            ]);
            $this->assertSame(1, $exitCode);

            $payload = json_decode(trim((string) Artisan::output()), true);
            $this->assertIsArray($payload);
            $this->assertFalse((bool) ($payload['pass'] ?? true));
            $violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
            $this->assertNotEmpty($violations);
        } finally {
            $this->restoreEnv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX', $previous['FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX', $previous['FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX', $previous['FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX', $previous['FAP_GATE_LEGACY_CODE_HIT_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX', $previous['FAP_GATE_DEMO_SCALE_HIT_RATE_MAX']);
        }
    }

    public function test_gate_command_strict_mode_fails_when_legacy_or_demo_hits_exist_under_zero_thresholds(): void
    {
        $this->insertAttempt('MBTI', 'MBTI_PERSONALITY_TEST_16_TYPES', '11111111-1111-4111-8111-111111111111');
        $this->insertAttempt('DEMO_ANSWERS', null, null);

        $exitCode = Artisan::call('ops:scale-identity-gate', [
            '--json' => '1',
            '--strict' => '1',
            '--hours' => '336',
            '--max-rows' => '1000',
        ]);
        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['pass'] ?? true));

        $violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
        $metrics = array_values(array_map(
            static fn (array $item): string => (string) ($item['metric'] ?? ''),
            array_filter($violations, 'is_array')
        ));

        $this->assertContains('legacy_code_hit_rate', $metrics);
        $this->assertContains('demo_scale_hit_rate', $metrics);
    }

    private function insertAttempt(string $scaleCode, ?string $scaleCodeV2, ?string $scaleUid): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'anon_id' => 'gate_'.strtolower(Str::random(10)),
            'user_id' => null,
            'scale_code' => strtoupper(trim($scaleCode)),
            'scale_version' => 'v0.3',
            'question_count' => 1,
            'answers_summary_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'qa',
            'referrer' => null,
            'started_at' => now(),
            'submitted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('attempts', 'scale_code_v2')) {
            $payload['scale_code_v2'] = $scaleCodeV2;
        }
        if (Schema::hasColumn('attempts', 'scale_uid')) {
            $payload['scale_uid'] = $scaleUid;
        }

        DB::table('attempts')->insert($payload);
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name.'='.$value);
    }
}
