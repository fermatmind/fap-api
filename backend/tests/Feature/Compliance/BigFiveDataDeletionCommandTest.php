<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Attempt;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveDataDeletionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_attempt_purge_command_redacts_and_deletes_related_data(): void
    {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_big5_purge_cmd',
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'answers_json' => [['question_id' => 1, 'code' => '3']],
            'answers_hash' => hash('sha256', 'seed'),
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'type_code' => 'BIG5_READY',
            'result_json' => ['score_result' => ['ok' => true]],
        ]);

        Result::create([
            'id' => $resultId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => ['domains_mean' => ['O' => 3.0]],
            'scores_pct' => ['O' => 50],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => ['normed_json' => ['quality' => ['level' => 'A']]],
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        DB::table('report_snapshots')->insert([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'order_no' => null,
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'snapshot_version' => 'v1',
            'report_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_free_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_full_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shares')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'anon_id' => 'anon_big5_purge_cmd',
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'content_package_version' => 'v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'status' => 'pending',
            'tries' => 0,
            'available_at' => now(),
            'started_at' => null,
            'finished_at' => null,
            'failed_at' => null,
            'last_error' => null,
            'last_error_trace' => null,
            'report_json' => null,
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan(sprintf(
            'big5:attempt:purge %s --org_id=0 --reason=test_command',
            $attemptId
        ))
            ->expectsOutput('status=success')
            ->expectsOutput('attempt_id=' . $attemptId)
            ->expectsOutput('org_id=0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('results')->where('attempt_id', $attemptId)->count());
        $this->assertSame(0, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());
        $this->assertSame(0, DB::table('shares')->where('attempt_id', $attemptId)->count());
        $this->assertSame(0, DB::table('report_jobs')->where('attempt_id', $attemptId)->count());

        $attempt = DB::table('attempts')->where('id', $attemptId)->first();
        $this->assertNotNull($attempt);
        $this->assertNull($attempt->answers_hash);
        $this->assertNull($attempt->type_code);
        $this->assertNull($attempt->result_json);

        $requestRow = DB::table('data_lifecycle_requests')
            ->where('request_type', 'attempt_purge')
            ->where('subject_ref', $attemptId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($requestRow);
        $this->assertSame('done', (string) ($requestRow->status ?? ''));
        $this->assertSame('success', (string) ($requestRow->result ?? ''));
        $this->assertSame('test_command', (string) ($requestRow->reason ?? ''));
    }
}

