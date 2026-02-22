<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ClinicalComboConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_gate_respects_feature_flag(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        Config::set('fap.features.clinical_consent_enforce', false);
        $startWithoutConsent = $this->withHeaders([
            'X-Anon-Id' => 'anon_cc68_consent_off',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'CLINICAL_COMBO_68',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_cc68_consent_off',
        ]);
        $startWithoutConsent->assertStatus(200);

        Config::set('fap.features.clinical_consent_enforce', true);
        $startMissingConsent = $this->withHeaders([
            'X-Anon-Id' => 'anon_cc68_consent_on_missing',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'CLINICAL_COMBO_68',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_cc68_consent_on_missing',
        ]);
        $startMissingConsent->assertStatus(422);
        $startMissingConsent->assertJsonPath('error_code', 'CONSENT_REQUIRED');

        $questions = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=zh-CN');
        $questions->assertStatus(200);
        $consentVersion = (string) data_get($questions->json(), 'meta.consent.version', '');
        $this->assertNotSame('', $consentVersion);

        $startWithConsent = $this->withHeaders([
            'X-Anon-Id' => 'anon_cc68_consent_on_ok',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'CLINICAL_COMBO_68',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_cc68_consent_on_ok',
            'consent' => [
                'accepted' => true,
                'version' => $consentVersion,
                'locale' => 'zh-CN',
            ],
        ]);
        $startWithConsent->assertStatus(200);

        $attemptId = (string) $startWithConsent->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        $summary = is_array($attempt->answers_summary_json) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $consent = is_array($meta['consent'] ?? null) ? $meta['consent'] : [];

        $this->assertSame(true, (bool) ($consent['accepted'] ?? false));
        $this->assertSame($consentVersion, (string) ($consent['version'] ?? ''));
        $this->assertSame('zh-CN', (string) ($consent['locale'] ?? ''));
    }
}
