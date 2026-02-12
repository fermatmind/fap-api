<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Attempt;

use App\Jobs\GenerateReportJob;
use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use App\Services\ContentPackResolver;
use App\Services\Legacy\Mbti\Content\LegacyMbtiPackRepository;
use App\Services\Psychometrics\NormsRegistry;
use App\Services\Psychometrics\QualityChecker;
use App\Services\Psychometrics\ScoreNormalizer;
use App\Support\OrgContext;
use App\Support\WritesEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LegacyMbtiAttemptLifecycleService
{
    use WritesEvents;

    /**
     * 缓存：本次请求内复用题库索引
     * @var array|null
     */
    private ?array $questionsIndex = null;

    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly LegacyMbtiPackRepository $packRepo,
    ) {
    }
    private function orgId(): int
    {
        return max(0, (int) $this->orgContext->orgId());
    }

    private function attemptQuery(string $attemptId)
    {
        return Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $this->orgId());
    }

    private function resultQuery(string $attemptId)
    {
        return Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $this->orgId());
    }

    private function defaultRegion(): string
    {
        return (string) config('content_packs.default_region', 'CN_MAINLAND');
    }

    private function defaultLocale(): string
    {
        return (string) config('content_packs.default_locale', 'zh-CN');
    }

    private function defaultDirVersion(): string
    {
        return (string) config(
            'content_packs.default_dir_version',
            config('content.default_versions.default', 'MBTI-CN-v0.2.1-TEST')
        );
    }
public function startAttempt(Request $request, ?string $id = null)
{
    if (is_string($id) && trim($id) !== '') {
        $request->merge(['attempt_id' => trim($id)]);
    }

    $payload = $request->validate([
        'anon_id'        => ['required', 'string', 'max:64'],
        'scale_code'     => ['required', 'string', 'in:MBTI'],
        'scale_version' => ['required', 'string', 'in:v0.2,v0.2.1,v0.2.1-TEST,v0.2.2'],

        // ✅ 你的 attempts 表里是 NOT NULL：必须补齐
        'question_count'  => ['required', 'integer', 'in:24,93,144'],
        'client_platform' => ['required', 'string', 'max:32'],

        // 可选字段（表里有就存，没有也不影响 create）
        'client_version' => ['nullable', 'string', 'max:32'],
        'channel'        => ['nullable', 'string', 'max:32'],
        'referrer'       => ['nullable', 'string', 'max:255'],

        'meta_json'      => ['sometimes', 'array'],
    ]);

    // ✅ 兜底：即使 Attempt 模型没自动填 id，这里也保证不报错
    $attempt = Attempt::create([
        'id'            => (string) Str::uuid(),

        'anon_id'       => $payload['anon_id'],
        'scale_code'    => $payload['scale_code'],
        'scale_version' => $payload['scale_version'],

        'question_count'  => (int) $payload['question_count'],
        'client_platform' => (string) $payload['client_platform'],

        'client_version' => $payload['client_version'] ?? null,
        'channel'        => $payload['channel'] ?? null,
        'referrer'       => $payload['referrer'] ?? null,

        'started_at'    => now(),

        'answers_summary_json' => [
            'stage' => 'start',
            'created_at_ms' => (int) round(microtime(true) * 1000),
            'meta' => $payload['meta_json'] ?? null,
        ],
    ]);

    Log::info('[attempt_start] created', [
        'attempt_id'    => (string) $attempt->id,
        'anon_id'       => (string) $attempt->anon_id,
        'scale_code'    => (string) $attempt->scale_code,
        'scale_version' => (string) $attempt->scale_version,
    ]);

    return response()->json([
        'ok' => true,
        'id' => (string) $attempt->id,
        'scale_code' => $attempt->scale_code,
        'scale_version' => $attempt->scale_version,
    ]);
}

    /**
     * POST /api/v0.2/attempts
     * ✅ 前端提交：answers[].question_id + answers[].code(A~E)
     * ✅ 后端用题库 options.score + key_pole + direction 计算 5 轴百分比
     */
