<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Jobs\GenerateReportSnapshotJob;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\Pr16IqRavenDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportSnapshotScaleIdentityDualWriteTest extends TestCase
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

    public function test_submit_and_snapshot_job_dual_write_snapshot_identity_columns(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();
        Config::set('scale_identity.write_mode', 'dual');

        $anonId = 'dual_snapshot_iq';
        $token = $this->issueAnonToken($anonId);

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

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
            'duration_ms' => 23000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);

        $pending = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->status ?? ''));
        $this->assertSame('IQ_RAVEN', (string) ($pending->scale_code ?? ''));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', (string) ($pending->scale_code_v2 ?? ''));
        $this->assertSame('55555555-5555-4555-8555-555555555555', (string) ($pending->scale_uid ?? ''));

        $job = new GenerateReportSnapshotJob(0, $attemptId, 'submit', null);
        $job->handle(app(ReportSnapshotStore::class));

        $ready = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($ready);
        $this->assertSame('ready', (string) ($ready->status ?? ''));
        $this->assertSame('IQ_RAVEN', (string) ($ready->scale_code ?? ''));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', (string) ($ready->scale_code_v2 ?? ''));
        $this->assertSame('55555555-5555-4555-8555-555555555555', (string) ($ready->scale_uid ?? ''));
    }

    public function test_submit_legacy_mode_keeps_snapshot_identity_columns_nullable(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->seedScales();
        Config::set('scale_identity.write_mode', 'legacy');

        $anonId = 'legacy_snapshot_iq';
        $token = $this->issueAnonToken($anonId);

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'IQ_RAVEN',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

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
            'duration_ms' => 22000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);

        $pending = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->status ?? ''));
        $this->assertSame('IQ_RAVEN', (string) ($pending->scale_code ?? ''));
        $this->assertNull($pending->scale_code_v2);
        $this->assertNull($pending->scale_uid);
    }
}

