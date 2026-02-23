<?php

namespace App\Jobs;

use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use App\Services\Report\ReportComposer;
use App\Services\Storage\ArtifactStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $attemptId;
    public ?string $jobId;

    public function __construct(string $attemptId, ?string $jobId = null)
    {
        $this->attemptId = $attemptId;
        $this->jobId = $jobId;
    }

    public function handle(ReportComposer $composer): void
    {
        $attempt = Attempt::query()->where('id', $this->attemptId)->firstOrFail();
        $attemptId = (string) $attempt->id;
        $orgId = (int) ($attempt->org_id ?? 0);

        $result = Result::query()
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->firstOrFail();

        $job = ReportJob::where('attempt_id', $attemptId)->first();

        if (!$job) {
            $job = ReportJob::create([
                'id' => $this->jobId ?: (string) Str::uuid(),
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'status' => 'queued',
                'tries' => 0,
                'available_at' => now(),
            ]);
        } elseif (empty($job->org_id)) {
            if ($orgId > 0) {
                $job->org_id = $orgId;
                $job->save();
            }
        }

        $job->tries = ((int) ($job->tries ?? 0)) + 1;
        $job->status = 'running';
        $job->started_at = now();
        $job->failed_at = null;
        $job->finished_at = null;
        $job->last_error = null;
        $job->last_error_trace = null;
        $job->save();

        try {
            $res = $composer->compose($attempt, [
                'org_id' => $orgId,
                'defaultProfileVersion' => config('fap.profile_version', 'mbti32-v2.5'),
            ], $result);

            if (!($res['ok'] ?? false)) {
                $msg = $res['message'] ?? 'Report compose failed';
                $err = $res['error'] ?? 'REPORT_FAILED';
                throw new \RuntimeException("{$err}: {$msg}");
            }

            $reportPayload = $res['report'] ?? [];
            if (!is_array($reportPayload)) $reportPayload = [];

            $reportPayload['tags'] = $res['tags'] ?? ($reportPayload['tags'] ?? []);
            if (!is_array($reportPayload['tags'])) $reportPayload['tags'] = [];

            $job->status = 'success';
            $job->finished_at = now();
            $job->report_json = $reportPayload;
            $job->save();

            $this->persistReportJson((string) ($attempt->scale_code ?? 'MBTI'), $attemptId, $reportPayload);
        } catch (\Throwable $e) {
            $job->status = 'failed';
            $job->failed_at = now();
            $job->last_error = $e->getMessage();
            $job->last_error_trace = $e->getTraceAsString();
            $job->save();

            Log::warning('[report_job] failed', [
                'attempt_id' => $this->attemptId,
                'job_id' => $job->id,
                'err' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function persistReportJson(string $scaleCode, string $attemptId, array $reportPayload): void
    {
        try {
            $latestRelPath = app(ArtifactStore::class)
                ->putReportJson($scaleCode, $attemptId, $reportPayload);

            Log::info('[report_job] persisted report.json', [
                'attempt_id' => $attemptId,
                'disk' => 'local',
                'latest' => $latestRelPath,
                'snapshot' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[report_job] persist report.json failed', [
                'attempt_id' => $attemptId,
                'path' => null,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