public function storeAttempt(Request $request)
{
    $payload = $request->validate([
        'anon_id'       => ['required', 'string', 'max:64'],
        'scale_code'    => ['required', 'string', 'in:MBTI'],
        'scale_version' => ['required', 'string', 'in:v0.2,v0.2.1,v0.2.1-TEST,v0.2.2'],

        'answers' => ['required', 'array', 'min:1'],
        'answers.*.question_id' => ['required', 'string'],
        'answers.*.code'        => ['required', 'string', 'in:A,B,C,D,E'],

        // 可选字段
        'client_platform' => ['nullable', 'string', 'max:32'],
        'client_version'  => ['nullable', 'string', 'max:32'],
        'channel'         => ['nullable', 'string', 'max:32'],
        'referrer'        => ['nullable', 'string', 'max:255'],
        'region'          => ['nullable', 'string', 'max:32'],
        'locale'          => ['nullable', 'string', 'max:16'],
        'attempt_id'      => ['nullable', 'string', 'max:64'],
        'demographics'    => ['sometimes', 'array'],
    ]);

    $attemptId = isset($payload['attempt_id']) && is_string($payload['attempt_id']) && trim($payload['attempt_id']) !== ''
        ? trim($payload['attempt_id'])
        : (string) Str::uuid();
    $isResultUpsertRoute = trim((string) ($request->route('id') ?? '')) !== '';

    Log::info('[attempt_submit] received', [
        'attempt_id' => $attemptId,
    ]);

    // 题库索引：question_id => meta
    $index = $this->getQuestionsIndex(
        $payload['region'] ?? null,
        $payload['locale'] ?? null,
        $this->defaultDirVersion()
    );
    $expectedQuestionCount = count($index);
    if ($expectedQuestionCount <= 0) {
        return response()->json([
            'ok'      => false,
            'error'   => 'QUESTIONS_NOT_READY',
            'message' => 'Questions index is empty.',
        ], 500);
    }

    // 校验 question_id 必须存在
    $unknown = [];
    foreach ($payload['answers'] as $a) {
        $qid = $a['question_id'];
        if (!isset($index[$qid])) $unknown[] = $qid;
    }
    if (!empty($unknown)) {
        return response()->json([
            'ok'      => false,
            'error'   => 'VALIDATION_FAILED',
            'message' => 'Unknown question_id in answers.',
            'data'    => ['unknown_question_ids' => $unknown],
        ], 422);
    }

    // ✅ 强校验：question_id 不能重复，且必须覆盖完整题目
    $qids = array_map(fn($a) => (string)($a['question_id'] ?? ''), $payload['answers']);
    $qids = array_values(array_filter($qids, fn($x) => $x !== ''));

    $unique = array_values(array_unique($qids));
    if (count($unique) !== $expectedQuestionCount) {
        $freq = array_count_values($qids);
        $dups = array_keys(array_filter($freq, fn($n) => $n > 1));

        return response()->json([
            'ok'      => false,
            'error'   => 'VALIDATION_FAILED',
            'message' => "Answers must contain {$expectedQuestionCount} unique question_id.",
            'data'    => [
                'unique_count'     => count($unique),
                'dup_question_ids' => array_values($dups),
            ],
        ], 422);
    }

    if (count($payload['answers']) !== $expectedQuestionCount) {
        return response()->json([
            'ok'      => false,
            'error'   => 'VALIDATION_FAILED',
            'message' => "Invalid answers count. expected={$expectedQuestionCount}",
            'data'    => ['answer_count' => count($payload['answers'])],
        ], 422);
    }

    $answers = $payload['answers'] ?? [];
    $region = $payload['region'] ?? $this->defaultRegion();
    $locale = $payload['locale'] ?? $this->defaultLocale();
    $contentPackageVersion = $this->defaultDirVersion();

    $scoringSpec = null;
    try {
        $resolved = app(ContentPackResolver::class)->resolve('MBTI', $region, $locale, $contentPackageVersion);
        $scoringSpec = $this->loadPackJson($resolved, 'scoring_spec.json');
    } catch (\Throwable $e) {
        Log::warning('LEGACY_MBTI_PACK_READ_DEGRADED', [
            'scale_code' => 'MBTI',
            'region' => $region,
            'locale' => $locale,
            'dir_version' => $contentPackageVersion,
            'request_id' => $this->requestId(),
            'exception' => $e,
        ]);
        $scoringSpec = null;
    }

    try {
        $scored = app(\App\Services\Score\MbtiAttemptScorer::class)->score($answers, $index, $scoringSpec);
    } catch (\Throwable $e) {
        Log::error('LEGACY_MBTI_SCORING_FAILED', [
            'attempt_id' => $attemptId,
            'scale_code' => (string) $payload['scale_code'],
            'request_id' => $this->requestId(),
            'exception' => $e,
        ]);

        throw $e;
    }

    $scoresPct = is_array($scored['scoresPct'] ?? null) ? $scored['scoresPct'] : [];
    $scoresJson = is_array($scored['scoresJson'] ?? null) ? $scored['scoresJson'] : [];
    $axisStates = is_array($scored['axisStates'] ?? null) ? $scored['axisStates'] : [];
    $typeCode = (string) ($scored['typeCode'] ?? '');
    $skipped = is_array($scored['skipped'] ?? null) ? $scored['skipped'] : [];
    $dimN = is_array($scored['dimTotals'] ?? null) ? $scored['dimTotals'] : [];
    $sumTowardP1 = is_array($scored['sumTowardP1'] ?? null) ? $scored['sumTowardP1'] : [];
    $counts = is_array($scored['counts'] ?? null) ? $scored['counts'] : [];
    $facetScores = $scored['facetScores'] ?? null;
    $pci = $scored['pci'] ?? null;
    $engineVersion = (string) ($scored['engineVersion'] ?? '');

    if ($typeCode === '' || empty($scoresPct)) {
        return response()->json([
            'ok'      => false,
            'error'   => 'SCORING_FAILED',
            'message' => 'Scoring output incomplete.',
        ], 500);
    }

    $answersSummary = [
        'answer_count' => count($answers),
        'dims_total'   => $dimN,
        'dims_sum_p1'  => $sumTowardP1,
        'scores_pct'   => $scoresPct,
        'type_code'    => $typeCode,
        'skipped'      => $skipped,
        'counts'       => $counts,
        'engine_version' => $engineVersion,
        'pci' => $pci,
        'facet_scores' => $facetScores,
    ];

    $profileVersion        = config('fap.profile_version', 'mbti32-v2.5');

    $psychometrics = $this->buildPsychometricsSnapshot(
        $payload['scale_code'],
        $region,
        $locale,
        $contentPackageVersion,
        $scoresPct,
        $answers,
        $payload['demographics'] ?? null
    );
    $answersHash = $this->computeAnswersHash($answers);

    $answersStoragePath = null;
    $storeToStorage = (bool) config('fap.store_answers_to_storage', false);

    if ($storeToStorage) {
        $answersStoragePath = $this->persistAnswersToStorage(
            $answers,
            $contentPackageVersion,
            $answersHash
        );
    }

    $canStoreJson = Schema::hasColumn('attempts', 'answers_json');
    $canStorePath = Schema::hasColumn('attempts', 'answers_storage_path');

    if (!$canStoreJson && (!$storeToStorage || !$canStorePath)) {
        return response()->json([
            'ok' => false,
            'error' => 'PERSISTENCE_NOT_SUPPORTED',
            'message' => 'Attempt answers persistence is required for audit, but attempts.answers_json / answers_storage_path is not available.',
            'data' => [
                'has_answers_json_column' => $canStoreJson,
                'has_answers_storage_path_column' => $canStorePath,
                'store_to_storage' => $storeToStorage,
            ],
        ], 500);
    }

    $tx = DB::transaction(function () use (
        $request,
        $payload,
        $attemptId,
        $expectedQuestionCount,
        $answersSummary,
        $typeCode,
        $scoresJson,
        $scoresPct,
        $axisStates,
        $facetScores,
        $pci,
        $engineVersion,
        $profileVersion,
        $contentPackageVersion,
        $psychometrics,
        $answers,
        $answersHash,
        $answersStoragePath,
        $isResultUpsertRoute
    ) {
        $existingAttempt = $this->attemptQuery($attemptId)->first();

        if ($isResultUpsertRoute && !$existingAttempt) {
            return response()->json([
                'ok'      => false,
                'error'   => 'NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        if ($existingAttempt) {
            if ((string)$existingAttempt->anon_id !== (string)$payload['anon_id']
                || (string)$existingAttempt->scale_code !== (string)$payload['scale_code']
                || (string)$existingAttempt->scale_version !== (string)$payload['scale_version']
            ) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'ATTEMPT_MISMATCH',
                    'message' => 'attempt_id does not match anon_id/scale.',
                ], 409);
            }
        }

        // ✅ 2) 组装 attempts 更新字段（注意：不要覆盖 ticket_code）
        $attemptData = [
            'id'                   => $attemptId,
            'anon_id'              => $payload['anon_id'],
            'user_id'              => $existingAttempt?->user_id ?? null,

            'scale_code'           => $payload['scale_code'],
            'scale_version'        => $payload['scale_version'],
            'question_count'       => $expectedQuestionCount,
            'answers_summary_json' => $answersSummary,

            'client_platform'      => $payload['client_platform'] ?? ($existingAttempt?->client_platform ?? 'unknown'),
            'client_version'       => $payload['client_version'] ?? ($existingAttempt?->client_version ?? 'unknown'),
            'channel'              => $payload['channel'] ?? ($existingAttempt?->channel ?? 'direct'),
            'referrer'             => $payload['referrer'] ?? ($existingAttempt?->referrer ?? ''),

            'started_at'           => $existingAttempt?->started_at ?? now(),
            'submitted_at'         => now(),
        ];
        if (Schema::hasColumn('attempts', 'org_id')) {
            $attemptData['org_id'] = $existingAttempt?->org_id ?? $this->orgId();
        }

        if (Schema::hasColumn('attempts', 'answers_json')) {
            $attemptData['answers_json'] = $answers;
        }
        if (Schema::hasColumn('attempts', 'answers_hash')) {
            $attemptData['answers_hash'] = $answersHash;
        }
        if (Schema::hasColumn('attempts', 'answers_storage_path')) {
            $attemptData['answers_storage_path'] = $answersStoragePath;
        }
        if (Schema::hasColumn('attempts', 'region')) {
            $attemptData['region'] = $payload['region'] ?? ($existingAttempt?->region ?? 'CN_MAINLAND');
        }
        if (Schema::hasColumn('attempts', 'locale')) {
            $attemptData['locale'] = $payload['locale'] ?? ($existingAttempt?->locale ?? 'zh-CN');
        }
        if (Schema::hasColumn('attempts', 'pack_id')) {
            $pack = $psychometrics['pack'] ?? null;
            $attemptData['pack_id'] = is_array($pack) ? ($pack['pack_id'] ?? null) : null;
        }
        if (Schema::hasColumn('attempts', 'dir_version')) {
            $pack = $psychometrics['pack'] ?? null;
            $attemptData['dir_version'] = is_array($pack) ? ($pack['dir_version'] ?? null) : null;
        }
        if (Schema::hasColumn('attempts', 'scoring_spec_version')) {
            $scoring = $psychometrics['scoring'] ?? null;
            $attemptData['scoring_spec_version'] = is_array($scoring) ? ($scoring['spec_version'] ?? null) : null;
        }
        if (Schema::hasColumn('attempts', 'norm_version')) {
            $norm = $psychometrics['norm'] ?? null;
            $attemptData['norm_version'] = is_array($norm) ? ($norm['version'] ?? null) : null;
        }
        if (Schema::hasColumn('attempts', 'calculation_snapshot_json')) {
            $attemptData['calculation_snapshot_json'] = $psychometrics;
        }

        // ✅✅✅ 关键：把“结果”写进 attempts（给 /attempts/{id}/result 主路径用）
        if (Schema::hasColumn('attempts', 'type_code')) {
            $attemptData['type_code'] = $typeCode;
        }
        if (Schema::hasColumn('attempts', 'result_json')) {
            $attemptData['result_json'] = [
                'attempt_id'              => $attemptId,
                'scale_code'              => $payload['scale_code'],
                'scale_version'           => $payload['scale_version'],
                'type_code'               => $typeCode,
                'scores'                  => $scoresJson,
                'scores_pct'              => $scoresPct,
                'axis_states'             => $axisStates,
                'facet_scores'            => $facetScores,
                'pci'                     => $pci,
                'engine_version'          => $engineVersion,
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
                'computed_at'             => now()->toISOString(),
            ];
        }

        if ($existingAttempt) {
            $existingAttempt->fill($attemptData);
            $existingAttempt->save();
            $attempt = $existingAttempt;
        } else {
            $attempt = Attempt::create($attemptData);
        }

        if (Schema::hasTable('attempt_quality')) {
            $quality = $psychometrics['quality'] ?? null;
            if (is_array($quality)) {
                $checks = $quality['checks'] ?? null;
                DB::table('attempt_quality')->updateOrInsert(
                    ['attempt_id' => $attemptId],
                    [
                        'checks_json' => is_array($checks)
                            ? json_encode($checks, JSON_UNESCAPED_SLASHES)
                            : null,
                        'grade' => (string) ($quality['grade'] ?? ''),
                        'created_at' => now(),
                    ]
                );
            }
        }

        // ✅ 3) results 表：兼容旧逻辑（有则更新，无则创建）
        $result = $this->resultQuery($attemptId)->first();
        $isNewResult = false;

        $resultBase = [
            'attempt_id'    => $attemptId,
            'scale_code'    => $payload['scale_code'],
            'scale_version' => $payload['scale_version'],
            'type_code'     => $typeCode,
            'scores_json'   => $scoresJson,
            'is_valid'      => true,
            'computed_at'   => now(),
        ];

        if (Schema::hasColumn('results', 'scores_pct')) {
            $resultBase['scores_pct'] = $scoresPct;
        }
        if (Schema::hasColumn('results', 'org_id')) {
            $resultBase['org_id'] = $this->orgId();
        }
        if (Schema::hasColumn('results', 'axis_states')) {
            $resultBase['axis_states'] = $axisStates;
        }
        if (Schema::hasColumn('results', 'profile_version')) {
            $resultBase['profile_version'] = $profileVersion;
        }
        if (Schema::hasColumn('results', 'content_package_version')) {
            $resultBase['content_package_version'] = $contentPackageVersion;
        }
        if (Schema::hasColumn('results', 'scoring_spec_version')) {
            $scoring = $psychometrics['scoring'] ?? null;
            $resultBase['scoring_spec_version'] = is_array($scoring) ? ($scoring['spec_version'] ?? null) : null;
        }

        if ($result) {
            $result->fill($resultBase);
            $result->save();
        } else {
            $isNewResult = true;
            $resultBase['id'] = (string) Str::uuid();
            $result = Result::create($resultBase);
        }

        // ✅ 4) test_submit event（保持你原逻辑）
        $this->logEvent('test_submit', $request, [
            'anon_id'       => $payload['anon_id'],
            'scale_code'    => $attempt->scale_code,
            'scale_version' => $attempt->scale_version,
            'attempt_id'    => $attemptId,
            'channel'       => $attempt->channel,
            'region'        => $payload['region'] ?? ($attempt->region ?? 'CN_MAINLAND'),
            'locale'        => $payload['locale'] ?? ($attempt->locale ?? 'zh-CN'),
            'meta_json'     => [
                'answer_count'            => count($payload['answers']),
                'question_count'          => $attempt->question_count,
                'type_code'               => $typeCode,
                'scores_pct'              => $scoresPct,
                'axis_states'             => $axisStates,
                'engine_version'          => $engineVersion,
                'pci'                     => $pci,
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
            ],
        ]);

        $resultPayload = [
            'type_code'               => $typeCode,
            'scores'                  => $scoresJson,
            'scores_pct'              => $scoresPct,
            'axis_states'             => $axisStates,
            'facet_scores'            => $facetScores,
            'pci'                     => $pci,
            'engine_version'          => $engineVersion,
            'profile_version'         => $profileVersion,
            'content_package_version' => $contentPackageVersion,
        ];

        $reportJob = $this->ensureReportJob($attemptId, true);
        if ($reportJob instanceof \Illuminate\Http\JsonResponse) {
            return $reportJob;
        }

        return [
            'attempt_id' => $attemptId,
            'result_id' => $result->id,
            'result' => $resultPayload,
            'status_code' => $isNewResult ? 201 : 200,
            'report_job' => $reportJob,
        ];
    });

    if ($tx instanceof \Illuminate\Http\JsonResponse) {
        return $tx;
    }

    if (isset($tx['report_job']) && $tx['report_job'] instanceof ReportJob) {
        $this->dispatchReportJob($tx['report_job']);
    }

    return response()->json([
        'ok'         => true,
        'attempt_id' => $tx['attempt_id'],
        'result_id'  => $tx['result_id'],
        'result'     => $tx['result'],
        'job'        => [
            'job_id' => $tx['report_job']->id,
            'status' => $tx['report_job']->status,
        ],
    ], $tx['status_code']);
}

