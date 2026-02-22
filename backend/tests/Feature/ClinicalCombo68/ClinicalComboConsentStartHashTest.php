<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ClinicalComboConsentStartHashTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_rejects_mismatched_consent_hash_when_gate_enabled(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        Config::set('fap.features.clinical_consent_enforce', true);

        $questions = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=zh-CN');
        $questions->assertStatus(200);
        $consentVersion = (string) data_get($questions->json(), 'meta.consent.version', '');
        $consentHash = (string) data_get($questions->json(), 'meta.consent.hash', '');
        $this->assertNotSame('', $consentVersion);
        $this->assertNotSame('', $consentHash);

        $mismatch = $this->withHeaders([
            'X-Anon-Id' => 'anon_cc68_hash_mismatch',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'CLINICAL_COMBO_68',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_cc68_hash_mismatch',
            'consent' => [
                'accepted' => true,
                'version' => $consentVersion,
                'hash' => str_repeat('0', 64),
                'locale' => 'zh-CN',
            ],
        ]);
        $mismatch->assertStatus(422);
        $mismatch->assertJsonPath('error_code', 'CONSENT_MISMATCH');

        $ok = $this->withHeaders([
            'X-Anon-Id' => 'anon_cc68_hash_match',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'CLINICAL_COMBO_68',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_cc68_hash_match',
            'consent' => [
                'accepted' => true,
                'version' => $consentVersion,
                'hash' => $consentHash,
                'locale' => 'zh-CN',
            ],
        ]);

        $ok->assertStatus(200);
    }
}
