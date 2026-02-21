<?php

namespace Tests\Feature\SRE;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AnswerRowWriter;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

class TransactionSlimmingTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
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

    public function test_attempt_submit_seeds_pending_snapshot_and_dispatches_job(): void
    {
        $this->seedScales();
        $anonId = 'anon_tx_submit';
        $anonToken = $this->issueAnonToken($anonId);
        $attemptId = $this->startSimpleScoreAttempt($anonId);

        Storage::fake('local');
        Queue::fake();

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->simpleScoreAnswers(),
            'duration_ms' => 120000,
        ]);
        $submit->assertStatus(200);

        $snapshot = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('pending', (string) ($snapshot->status ?? ''));

        Queue::assertPushed(GenerateReportSnapshotJob::class, function (GenerateReportSnapshotJob $job) use ($attemptId): bool {
            return $job->orgId === 0
                && $job->attemptId === $attemptId
                && $job->triggerSource === 'submit';
        });
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attempt_submit_rollback_does_not_dispatch_job(): void
    {
        $this->seedScales();
        $anonId = 'anon_tx_rollback';
        $anonToken = $this->issueAnonToken($anonId);
        $attemptId = $this->startSimpleScoreAttempt($anonId);

        $writer = Mockery::mock(AnswerRowWriter::class);
        $writer->shouldReceive('writeRows')
            ->once()
            ->andThrow(new \RuntimeException('forced_answer_row_write_failure'));
        $this->app->instance(AnswerRowWriter::class, $writer);

        Queue::fake();

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->simpleScoreAnswers(),
            'duration_ms' => 120000,
        ]);
        $submit->assertStatus(500);

        Queue::assertNotPushed(GenerateReportSnapshotJob::class);
        $this->assertSame(0, DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->count());
    }

    public function test_webhook_seeds_pending_snapshot_and_dispatches_job(): void
    {
        $this->seedScales();
        $attemptId = $this->createMbtiAttemptWithResult(0);
        $orderNo = 'ord_tx_webhook_1';

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_tx_webhook',
            'sku' => 'MBTI_REPORT_FULL',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_REPORT_FULL',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);

        Queue::fake();
        Storage::fake('s3');

        $response = $this->postSignedBillingWebhook([
            'provider_event_id' => 'evt_tx_webhook_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_tx_webhook_1',
            'amount_cents' => 990,
            'currency' => 'USD',
        ], [
            'X-Org-Id' => '0',
        ]);
        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $snapshot = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('pending', (string) ($snapshot->status ?? ''));

        Queue::assertPushed(GenerateReportSnapshotJob::class, function (GenerateReportSnapshotJob $job) use ($attemptId, $orderNo): bool {
            return $job->attemptId === $attemptId
                && $job->orgId === 0
                && $job->triggerSource === 'payment'
                && $job->orderNo === $orderNo;
        });
    }

    public function test_generate_report_snapshot_job_sets_snapshot_ready(): void
    {
        $this->seedScales();
        $anonId = 'anon_tx_job';
        $anonToken = $this->issueAnonToken($anonId);
        $attemptId = $this->startSimpleScoreAttempt($anonId);

        Queue::fake();

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->simpleScoreAnswers(),
            'duration_ms' => 120000,
        ]);
        $submit->assertStatus(200);

        $pending = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($pending);
        $this->assertSame('pending', (string) ($pending->status ?? ''));

        $job = new GenerateReportSnapshotJob(0, $attemptId, 'submit', null);
        $job->handle(app(ReportSnapshotStore::class));

        $ready = DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->first();
        $this->assertNotNull($ready);
        $this->assertSame('ready', (string) ($ready->status ?? ''));
        $this->assertNull($ready->last_error ?? null);
        $this->assertNotSame('{}', (string) ($ready->report_json ?? '{}'));
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
        (new Pr19CommerceSeeder())->run();
    }

    /**
     * @return array<int, array{question_id:string, code:string}>
     */
    private function simpleScoreAnswers(): array
    {
        return [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];
    }

    private function startSimpleScoreAttempt(string $anonId): string
    {
        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);

        return (string) $start->json('attempt_id');
    }

    private function createMbtiAttemptWithResult(int $orgId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => 'anon_tx_webhook',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }
}
