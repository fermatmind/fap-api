<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateReportJob;
use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use App\Services\Report\ReportComposer;
use App\Services\Storage\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenerateReportJobFailureContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_retry_timeout_and_backoff_contract_is_bounded(): void
    {
        $job = new GenerateReportJob((string) Str::uuid());

        $this->assertSame(3, $job->tries);
        $this->assertSame(180, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([10, 30, 60], $job->backoff);
    }

    public function test_artifact_failure_cannot_leave_a_successful_report_job(): void
    {
        [$attemptId] = $this->seedAttemptAndResult();
        $unencodable = fopen('php://memory', 'rb');
        $this->assertIsResource($unencodable);

        $composer = Mockery::mock(ReportComposer::class);
        $composer->shouldReceive('compose')->once()->andReturn([
            'ok' => true,
            'report' => ['unencodable' => $unencodable],
            'tags' => [],
        ]);

        $job = new GenerateReportJob($attemptId);

        try {
            $job->handle($composer);
            $this->fail('Artifact persistence failure must be rethrown for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('REPORT_JSON_ENCODE_FAILED', $exception->getMessage());
        } finally {
            fclose($unencodable);
        }

        $reportJob = ReportJob::query()->where('attempt_id', $attemptId)->firstOrFail();
        $this->assertSame('queued', $reportJob->status);
        $this->assertNull($reportJob->finished_at);
        $this->assertNull($reportJob->failed_at);
        $this->assertNull($reportJob->report_json);

        $terminalFailure = new RuntimeException('artifact retries exhausted');
        $job->failed($terminalFailure);

        $reportJob->refresh();
        $this->assertSame('failed', $reportJob->status);
        $this->assertNotNull($reportJob->failed_at);
        $this->assertNull($reportJob->finished_at);
        $this->assertSame('artifact retries exhausted', $reportJob->last_error);
    }

    public function test_report_artifact_store_rejects_a_false_disk_write_result(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REPORT_JSON_WRITE_FAILED');

        app(ArtifactStore::class)->putReportJson('MBTI', (string) Str::uuid(), ['ok' => true]);
    }

    /** @return array{string, string} */
    private function seedAttemptAndResult(): array
    {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();

        Attempt::query()->create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'report-job-failure-contract',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 1,
            'answers_summary_json' => [],
            'client_platform' => 'test',
        ]);

        Result::query()->create([
            'id' => $resultId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [],
            'computed_at' => now(),
        ]);

        return [$attemptId, $resultId];
    }
}
