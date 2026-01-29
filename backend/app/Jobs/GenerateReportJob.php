<?php

namespace App\Jobs;

use App\Models\Attempt;
use App\Models\ReportJob;
use App\Services\Report\ReportComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        $job = ReportJob::where('attempt_id', $this->attemptId)->first();

        if (!$job) {
            $orgId = (int) (Attempt::where('id', $this->attemptId)->value('org_id') ?? 0);
            $job = ReportJob::create([
                'id' => $this->jobId ?: (string) Str::uuid(),
                'org_id' => $orgId,
                'attempt_id' => $this->attemptId,
                'status' => 'queued',
                'tries' => 0,
                'available_at' => now(),
            ]);
        } elseif (empty($job->org_id)) {
            $orgId = (int) (Attempt::where('id', $this->attemptId)->value('org_id') ?? 0);
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
            $defaultDirVersion = (string) config(
                'content_packs.default_dir_version',
                config('content.default_versions.default', 'MBTI-CN-v0.2.1-TEST')
            );

            $res = $composer->compose($this->attemptId, [
                'defaultProfileVersion' => config('fap.profile_version', 'mbti32-v2.5'),
                'defaultContentPackageVersion' => $defaultDirVersion,
                'currentContentPackageVersion' => fn () => $defaultDirVersion,
            ]);

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

            $this->persistReportJson($this->attemptId, $reportPayload);
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

    private function persistReportJson(string $attemptId, array $reportPayload): void
    {
        $disk = array_key_exists('private', config('filesystems.disks', []))
            ? Storage::disk('private')
            : Storage::disk(config('filesystems.default', 'local'));

        $latestRelPath = "reports/{$attemptId}/report.json";

        try {
            $json = json_encode($reportPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('json_encode_failed: ' . json_last_error_msg());
            }

            if (method_exists($disk, 'makeDirectory')) {
                $disk->makeDirectory("reports/{$attemptId}");
            }

            $disk->put($latestRelPath, $json);

            $ts = function_exists('now') ? now()->format('Ymd_His') : date('Ymd_His');
            $snapRelPath = "reports/{$attemptId}/report.{$ts}.json";
            $disk->put($snapRelPath, $json);

            Log::info('[report_job] persisted report.json', [
                'attempt_id' => $attemptId,
                'disk' => array_key_exists('private', config('filesystems.disks', []))
                    ? 'private'
                    : config('filesystems.default', 'local'),
                'latest' => $latestRelPath,
                'snapshot' => $snapRelPath,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[report_job] persist report.json failed', [
                'attempt_id' => $attemptId,
                'path' => $latestRelPath,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
