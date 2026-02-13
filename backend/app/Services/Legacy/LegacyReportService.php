<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Jobs\GenerateReportJob;
use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Support\OrgContext;
use App\Support\WritesEvents;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class LegacyReportService
{
    use WritesEvents;

    public function __construct(
        private OrgContext $orgContext
    ) {
    }

    public function ownedAttemptOrFail(string $attemptId, Request $request): Attempt
    {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $this->resolveScopedOrgId($request));

        $userId = $this->resolveUserId($request);
        if ($userId !== '') {
            return (clone $query)
                ->where('user_id', $userId)
                ->firstOrFail();
        }

        $anonId = $this->resolveAnonId($request);
        if ($anonId !== '') {
            return (clone $query)
                ->where('anon_id', $anonId)
                ->firstOrFail();
        }

        throw (new ModelNotFoundException())->setModel(Attempt::class, [$attemptId]);
    }

    private function resolveScopedOrgId(Request $request): int
    {
        $orgAttr = trim((string) ($request->attributes->get('org_id')
            ?? $request->attributes->get('fm_org_id')
            ?? ''));
        if ($orgAttr !== '' && preg_match('/^\d+$/', $orgAttr) === 1) {
            return (int) $orgAttr;
        }

        return max(0, (int) $this->orgContext->orgId());
    }

    public function getResultPayload(Attempt $attempt): array
    {
        $attemptId = (string) $attempt->id;
        $attemptResult = is_array($attempt->result_json ?? null) ? $attempt->result_json : [];

        $result = Result::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', $attemptId)
            ->first();

        if (empty($attemptResult) && !$result) {
            throw (new ModelNotFoundException())->setModel(Result::class, [$attemptId]);
        }

        $scaleCode = (string) (
            ($attemptResult['scale_code'] ?? null)
            ?? ($result?->scale_code ?? 'MBTI')
        );

        $scaleVersion = (string) (
            ($attemptResult['scale_version'] ?? null)
            ?? ($result?->scale_version ?? 'v0.2')
        );

        $typeCode = (string) (
            ($attemptResult['type_code'] ?? null)
            ?? ($attempt->type_code ?? null)
            ?? ($result?->type_code ?? '')
        );

        $scoresJson = (
            ($attemptResult['scores'] ?? null)
            ?? ($attemptResult['scores_json'] ?? null)
            ?? ($result?->scores_json ?? [])
        );

        $scoresPct = (
            ($attemptResult['scores_pct'] ?? null)
            ?? ($result?->scores_pct ?? [])
        );

        $axisStates = (
            ($attemptResult['axis_states'] ?? null)
            ?? ($result?->axis_states ?? [])
        );

        $facetScores = (
            ($attemptResult['facet_scores'] ?? null)
            ?? ($attemptResult['facetScores'] ?? null)
            ?? []
        );

        $pci = (
            ($attemptResult['pci'] ?? null)
            ?? []
        );

        $engineVersion = (string) (
            ($attemptResult['engine_version'] ?? null)
            ?? ($attemptResult['scoring_engine_version'] ?? null)
            ?? ($result?->report_engine_version ?? '')
        );

        $profileVersion = (string) (
            ($attemptResult['profile_version'] ?? null)
            ?? ($result?->profile_version ?? null)
            ?? config('fap.profile_version', 'mbti32-v2.5')
        );

        $contentPackageVersion = (string) (
            ($attemptResult['content_package_version'] ?? null)
            ?? ($result?->content_package_version ?? null)
            ?? $this->defaultDirVersion()
        );

        $computedAt = (
            ($attemptResult['computed_at'] ?? null)
            ?? ($result?->computed_at ? $result->computed_at->toIso8601String() : null)
        );

        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        if (!is_array($scoresJson)) {
            $scoresJson = [];
        }
        if (!is_array($scoresPct)) {
            $scoresPct = [];
        }
        if (!is_array($axisStates)) {
            $axisStates = [];
        }
        if (!is_array($facetScores)) {
            $facetScores = [];
        }
        if (!is_array($pci)) {
            $pci = [];
        }

        foreach ($dims as $dim) {
            if (!array_key_exists($dim, $scoresJson)) {
                $scoresJson[$dim] = ['a' => 0, 'b' => 0, 'neutral' => 0, 'sum' => 0, 'total' => 0];
            }
            if (!array_key_exists($dim, $scoresPct)) {
                $scoresPct[$dim] = 50;
            }
            if (!array_key_exists($dim, $axisStates)) {
                $axisStates[$dim] = 'moderate';
            }
        }

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => $scaleVersion,
            'type_code' => $typeCode,
            'scores' => $scoresJson,
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'facet_scores' => $facetScores,
            'pci' => $pci,
            'engine_version' => $engineVersion,
            'profile_version' => $profileVersion,
            'content_package_version' => $contentPackageVersion,
            'computed_at' => $computedAt,
        ];
    }

    public function recordResultViewEvents(Request $request, Attempt $attempt, array $payload): void
    {
        $shareId = trim((string) (
            $request->query('share_id')
            ?? $request->header('X-Share-Id')
            ?? $request->header('X-Share-ID')
            ?? ''
        ));
        if ($shareId === '') {
            $shareId = null;
        }

        $funnel = $this->readFunnelMetaFromHeaders($request, $attempt);

        $resultViewMeta = $this->mergeEventMeta([
            'type_code' => (string) ($payload['type_code'] ?? ''),
            'engine_version' => 'v1.2',
            'content_package_version' => (string) ($payload['content_package_version'] ?? ''),
            'share_id' => $shareId,
        ], $funnel);

        $this->logEvent('result_view', $request, [
            'anon_id' => $attempt->anon_id,
            'scale_code' => (string) ($payload['scale_code'] ?? $attempt->scale_code ?? ''),
            'scale_version' => (string) ($payload['scale_version'] ?? $attempt->scale_version ?? ''),
            'attempt_id' => (string) $attempt->id,
            'channel' => $funnel['channel'] ?? null,
            'client_platform' => $funnel['client_platform'] ?? null,
            'client_version' => $funnel['version'] ?? null,
            'region' => $attempt->region ?? 'CN_MAINLAND',
            'locale' => $attempt->locale ?? 'zh-CN',
            'share_id' => $shareId,
            'meta_json' => $resultViewMeta,
        ]);

        if ($shareId !== null) {
            $shareViewMeta = $this->mergeEventMeta([
                'share_id' => $shareId,
                'page' => 'result_page',
            ], $funnel);

            $this->logEvent('share_view', $request, [
                'anon_id' => $attempt->anon_id,
                'scale_code' => (string) ($payload['scale_code'] ?? $attempt->scale_code ?? ''),
                'scale_version' => (string) ($payload['scale_version'] ?? $attempt->scale_version ?? ''),
                'attempt_id' => (string) $attempt->id,
                'channel' => $funnel['channel'] ?? null,
                'client_platform' => $funnel['client_platform'] ?? null,
                'client_version' => $funnel['version'] ?? null,
                'region' => $attempt->region ?? 'CN_MAINLAND',
                'locale' => $attempt->locale ?? 'zh-CN',
                'share_id' => $shareId,
                'meta_json' => $shareViewMeta,
            ]);
        }
    }

    public function getReportPayload(Attempt $attempt, Request $request): array
    {
        $attemptId = (string) $attempt->id;

        if (!config('features.enable_v0_2_report', false)) {
            throw (new ModelNotFoundException())->setModel(Attempt::class, [$attemptId]);
        }

        $result = Result::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', $attemptId)
            ->first();

        if (!$result) {
            throw (new ModelNotFoundException())->setModel(Result::class, [$attemptId]);
        }

        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $benefitCode = $this->resolveReportBenefitCode($attempt, $request);

        $hasFullAccess = app(EntitlementManager::class)->hasFullAccess(
            (int) ($attempt->org_id ?? 0),
            $userId !== '' ? $userId : null,
            $anonId !== '' ? $anonId : null,
            $attemptId,
            $benefitCode
        );

        if (!$hasFullAccess) {
            return [
                'status' => 402,
                'body' => [
                    'ok' => false,
                    'error' => 'PAYMENT_REQUIRED',
                    'message' => 'report locked',
                ],
            ];
        }

        $shareId = trim((string) ($request->query('share_id') ?? $request->header('X-Share-Id') ?? ''));
        $includeRaw = (string) $request->query('include', '');
        $include = array_values(array_filter(array_map('trim', explode(',', $includeRaw))));
        $includePsychometrics = in_array('psychometrics', $include, true);

        $refreshRaw = (string) $request->query('refresh', '0');
        $refresh = in_array($refreshRaw, ['1', 'true', 'TRUE', 'yes', 'YES'], true);

        $contentPackageVersion = (string) ($result->content_package_version ?? $this->defaultDirVersion());
        $eventAnonId = trim((string) ($attempt->anon_id ?? $anonId));
        if ($eventAnonId === '') {
            $eventAnonId = null;
        }

        $reportJob = $this->ensureReportJob($attemptId, $refresh);
        if ($refresh || $reportJob->wasRecentlyCreated) {
            $this->dispatchReportJob($reportJob);
        }

        if ($reportJob->status === 'failed') {
            return [
                'status' => 500,
                'body' => [
                    'ok' => false,
                    'status' => 'failed',
                    'error' => 'REPORT_FAILED',
                    'message' => $reportJob->last_error ?? 'Report generation failed',
                    'job_id' => $reportJob->id,
                ],
            ];
        }

        $status = (string) ($reportJob->status ?? 'queued');
        if (!in_array($status, ['success', 'queued', 'running', 'failed'], true)) {
            $status = 'queued';
        }

        if (in_array($status, ['queued', 'running'], true)) {
            $deadline = microtime(true) + 3.0;
            $latest = $reportJob;

            while (microtime(true) < $deadline) {
                usleep(100000);
                $latest = ReportJob::query()->where('attempt_id', $attemptId)->first();
                if (!$latest) {
                    break;
                }

                $status = (string) ($latest->status ?? 'queued');
                if ($status === 'success' || $status === 'failed') {
                    break;
                }
            }

            if ($status === 'failed') {
                return [
                    'status' => 500,
                    'body' => [
                        'ok' => false,
                        'status' => 'failed',
                        'error' => 'REPORT_FAILED',
                        'message' => $latest?->last_error ?? 'Report generation failed',
                        'job_id' => $latest?->id,
                    ],
                ];
            }

            if ($status !== 'success') {
                return [
                    'status' => 202,
                    'body' => [
                        'ok' => true,
                        'status' => $status,
                        'job_id' => $latest?->id ?? $reportJob->id,
                    ],
                ];
            }

            $reportJob = $latest ?? $reportJob;
        }

        $reportPayload = is_array($reportJob->report_json ?? null)
            ? $reportJob->report_json
            : null;

        if (!is_array($reportPayload)) {
            $disk = array_key_exists('private', config('filesystems.disks', []))
                ? Storage::disk('private')
                : Storage::disk(config('filesystems.default', 'local'));
            $latestRelPath = "reports/{$attemptId}/report.json";

            try {
                if ($disk->exists($latestRelPath)) {
                    $cachedJson = $disk->get($latestRelPath);
                    $cached = json_decode($cachedJson, true);
                    if (is_array($cached)) {
                        $reportPayload = $cached;
                    }
                }
            } catch (Throwable $e) {
                Log::error('LEGACY_REPORT_CACHE_READ_FAILED', [
                    'attempt_id' => $attemptId,
                    'request_id' => $this->requestId($request),
                    'path' => $latestRelPath,
                    'message' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        if (!is_array($reportPayload)) {
            return [
                'status' => 500,
                'body' => [
                    'ok' => false,
                    'status' => 'failed',
                    'error' => 'REPORT_MISSING',
                    'message' => 'Report payload is missing',
                    'job_id' => $reportJob->id,
                ],
            ];
        }

        if (!array_key_exists('tags', $reportPayload) || !is_array($reportPayload['tags'])) {
            $reportPayload['tags'] = [];
        }

        $funnel = $this->readFunnelMetaFromHeaders($request, $attempt);
        $reportViewMeta = $this->mergeEventMeta([
            'type_code' => (string) ($result->type_code ?? ''),
            'engine_version' => 'v1.2',
            'content_package_version' => $contentPackageVersion,
            'share_id' => ($shareId !== '' ? $shareId : null),
            'refresh' => $refresh,
            'cache' => !$refresh,
        ], $funnel);

        $this->logEvent('report_view', $request, [
            'anon_id' => $eventAnonId,
            'scale_code' => (string) ($result->scale_code ?? ''),
            'scale_version' => (string) ($result->scale_version ?? ''),
            'attempt_id' => $attemptId,
            'channel' => $funnel['channel'] ?? null,
            'client_platform' => $funnel['client_platform'] ?? null,
            'client_version' => $funnel['version'] ?? null,
            'region' => $attempt->region ?? 'CN_MAINLAND',
            'locale' => $attempt->locale ?? 'zh-CN',
            'share_id' => ($shareId !== '' ? $shareId : null),
            'meta_json' => $reportViewMeta,
        ]);

        if ($shareId !== '') {
            $shareViewMeta = $this->mergeEventMeta([
                'share_id' => $shareId,
                'page' => 'report_page',
            ], $funnel);

            $this->logEvent('share_view', $request, [
                'anon_id' => $eventAnonId,
                'scale_code' => (string) ($result->scale_code ?? ''),
                'scale_version' => (string) ($result->scale_version ?? ''),
                'attempt_id' => $attemptId,
                'channel' => $funnel['channel'] ?? null,
                'client_platform' => $funnel['client_platform'] ?? null,
                'client_version' => $funnel['version'] ?? null,
                'region' => $attempt->region ?? 'CN_MAINLAND',
                'locale' => $attempt->locale ?? 'zh-CN',
                'share_id' => $shareId,
                'meta_json' => $shareViewMeta,
            ]);
        }

        if ($includePsychometrics) {
            $snapshot = $attempt->calculation_snapshot_json;
            if (is_string($snapshot)) {
                $decoded = json_decode($snapshot, true);
                $snapshot = is_array($decoded) ? $decoded : null;
            }
            $reportPayload['psychometrics'] = $snapshot;
        }

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'attempt_id' => $attemptId,
                'type_code' => (string) ($reportPayload['profile']['type_code'] ?? $result->type_code),
                'report' => $reportPayload,
            ],
        ];
    }

    private function resolveUserId(Request $request): string
    {
        $userId = trim((string) ($request->user()?->id ?? ''));
        if ($userId !== '') {
            return $userId;
        }

        return trim((string) ($request->attributes->get('fm_user_id')
            ?? $request->attributes->get('user_id')
            ?? ''));
    }

    private function resolveAnonId(Request $request): string
    {
        $anonId = trim((string) ($request->attributes->get('anon_id')
            ?? $request->attributes->get('fm_anon_id')
            ?? ''));
        if ($anonId !== '') {
            return $anonId;
        }

        $anonId = trim((string) $request->header('X-Anon-Id', ''));
        if ($anonId !== '') {
            return $anonId;
        }

        return trim((string) $request->query('anon_id', ''));
    }

    private function resolveReportBenefitCode(Attempt $attempt, Request $request): string
    {
        if (!Schema::hasTable('scales_registry')) {
            return '';
        }

        $scaleCode = trim((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return '';
        }

        try {
            $row = DB::table('scales_registry')
                ->where('org_id', (int) ($attempt->org_id ?? 0))
                ->where('code', $scaleCode)
                ->first();
        } catch (Throwable $e) {
            Log::error('LEGACY_REPORT_BENEFIT_RESOLVE_FAILED', [
                'attempt_id' => (string) $attempt->id,
                'request_id' => $this->requestId($request),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (!$row) {
            return '';
        }

        $commercial = $row->commercial_json ?? null;
        if (is_string($commercial)) {
            $decoded = json_decode($commercial, true);
            $commercial = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($commercial)) {
            return '';
        }

        $benefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($benefitCode === '') {
            $benefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        return $benefitCode;
    }

    private function ensureReportJob(string $attemptId, bool $reset = false): ReportJob
    {
        $job = ReportJob::query()->where('attempt_id', $attemptId)->first();

        if (!$job) {
            $orgId = (int) (Attempt::query()->where('id', $attemptId)->value('org_id') ?? 0);

            return ReportJob::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'status' => 'queued',
                'tries' => 0,
                'available_at' => now(),
            ]);
        }

        if (empty($job->org_id)) {
            $orgId = (int) (Attempt::query()->where('id', $attemptId)->value('org_id') ?? 0);
            if ($orgId > 0) {
                $job->org_id = $orgId;
                $job->save();
            }
        }

        if ($reset) {
            $job->status = 'queued';
            $job->available_at = now();
            $job->started_at = null;
            $job->finished_at = null;
            $job->failed_at = null;
            $job->last_error = null;
            $job->last_error_trace = null;
            $job->report_json = null;
            $job->save();
        }

        return $job;
    }

    private function dispatchReportJob(ReportJob $job): void
    {
        GenerateReportJob::dispatch($job->attempt_id, $job->id)->onQueue('reports');
    }

    private function readFunnelMetaFromHeaders(Request $request, ?Attempt $attempt = null): array
    {
        $experiment = trim((string) ($request->header('X-Experiment') ?? ''));
        $version = trim((string) ($request->header('X-App-Version') ?? ''));
        $channel = trim((string) ($request->header('X-Channel') ?? ($attempt?->channel ?? '')));
        $clientPlatform = trim((string) ($request->header('X-Client-Platform') ?? ''));
        $entryPage = trim((string) ($request->header('X-Entry-Page') ?? ''));

        return [
            'experiment' => ($experiment !== '' ? $experiment : null),
            'version' => ($version !== '' ? $version : null),
            'channel' => ($channel !== '' ? $channel : null),
            'client_platform' => ($clientPlatform !== '' ? $clientPlatform : null),
            'entry_page' => ($entryPage !== '' ? $entryPage : null),
        ];
    }

    private function mergeEventMeta(array $base, array $funnel): array
    {
        return array_merge($base, $funnel);
    }

    private function defaultDirVersion(): string
    {
        return (string) config(
            'content_packs.default_dir_version',
            config('content.default_versions.default', 'MBTI-CN-v0.2.1-TEST')
        );
    }

    private function requestId(Request $request): string
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        return trim((string) $request->header('X-Request-ID', ''));
    }
}
