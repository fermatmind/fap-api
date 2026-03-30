<?php

namespace Tests\Feature\V0_3;

use App\Jobs\ProcessAttemptSubmissionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptSubmissionJobTerminalFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_failed_marks_running_submission_as_terminal_failed(): void
    {
        $submissionId = (string) Str::uuid();
        DB::table('attempt_submissions')->insert([
            'id' => $submissionId,
            'org_id' => 0,
            'attempt_id' => 'attempt-terminal-running',
            'actor_user_id' => null,
            'actor_anon_id' => 'anon_terminal_running',
            'dedupe_key' => hash('sha256', 'attempt-terminal-running'),
            'mode' => 'async',
            'state' => 'running',
            'error_code' => null,
            'error_message' => null,
            'request_payload_json' => json_encode(['attempt_id' => 'attempt-terminal-running'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'response_payload_json' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $job = new ProcessAttemptSubmissionJob($submissionId);
        $job->failed(new TimeoutExceededException('worker timed out.'));

        $row = DB::table('attempt_submissions')->where('id', $submissionId)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', (string) ($row->state ?? ''));
        $this->assertSame('SUBMISSION_JOB_TIMEOUT', (string) ($row->error_code ?? ''));
        $this->assertNotNull($row->finished_at ?? null);

        $payload = json_decode((string) ($row->response_payload_json ?? '{}'), true);
        $this->assertTrue((bool) ($payload['terminal_failure'] ?? false));
        $this->assertSame('SUBMISSION_JOB_TIMEOUT', (string) ($payload['error_code'] ?? ''));
        $this->assertNotSame('', (string) data_get($payload, 'job.queue', ''));
    }

    public function test_job_failed_does_not_overwrite_existing_succeeded_or_failed_submission(): void
    {
        $succeededId = (string) Str::uuid();
        $failedId = (string) Str::uuid();

        DB::table('attempt_submissions')->insert([
            [
                'id' => $succeededId,
                'org_id' => 0,
                'attempt_id' => 'attempt-terminal-succeeded',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon_terminal_succeeded',
                'dedupe_key' => hash('sha256', 'attempt-terminal-succeeded'),
                'mode' => 'async',
                'state' => 'succeeded',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => json_encode(['attempt_id' => 'attempt-terminal-succeeded'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_payload_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => now()->subMinutes(2),
                'finished_at' => now()->subMinute(),
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinute(),
            ],
            [
                'id' => $failedId,
                'org_id' => 0,
                'attempt_id' => 'attempt-terminal-failed',
                'actor_user_id' => null,
                'actor_anon_id' => 'anon_terminal_failed',
                'dedupe_key' => hash('sha256', 'attempt-terminal-failed'),
                'mode' => 'async',
                'state' => 'failed',
                'error_code' => 'SUBMISSION_JOB_FAILED',
                'error_message' => 'existing terminal failure',
                'request_payload_json' => json_encode(['attempt_id' => 'attempt-terminal-failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_payload_json' => json_encode(['ok' => false, 'error_code' => 'SUBMISSION_JOB_FAILED'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => now()->subMinutes(3),
                'finished_at' => now()->subMinutes(2),
                'created_at' => now()->subMinutes(3),
                'updated_at' => now()->subMinutes(2),
            ],
        ]);

        (new ProcessAttemptSubmissionJob($succeededId))->failed(new \RuntimeException('should not overwrite success'));
        (new ProcessAttemptSubmissionJob($failedId))->failed(new \RuntimeException('should not overwrite failure'));

        $succeeded = DB::table('attempt_submissions')->where('id', $succeededId)->first();
        $failed = DB::table('attempt_submissions')->where('id', $failedId)->first();

        $this->assertNotNull($succeeded);
        $this->assertSame('succeeded', (string) ($succeeded->state ?? ''));
        $this->assertSame('{"ok":true}', (string) ($succeeded->response_payload_json ?? ''));

        $this->assertNotNull($failed);
        $this->assertSame('failed', (string) ($failed->state ?? ''));
        $this->assertSame('SUBMISSION_JOB_FAILED', (string) ($failed->error_code ?? ''));
        $this->assertSame('existing terminal failure', (string) ($failed->error_message ?? ''));
    }
}
