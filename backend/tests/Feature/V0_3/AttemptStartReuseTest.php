<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AttemptStartReuseTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
    }

    private function startAttempt(string $anonId, string $scaleCode = 'MBTI', ?string $formCode = null)
    {
        $payload = [
            'scale_code' => $scaleCode,
            'anon_id' => $anonId,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
        ];

        if ($formCode !== null) {
            $payload['form_code'] = $formCode;
        }

        return $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', $payload);
    }

    public function test_mbti_start_reuses_in_progress_attempt_and_draft(): void
    {
        $this->seedScales();

        $first = $this->startAttempt('anon-mbti-reuse', 'MBTI', 'mbti_93');
        $first->assertStatus(200);

        $second = $this->startAttempt('anon-mbti-reuse', 'MBTI', 'mbti_93');
        $second->assertStatus(200);

        $this->assertSame((string) $first->json('attempt_id'), (string) $second->json('attempt_id'));
        $this->assertSame(1, DB::table('attempts')->count());
        $this->assertSame(1, DB::table('attempt_drafts')->count());
        $this->assertNotSame('', (string) $second->json('resume_token'));
    }

    public function test_submitted_attempt_is_not_reused(): void
    {
        $this->seedScales();

        $first = $this->startAttempt('anon-submitted-skip', 'MBTI', 'mbti_93');
        $first->assertStatus(200);
        $firstAttemptId = (string) $first->json('attempt_id');

        DB::table('attempts')
            ->where('id', $firstAttemptId)
            ->update(['submitted_at' => now()]);

        $second = $this->startAttempt('anon-submitted-skip', 'MBTI', 'mbti_93');
        $second->assertStatus(200);

        $this->assertNotSame($firstAttemptId, (string) $second->json('attempt_id'));
        $this->assertSame(2, DB::table('attempts')->count());
        $this->assertSame(2, DB::table('attempt_drafts')->count());
    }

    public function test_expired_attempt_is_not_reused(): void
    {
        $this->seedScales();

        $first = $this->startAttempt('anon-expired-skip', 'MBTI', 'mbti_93');
        $first->assertStatus(200);
        $firstAttemptId = (string) $first->json('attempt_id');

        DB::table('attempts')
            ->where('id', $firstAttemptId)
            ->update(['resume_expires_at' => now()->subMinute()]);

        DB::table('attempt_drafts')
            ->where('attempt_id', $firstAttemptId)
            ->update(['expires_at' => now()->subMinute()]);

        $second = $this->startAttempt('anon-expired-skip', 'MBTI', 'mbti_93');
        $second->assertStatus(200);

        $this->assertNotSame($firstAttemptId, (string) $second->json('attempt_id'));
        $this->assertSame(2, DB::table('attempts')->count());
        $this->assertSame(2, DB::table('attempt_drafts')->count());
    }

    public function test_different_form_does_not_reuse_attempt(): void
    {
        $this->seedScales();

        $first = $this->startAttempt('anon-form-split', 'MBTI', 'mbti_93');
        $first->assertStatus(200);

        $second = $this->startAttempt('anon-form-split', 'MBTI', 'mbti_144');
        $second->assertStatus(200);

        $this->assertNotSame((string) $first->json('attempt_id'), (string) $second->json('attempt_id'));
        $this->assertSame(2, DB::table('attempts')->count());
        $this->assertSame(2, DB::table('attempt_drafts')->count());
        $this->assertSame('mbti_144', (string) $second->json('form_code'));
    }

    public function test_different_actor_does_not_reuse_attempt(): void
    {
        $this->seedScales();

        $first = $this->startAttempt('anon-actor-a', 'MBTI', 'mbti_93');
        $first->assertStatus(200);

        $second = $this->startAttempt('anon-actor-b', 'MBTI', 'mbti_93');
        $second->assertStatus(200);

        $this->assertNotSame((string) $first->json('attempt_id'), (string) $second->json('attempt_id'));
        $this->assertSame(2, DB::table('attempts')->count());
        $this->assertSame(2, DB::table('attempt_drafts')->count());
    }

    public function test_big5_start_reuses_in_progress_attempt_before_retake_limit_checks(): void
    {
        $this->seedScales();

        $first = $this->startAttempt('anon-big5-reuse', 'BIG5_OCEAN', 'big5_120');
        $first->assertStatus(200);

        $second = $this->startAttempt('anon-big5-reuse', 'BIG5_OCEAN', 'big5_120');
        $second->assertStatus(200);

        $this->assertSame((string) $first->json('attempt_id'), (string) $second->json('attempt_id'));
        $this->assertSame(1, DB::table('attempts')->count());
        $this->assertSame(1, DB::table('attempt_drafts')->count());
        $this->assertSame('big5_120', (string) $second->json('form_code'));
    }
}
