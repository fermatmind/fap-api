<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr16IqRavenDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventScaleIdentityDualWriteTest extends TestCase
{
    use RefreshDatabase;

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr16IqRavenDemoSeeder())->run();
    }

    public function test_start_and_submit_dual_write_persist_event_identity_columns(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();
        Config::set('scale_identity.write_mode', 'dual');

        $anonId = 'dual_event_iq';
        $token = $this->issueAnonToken($anonId);

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $startEvent = DB::table('events')
            ->where('event_code', 'test_start')
            ->where('attempt_id', $attemptId)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($startEvent);
        $this->assertSame('IQ_RAVEN', (string) ($startEvent->scale_code ?? ''));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', (string) ($startEvent->scale_code_v2 ?? ''));
        $this->assertSame('55555555-5555-4555-8555-555555555555', (string) ($startEvent->scale_uid ?? ''));

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'MATRIX_Q01', 'code' => 'A'],
                ['question_id' => 'ODD_Q01', 'code' => 'B'],
                ['question_id' => 'SERIES_Q01', 'code' => 'C'],
            ],
            'duration_ms' => 21000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);

        $submitEvent = DB::table('events')
            ->where('event_code', 'test_submit')
            ->where('attempt_id', $attemptId)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($submitEvent);
        $this->assertSame('IQ_RAVEN', (string) ($submitEvent->scale_code ?? ''));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', (string) ($submitEvent->scale_code_v2 ?? ''));
        $this->assertSame('55555555-5555-4555-8555-555555555555', (string) ($submitEvent->scale_uid ?? ''));
    }

    public function test_start_and_submit_legacy_mode_keep_event_identity_columns_nullable(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();
        Config::set('scale_identity.write_mode', 'legacy');

        $anonId = 'legacy_event_iq';
        $token = $this->issueAnonToken($anonId);

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_RAVEN',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $startEvent = DB::table('events')
            ->where('event_code', 'test_start')
            ->where('attempt_id', $attemptId)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($startEvent);
        $this->assertSame('IQ_RAVEN', (string) ($startEvent->scale_code ?? ''));
        $this->assertNull($startEvent->scale_code_v2);
        $this->assertNull($startEvent->scale_uid);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => [
                ['question_id' => 'MATRIX_Q01', 'code' => 'A'],
                ['question_id' => 'ODD_Q01', 'code' => 'B'],
                ['question_id' => 'SERIES_Q01', 'code' => 'C'],
            ],
            'duration_ms' => 20000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);

        $submitEvent = DB::table('events')
            ->where('event_code', 'test_submit')
            ->where('attempt_id', $attemptId)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($submitEvent);
        $this->assertSame('IQ_RAVEN', (string) ($submitEvent->scale_code ?? ''));
        $this->assertNull($submitEvent->scale_code_v2);
        $this->assertNull($submitEvent->scale_uid);
    }
}

