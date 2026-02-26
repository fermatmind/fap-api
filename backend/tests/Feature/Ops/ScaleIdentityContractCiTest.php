<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ScaleIdentityContractCiTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_scale_questions_accept_legacy_and_v2_codes(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

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
            $legacyResponse->assertStatus(200);
            $legacyResponse->assertJsonPath('ok', true);
            $this->assertIsArray($legacyResponse->json('questions.items'));

            $v2Response = $this->getJson('/api/v0.3/scales/'.$v2.'/questions?region=CN_MAINLAND&locale=zh-CN');
            $v2Response->assertStatus(200);
            $v2Response->assertJsonPath('ok', true);
            $this->assertIsArray($v2Response->json('questions.items'));
        }
    }

    public function test_strict_gate_passes_with_contract_thresholds(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

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
        putenv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX=1');
        putenv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX=1');

        try {
            $exitCode = Artisan::call('ops:scale-identity-gate', [
                '--json' => '1',
                '--strict' => '1',
                '--hours' => '336',
                '--max-rows' => '5000',
            ]);

            $this->assertSame(0, $exitCode);

            $payload = json_decode(trim((string) Artisan::output()), true);
            $this->assertIsArray($payload);
            $this->assertTrue((bool) ($payload['ok'] ?? false));
            $this->assertTrue((bool) ($payload['pass'] ?? false));
            $this->assertSame([], $payload['violations'] ?? null);
        } finally {
            $this->restoreEnv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX', $previous['FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX', $previous['FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX', $previous['FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX', $previous['FAP_GATE_LEGACY_CODE_HIT_RATE_MAX']);
            $this->restoreEnv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX', $previous['FAP_GATE_DEMO_SCALE_HIT_RATE_MAX']);
        }
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
