<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Sds20ConsentRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_requires_consent_and_persists_consent_snapshot(): void
    {
        (new ScaleRegistrySeeder())->run();

        $missing = $this->withHeaders([
            'X-Anon-Id' => 'anon_sds20_missing_consent',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SDS_20',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_sds20_missing_consent',
        ]);
        $missing->assertStatus(422);
        $missing->assertJsonPath('error_code', 'CONSENT_REQUIRED_SDS20');

        $questions = $this->getJson('/api/v0.3/scales/SDS_20/questions?locale=zh-CN');
        $questions->assertStatus(200);
        $consentVersion = (string) data_get($questions->json(), 'meta.consent.version', '');
        $this->assertNotSame('', $consentVersion);

        $ok = $this->withHeaders([
            'X-Anon-Id' => 'anon_sds20_with_consent',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SDS_20',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_sds20_with_consent',
            'consent' => [
                'accepted' => true,
                'version' => $consentVersion,
                'locale' => 'zh-CN',
            ],
        ]);
        $ok->assertStatus(200);

        $attemptId = (string) $ok->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        $summary = is_array($attempt->answers_summary_json) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $consent = is_array($meta['consent'] ?? null) ? $meta['consent'] : [];

        $this->assertSame(true, (bool) ($consent['accepted'] ?? false));
        $this->assertSame($consentVersion, (string) ($consent['version'] ?? ''));
        $this->assertSame('zh-CN', (string) ($consent['locale'] ?? ''));
        $this->assertNotSame('', (string) ($meta['disclaimer_hash'] ?? ''));
    }
}
