<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BackfillAttemptsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_attempts(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.write_mode', 'legacy');

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => 'ops_backfill_attempt_known',
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $before = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($before);
        $this->assertNull($before->scale_code_v2);
        $this->assertNull($before->scale_uid);

        $this->artisan('ops:backfill-attempts-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_attempts_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_codes_without_looping_or_crashing(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        Config::set('scale_identity.write_mode', 'legacy');

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => 'ops_backfill_attempt_unknown',
        ]);

        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        DB::table('attempts')
            ->where('id', $attemptId)
            ->update([
                'scale_code' => 'UNKNOWN_SCALE',
                'scale_code_v2' => null,
                'scale_uid' => null,
                'updated_at' => now(),
            ]);

        $this->artisan('ops:backfill-attempts-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_attempts_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
