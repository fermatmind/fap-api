<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Attempt;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Big5DisclaimerAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_questions_include_disclaimer_meta_and_start_persists_acceptance(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $questions = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=zh-CN');
        $questions->assertStatus(200);
        $questions->assertJsonPath('ok', true);

        $disclaimerVersion = (string) $questions->json('meta.disclaimer_version');
        $disclaimerHash = (string) $questions->json('meta.disclaimer_hash');
        $disclaimerText = (string) $questions->json('meta.disclaimer_text');

        $this->assertNotSame('', $disclaimerVersion);
        $this->assertNotSame('', $disclaimerHash);
        $this->assertNotSame('', $disclaimerText);

        $start = $this->withHeaders([
            'X-Anon-Id' => 'anon_big5_disclaimer_acceptance',
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_big5_disclaimer_acceptance',
            'meta' => [
                'channel' => 'ci',
            ],
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        $answersSummary = is_array($attempt->answers_summary_json) ? $attempt->answers_summary_json : [];
        $meta = is_array($answersSummary['meta'] ?? null) ? $answersSummary['meta'] : [];

        $this->assertSame('ci', (string) ($meta['channel'] ?? ''));
        $this->assertSame($disclaimerVersion, (string) ($meta['disclaimer_version_accepted'] ?? ''));
        $this->assertSame($disclaimerHash, (string) ($meta['disclaimer_hash'] ?? ''));
        $this->assertSame('zh-CN', (string) ($meta['disclaimer_locale'] ?? ''));
    }
}