public function upsertResult(\Illuminate\Http\Request $request, string $attemptId)
{
    // 让 /attempts/{id}/result 走你现有的 storeAttempt 逻辑（写 attempts.result_json + results 表）
    $request->merge([
        'attempt_id' => $attemptId,
    ]);

    return $this->storeAttempt($request);
}

private function ensureReportJob(string $attemptId, bool $reset = false): ReportJob|\Illuminate\Http\JsonResponse
{
    if (!$this->attemptQuery($attemptId)->exists()) {
        return response()->json([
            'ok'      => false,
            'error'   => 'NOT_FOUND',
            'message' => 'attempt not found.',
        ], 404);
    }

    $job = ReportJob::where('attempt_id', $attemptId)->first();

    if (!$job) {
        $job = ReportJob::create([
            'id' => (string) Str::uuid(),
            'org_id' => $this->orgId(),
            'attempt_id' => $attemptId,
            'status' => 'queued',
            'tries' => 0,
            'available_at' => now(),
        ]);
        return $job;
    }

    if ((int) ($job->org_id ?? 0) !== $this->orgId()) {
        $job->org_id = $this->orgId();
        $job->save();
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
    private function getQuestionsIndex(?string $region = null, ?string $locale = null, ?string $pkgVersion = null): array
    {
        if ($this->questionsIndex !== null) {
            return $this->questionsIndex;
        }

        $ver = $pkgVersion ?: $this->defaultDirVersion();
        $region = $region ?: $this->defaultRegion();
        $locale = $locale ?: $this->defaultLocale();
        $contentDir = $this->packRepo->resolveContentDir(null, $ver, $region, $locale);
        $json = $this->packRepo->loadQuestionsDoc($contentDir);

        if (!is_array($json)) {
            $this->questionsIndex = [];
            return $this->questionsIndex;
        }

        $items = isset($json['items']) ? $json['items'] : $json;
        if (!is_array($items)) {
            $this->questionsIndex = [];
            return $this->questionsIndex;
        }

        $items = array_values(array_filter($items, fn ($q) => ($q['is_active'] ?? true) === true));

        $idx = [];
        foreach ($items as $q) {
            $qid = $q['question_id'] ?? null;
            if (!$qid) {
                continue;
            }

            $scoreMap = [];
            foreach (($q['options'] ?? []) as $o) {
                if (!isset($o['code'])) {
                    continue;
                }
                $scoreMap[strtoupper($o['code'])] = (int) ($o['score'] ?? 0);
            }

            $idx[$qid] = [
                'dimension' => $q['dimension'] ?? null,
                'key_pole'  => $q['key_pole'] ?? null,
                'direction' => $q['direction'] ?? 1,
                'score_map' => $scoreMap,
                'code'      => isset($q['code']) ? strtoupper((string) $q['code']) : null,
                'weight'    => $q['irt']['a'] ?? null,
            ];
        }

        $this->questionsIndex = $idx;
        return $this->questionsIndex;
    }

private function buildPsychometricsSnapshot(
    string $scaleCode,
    string $region,
    string $locale,
    string $dirVersion,
    array $scoresPct,
    array $answers,
    ?array $demographics
): array {
    $snapshot = [
        'norm' => null,
        'scoring' => [
            'spec_version' => null,
            'rules_checksum' => null,
        ],
        'pack' => [
            'pack_id' => null,
            'dir_version' => $dirVersion,
            'version_checksum' => null,
        ],
        'stats' => null,
        'quality' => null,
        'computed_at' => now()->toISOString(),
    ];

    $manifest = $this->loadPackManifest($region, $locale, $dirVersion);
    $resolvedVersion = is_array($manifest)
        ? (string) ($manifest['content_package_version'] ?? '')
        : '';

    if ($resolvedVersion === '') {
        $resolvedVersion = $dirVersion;
    }

    $resolved = null;
    try {
        $resolved = app(ContentPackResolver::class)->resolve($scaleCode, $region, $locale, $resolvedVersion);
    } catch (\Throwable $e) {
        Log::warning('LEGACY_MBTI_PACK_RESOLVE_DEGRADED', [
            'scale_code' => $scaleCode,
            'region' => $region,
            'locale' => $locale,
            'dir_version' => $resolvedVersion,
            'request_id' => $this->requestId(),
            'exception' => $e,
        ]);

        $resolved = null;
    }

    if ($resolved) {
        $snapshot['pack']['pack_id'] = $resolved->packId ?? null;
        $versionJson = $this->loadPackJson($resolved, 'version.json');
        if (is_array($versionJson)) {
            $snapshot['pack']['version_checksum'] = (string) (
                $versionJson['checksum']
                ?? $versionJson['checksum_sha256']
                ?? ''
            );
        }
    } elseif (is_array($manifest)) {
        $snapshot['pack']['pack_id'] = (string) ($manifest['pack_id'] ?? '');
    }

    $scoringSpec = $this->loadPackJson($resolved, 'scoring_spec.json');
    if (is_array($scoringSpec)) {
        $snapshot['scoring']['spec_version'] = (string) ($scoringSpec['version'] ?? '');
        $snapshot['scoring']['rules_checksum'] = $this->checksumJson($scoringSpec);
    }

    $qualitySpec = $this->loadPackJson($resolved, 'quality_checks.json');

    $normsInfo = app(NormsRegistry::class)->resolve(
        $scaleCode,
        $region,
        $locale,
        $resolvedVersion,
        $demographics,
        null
    );

    $bucket = null;
    if (is_array($normsInfo) && ($normsInfo['ok'] ?? false)) {
        $bucket = $normsInfo['bucket'] ?? null;
        $snapshot['norm'] = [
            'norm_id' => $normsInfo['norm_id'] ?? '',
            'version' => $normsInfo['version'] ?? '',
            'checksum' => $normsInfo['checksum'] ?? '',
            'bucket_keys' => $normsInfo['bucket_keys'] ?? [],
            'bucket' => is_array($bucket) ? [
                'id' => $bucket['id'] ?? null,
                'keys' => $bucket['keys'] ?? null,
            ] : null,
        ];
    }

    if (is_array($bucket)) {
        $stats = app(ScoreNormalizer::class)->normalize($scoresPct, $bucket, 0.95);
        $snapshot['stats'] = $stats;
    }

    if (is_array($scoringSpec) && is_array($qualitySpec)) {
        $quality = app(QualityChecker::class)->check($answers, $scoringSpec, $qualitySpec);
        $snapshot['quality'] = $quality;
    }

    return $snapshot;
}

private function loadPackJson($resolved, string $filename): ?array
{
    if (!$resolved || !is_array($resolved->loaders ?? null)) {
        return null;
    }

    $loader = $resolved->loaders['readJson'] ?? null;
    if (!is_callable($loader)) {
        return null;
    }

    $data = $loader($filename);
    return is_array($data) ? $data : null;
}

private function loadPackManifest(string $region, string $locale, string $dirVersion): ?array
{
    return $this->packRepo->loadPackManifest($region, $locale, $dirVersion);
}

private function checksumJson(array $data): string
{
    return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
}

/**
 * answers_hash：稳定可复现
 * - 只用 question_id + code
 * - 按 question_id 排序，避免前端提交顺序影响 hash
 */
private function computeAnswersHash(array $answers): string
{
    $norm = [];

    foreach ($answers as $a) {
        if (!is_array($a)) continue;

        $qid  = (string)($a['question_id'] ?? '');
        $code = strtoupper((string)($a['code'] ?? ''));

        if ($qid === '' || $code === '') continue;

        $norm[] = ['question_id' => $qid, 'code' => $code];
    }

    usort($norm, fn($x, $y) => strcmp($x['question_id'], $y['question_id']));

    return hash('sha256', json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * 可选：把 answers 原文存到 storage（便于后续复算/风控/审计）
 * 默认写到 storage/app/attempt_answers/<pkg>/<Ymd>/<hash>.json
 */
private function persistAnswersToStorage(array $answers, string $pkg, string $answersHash): string
{
    $pkg  = trim($pkg, "/\\");
    $date = now()->format('Ymd');

    $path = "attempt_answers/{$pkg}/{$date}/{$answersHash}.json";

    // 如果你配置了 private disk 就优先用，否则退回默认 disk
    $disk = Storage::disk(config('filesystems.default', 'local'));
    if (array_key_exists('private', config('filesystems.disks', []))) {
        $disk = Storage::disk('private');
    }

    $disk->put($path, json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return $path;
}
private function requestId(): string
{
    $request = request();
    if (!$request instanceof Request) {
        return '';
    }

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
