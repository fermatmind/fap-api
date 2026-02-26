<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ScaleIdentityHardCutoverCiTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_scale_questions_reject_legacy_and_accept_v2_codes_under_hard_cutover(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->applyHardCutoverConfig();

        $pairs = [
            ['legacy' => 'MBTI', 'v2' => 'MBTI_PERSONALITY_TEST_16_TYPES'],
            ['legacy' => 'BIG5_OCEAN', 'v2' => 'BIG_FIVE_OCEAN_MODEL'],
            ['legacy' => 'CLINICAL_COMBO_68', 'v2' => 'CLINICAL_DEPRESSION_ANXIETY_PRO'],
            ['legacy' => 'SDS_20', 'v2' => 'DEPRESSION_SCREENING_STANDARD'],
            ['legacy' => 'IQ_RAVEN', 'v2' => 'IQ_INTELLIGENCE_QUOTIENT'],
            ['legacy' => 'EQ_60', 'v2' => 'EQ_EMOTIONAL_INTELLIGENCE'],
        ];

        foreach ($pairs as $pair) {
            $legacy = (string) ($pair['legacy'] ?? '');
            $v2 = (string) ($pair['v2'] ?? '');

            $legacyResponse = $this->getJson('/api/v0.3/scales/'.$legacy.'/questions?region=CN_MAINLAND&locale=zh-CN');
            $legacyResponse->assertStatus(410);
            $legacyResponse->assertJsonPath('error_code', 'SCALE_CODE_LEGACY_NOT_ACCEPTED');
            $legacyResponse->assertJsonPath('details.requested_scale_code', $legacy);
            $legacyResponse->assertJsonPath('details.scale_code_legacy', $legacy);
            $legacyResponse->assertJsonPath('details.replacement_scale_code_v2', $v2);

            $v2Response = $this->getJson('/api/v0.3/scales/'.$v2.'/questions?region=CN_MAINLAND&locale=zh-CN');
            $v2Response->assertStatus(200);
            $v2Response->assertJsonPath('ok', true);
            $this->assertIsArray($v2Response->json('questions.items'));
        }
    }

    public function test_mode_audit_and_gate_strict_pass_under_hard_cutover_thresholds(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->applyHardCutoverConfig();

        $previous = [
            'FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX' => getenv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX'),
            'FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX' => getenv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX'),
            'FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX' => getenv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX'),
            'FAP_GATE_LEGACY_CODE_HIT_RATE_MAX' => getenv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX'),
            'FAP_GATE_DEMO_SCALE_HIT_RATE_MAX' => getenv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX'),
        ];

        putenv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX=0');
        putenv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX=0');
        putenv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX=0');
        putenv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX=0');
        putenv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX=0');

        try {
            $modeAuditExitCode = Artisan::call('ops:scale-identity-mode-audit', [
                '--json' => '1',
                '--strict' => '1',
            ]);
            $this->assertSame(0, $modeAuditExitCode);

            $modeAuditPayload = json_decode(trim((string) Artisan::output()), true);
            $this->assertIsArray($modeAuditPayload);
            $this->assertTrue((bool) ($modeAuditPayload['ok'] ?? false));
            $this->assertTrue((bool) ($modeAuditPayload['pass'] ?? false));
            $this->assertSame([], $modeAuditPayload['violations'] ?? null);

            $gateExitCode = Artisan::call('ops:scale-identity-gate', [
                '--json' => '1',
                '--strict' => '1',
                '--hours' => '336',
                '--max-rows' => '5000',
            ]);
            $this->assertSame(0, $gateExitCode);

            $gatePayload = json_decode(trim((string) Artisan::output()), true);
            $this->assertIsArray($gatePayload);
            $this->assertTrue((bool) ($gatePayload['ok'] ?? false));
            $this->assertTrue((bool) ($gatePayload['pass'] ?? false));
            $this->assertSame([], $gatePayload['violations'] ?? null);
        } finally {
            $this->restoreEnv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX', $previous['FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX', $previous['FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX', $previous['FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX', $previous['FAP_GATE_LEGACY_CODE_HIT_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX', $previous['FAP_GATE_DEMO_SCALE_HIT_RATE_MAX']);
        }
    }

    private function applyHardCutoverConfig(): void
    {
        Config::set('scale_identity.write_mode', 'dual');
        Config::set('scale_identity.read_mode', 'v2');
        Config::set('scale_identity.accept_legacy_scale_code', false);
        Config::set('scale_identity.api_response_scale_code_mode', 'v2');
        Config::set('scale_identity.allow_demo_scales', false);
        Config::set('scale_identity.content_path_mode', 'dual_prefer_new');
        Config::set('scale_identity.content_publish_mode', 'dual');
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
