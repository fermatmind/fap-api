<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

use App\Models\Attempt;
use App\Models\Result;

use App\Support\WritesEvents;

use App\Services\Report\TagBuilder;
use App\Services\Report\SectionCardGenerator;
use App\Services\Report\HighlightsGenerator;
use App\Services\Overrides\HighlightsOverridesApplier;
use App\Services\Report\IdentityLayerBuilder;
use App\Services\Report\ReportComposer;
use App\Services\Rules\RuleEngine;

class MbtiController extends Controller
{
    use WritesEvents;

    /**
     * 缓存：本次请求内复用题库索引
     * @var array|null
     */
    private ?array $questionsIndex = null;

    /**
     * 当前内容包版本（统一入口）
     * ✅ config-cache 安全：业务代码只读 config（.env 由 config/fap.php 读取）
     */
    private function currentContentPackageVersion(): string
    {
        return (string) config('fap.content_package_version', 'MBTI-CN-v0.2.1-TEST');
    }

    /**
     * 健康检查：确认 API 服务在线
     */
    public function health()
    {
        return response()->json([
            'ok'      => true,
            'service' => 'Fermat Assessment Platform API',
            'version' => 'v0.2-skeleton',
            'time'    => now()->toIso8601String(),
        ]);
    }

    /**
     * 返回 MBTI 量表元信息
     */
    public function scaleMeta()
    {
        return response()->json([
            'scale_code'     => 'MBTI',
            'title'          => 'MBTI v2.5 · FermatMind',
            'question_count' => 144,
            'region'         => 'CN_MAINLAND',
            'locale'         => 'zh-CN',
            'version'        => 'v0.2',
            'price_tier'     => 'FREE',
        ]);
    }

    /**
     * GET /api/v0.2/scales/MBTI/questions
     * ✅ 读 content_packages/<pkg>/questions.json（兼容根目录 or 子目录）
     * ✅ 对外脱敏：不返回 score / key_pole / direction / irt / is_active
     */
    public function questions()
    {
        $pkg = $this->normalizeContentPackageDir($this->currentContentPackageVersion());

        // ✅ 兼容：questions.json 在根目录 or 子目录
        $path = $this->findPackageFile($pkg, 'questions.json');

        if (!$path || !is_file($path)) {
            return response()->json([
                'ok'    => false,
                'error' => 'questions.json not found',
                'path'  => $path ?: "(not found in package: {$pkg})",
            ], 500);
        }

        $json = json_decode(file_get_contents($path), true);
        if (!is_array($json)) {
            return response()->json([
                'ok'    => false,
                'error' => 'questions.json invalid JSON',
                'path'  => $path,
            ], 500);
        }

        // 兼容：数组 或 {items:[...]}
        $items = isset($json['items']) ? $json['items'] : $json;
        if (!is_array($items)) {
            return response()->json([
                'ok'    => false,
                'error' => 'questions.json items invalid',
            ], 500);
        }

        // 只取 active + 排序
        $items = array_values(array_filter($items, fn ($q) => ($q['is_active'] ?? true) === true));
        usort($items, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        if (count($items) !== 144) {
            return response()->json([
                'ok'    => false,
                'error' => 'MBTI questions must be 144',
                'count' => count($items),
            ], 500);
        }

        // ✅ 对外脱敏：仅返回展示字段
        $safe = array_map(function ($q) {
            $opts = array_map(function ($o) {
                return [
                    'code' => $o['code'],
                    'text' => $o['text'],
                ];
            }, $q['options'] ?? []);

            return [
                'question_id' => $q['question_id'] ?? null,
                'order'       => $q['order'] ?? null,
                'dimension'   => $q['dimension'] ?? null,
                'text'        => $q['text'] ?? null,
                'options'     => $opts,
            ];
        }, $items);

        return response()->json([
            'ok'                      => true,
            'scale_code'              => 'MBTI',
            'version'                 => 'v0.2',
            'region'                  => 'CN_MAINLAND',
            'locale'                  => 'zh-CN',
            'content_package_version' => $pkg,
            'items'                   => $safe,
        ]);
    }

public function startAttempt(Request $request)
{
    $payload = $request->validate([
        'anon_id'        => ['required', 'string', 'max:64'],
        'scale_code'     => ['required', 'string', 'in:MBTI'],
        'scale_version'  => ['required', 'string', 'in:v0.2'],

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
        'scale_version' => ['required', 'string', 'in:v0.2'],

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
    ]);

    $expectedQuestionCount = 144;

    // 题库索引：question_id => meta
    $index = $this->getQuestionsIndex();

    // 校验 question_id 必须存在
    $unknown = [];
    foreach ($payload['answers'] as $a) {
        $qid = $a['question_id'];
        if (!isset($index[$qid])) {
            $unknown[] = $qid;
        }
    }
    if (!empty($unknown)) {
        return response()->json([
            'ok'      => false,
            'error'   => 'VALIDATION_FAILED',
            'message' => 'Unknown question_id in answers.',
            'data'    => ['unknown_question_ids' => $unknown],
        ], 422);
    }

    // ✅ 强校验：question_id 不能重复，且必须覆盖 144 个唯一题
    $qids = array_map(fn($a) => (string)($a['question_id'] ?? ''), $payload['answers']);
    $qids = array_values(array_filter($qids, fn($x) => $x !== ''));

    $unique = array_values(array_unique($qids));
    if (count($unique) !== $expectedQuestionCount) {
    // 找重复
    $freq = array_count_values($qids);
    $dups = array_keys(array_filter($freq, fn($n) => $n > 1));

    return response()->json([
        'ok'      => false,
        'error'   => 'VALIDATION_FAILED',
        'message' => 'Answers must contain 144 unique question_id.',
        'data'    => [
            'unique_count' => count($unique),
            'dup_question_ids' => array_values($dups),
        ],
    ], 422);
}

    // 可选：强校验题数（建议你 debug/线上都开）
    if (count($payload['answers']) !== $expectedQuestionCount) {
        return response()->json([
            'ok'      => false,
            'error'   => 'VALIDATION_FAILED',
            'message' => "Invalid answers count. expected={$expectedQuestionCount}",
            'data'    => ['answer_count' => count($payload['answers'])],
        ], 422);
    }

    // 维度：固定 5 轴
    $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

    // 统计
    $dimN        = array_fill_keys($dims, 0);
    $sumTowardP1 = array_fill_keys($dims, 0);

    // 额外归档：用于审计/可视化
    $countP1      = array_fill_keys($dims, 0);
    $countP2      = array_fill_keys($dims, 0);
    $countNeutral = array_fill_keys($dims, 0);

    // 默认 5 档（当 score_map 缺失/异常时兜底）
    $defaultScoreMap = ['A' => 2, 'B' => 1, 'C' => 0, 'D' => -1, 'E' => -2];

    foreach ($payload['answers'] as $a) {
        $qid  = $a['question_id'];
        $code = strtoupper($a['code'] ?? '');

        $meta = $index[$qid];
        $dim  = $meta['dimension'] ?? null;

        if (!$dim || !isset($dimN[$dim])) {
        $skipped[] = ['question_id' => $qid, 'reason' => 'invalid_dimension', 'dimension' => $dim];
        continue;
        }

        // 1) 取 score_map：若缺失/不含该 code/或整张表全 0 => fallback
        $scoreMap = $meta['score_map'] ?? null;
        if (!is_array($scoreMap) || empty($scoreMap)) {
            $scoreMap = $defaultScoreMap;
        } else {
            // normalize keys to uppercase
            $norm = [];
            foreach ($scoreMap as $k => $v) {
                $norm[strtoupper((string)$k)] = (int)$v;
            }
            $scoreMap = $norm;

            // 如果不包含当前 code，则 fallback
            if (!array_key_exists($code, $scoreMap)) {
                $scoreMap = $defaultScoreMap;
            } else {
                // 如果 score_map 全是 0（你现在的症状就是这个），也 fallback
                $vals = array_values($scoreMap);
                $allZero = true;
                foreach ($vals as $v) {
                    if ((int)$v !== 0) { $allZero = false; break; }
                }
                if ($allZero) {
                    $scoreMap = $defaultScoreMap;
                }
            }
        }

        $rawScore  = (int) ($scoreMap[$code] ?? 0);
        $direction = (int) ($meta['direction'] ?? 1);
        $keyPole   = (string) ($meta['key_pole'] ?? '');

        // direction=0 当作 1
        $signed = $rawScore * ($direction === 0 ? 1 : $direction);

        // 2) 统一折算成 “toward P1 的 signed 分数”
        [$p1, $p2] = $this->getDimensionPoles($dim);

        $towardP1 = $signed;
        // 如果 keyPole 指向 P2，则取反
        if ($keyPole === $p2) {
            $towardP1 = -$signed;
        }

        $dimN[$dim] += 1;
        $sumTowardP1[$dim] += $towardP1;

        if ($towardP1 > 0) {
            $countP1[$dim] += 1;
        } elseif ($towardP1 < 0) {
            $countP2[$dim] += 1;
        } else {
            $countNeutral[$dim] += 1;
        }
    }

    // 防御：任何轴 n=0 直接判为题库 meta 不一致（不让悄悄变 50）
    foreach ($dims as $dim) {
        if ((int)$dimN[$dim] <= 0) {
            return response()->json([
                'ok'      => false,
                'error'   => 'SCORING_FAILED',
                'message' => "No scored answers for dim={$dim}. Check questions meta(score_map/dimension).",
                'data'    => ['dims_total' => $dimN],
            ], 500);
        }
    }

    // scores_pct：[-2n,+2n] -> [0,100]
    $scoresPct = [];
    foreach ($dims as $dim) {
        $n = (int) $dimN[$dim];
        $pct = (int) round((($sumTowardP1[$dim] + 2 * $n) / (4 * $n)) * 100);
        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;
        $scoresPct[$dim] = $pct;
    }

    // type_code：四字母 + -A/-T
    $letters = [
        'EI' => $scoresPct['EI'] >= 50 ? 'E' : 'I',
        'SN' => $scoresPct['SN'] >= 50 ? 'S' : 'N',
        'TF' => $scoresPct['TF'] >= 50 ? 'T' : 'F',
        'JP' => $scoresPct['JP'] >= 50 ? 'J' : 'P',
    ];
    $atSuffix = $scoresPct['AT'] >= 50 ? 'A' : 'T';
    $typeCode = implode('', $letters) . '-' . $atSuffix;

    // axis_states：用“胜方 displayPct(>=50)”离散化 + borderline
    $axisStates = [];
    foreach ($scoresPct as $dim => $pctTowardP1) {
        $displayPct = $pctTowardP1 >= 50 ? $pctTowardP1 : (100 - $pctTowardP1);

        $state = match (true) {
            $displayPct >= 80 => 'very_strong',
            $displayPct >= 70 => 'strong',
            $displayPct >= 60 => 'clear',
            $displayPct >= 55 => 'weak',
            default           => 'very_weak',
        };

        $axisStates[$dim] = $state;
    }

    // scores_json：可审计（保留你原结构）
    $scoresJson = [];
    foreach ($dims as $dim) {
        $scoresJson[$dim] = [
            'a'       => $countP1[$dim],
            'b'       => $countP2[$dim],
            'neutral' => $countNeutral[$dim],
            'sum'     => $sumTowardP1[$dim],
            'total'   => $dimN[$dim],
        ];
    }

    // answers_summary_json：把关键审计信息也塞进去
    $answersSummary = [
        'answer_count' => count($payload['answers']),
        'dims_total'   => $dimN,
        'dims_sum_p1'  => $sumTowardP1,
        'scores_pct'   => $scoresPct,
        'type_code'    => $typeCode,
        'counts'       => [
            'p1'      => $countP1,
            'p2'      => $countP2,
            'neutral' => $countNeutral,
        ],
    ];

    $profileVersion        = config('fap.profile_version', 'mbti32-v2.5');
    $contentPackageVersion = $this->currentContentPackageVersion();

    // ✅ 方案1：把 answers 持久化到 attempts（answers_json + hash + optional storage_path）
    $answers = $payload['answers'] ?? [];

    // 稳定 hash：只看 question_id + code（内部会排序）
    $answersHash = $this->computeAnswersHash($answers);

    // ✅ 修改点 2：env -> config（config-cache 安全）
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

    return DB::transaction(function () use (
        $request,
        $payload,
        $expectedQuestionCount,
        $answersSummary,
        $typeCode,
        $scoresJson,
        $scoresPct,
        $axisStates,
        $profileVersion,
        $contentPackageVersion,
        $answers,
        $answersHash,
        $answersStoragePath
    ) {
        $attemptId = (string) Str::uuid();
        $resultId  = (string) Str::uuid();

        // 1) attempts
$attemptData = [
    'id'                   => $attemptId,
    'anon_id'              => $payload['anon_id'],
    'user_id'              => null,
    'scale_code'           => $payload['scale_code'],
    'scale_version'        => $payload['scale_version'],
    'question_count'       => $expectedQuestionCount,
    'answers_summary_json' => $answersSummary,

    'client_platform'      => $payload['client_platform'] ?? 'unknown',
    'client_version'       => $payload['client_version'] ?? 'unknown',
    'channel'              => $payload['channel'] ?? 'direct',
    'referrer'             => $payload['referrer'] ?? '',

    'started_at'           => now(),
    'submitted_at'         => now(),
];

// ✅ 关键：answers_json 直接存 array（不要 json_encode）
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
    $attemptData['region'] = $payload['region'] ?? 'CN_MAINLAND';
}
if (Schema::hasColumn('attempts', 'locale')) {
    $attemptData['locale'] = $payload['locale'] ?? 'zh-CN';
}

$attempt = Attempt::create($attemptData);

        // 2) results
        $resultData = [
            'id'            => $resultId,
            'attempt_id'    => $attemptId,
            'scale_code'    => $payload['scale_code'],
            'scale_version' => $payload['scale_version'],
            'type_code'     => $typeCode,
            'scores_json'   => $scoresJson,
            'is_valid'      => true,
            'computed_at'   => now(),
        ];

        if (Schema::hasColumn('results', 'scores_pct')) {
            $resultData['scores_pct'] = $scoresPct;
        }
        if (Schema::hasColumn('results', 'axis_states')) {
            $resultData['axis_states'] = $axisStates;
        }
        if (Schema::hasColumn('results', 'profile_version')) {
            $resultData['profile_version'] = $profileVersion;
        }
        if (Schema::hasColumn('results', 'content_package_version')) {
            $resultData['content_package_version'] = $contentPackageVersion;
        }

        $result = Result::create($resultData);

        // 3) test_submit（强一致：事务成功后写）
        $this->logEvent('test_submit', $request, [
            'anon_id'       => $payload['anon_id'],
            'scale_code'    => $attempt->scale_code,
            'scale_version' => $attempt->scale_version,
            'attempt_id'    => $attemptId,
            'channel'       => $attempt->channel,
            'region'        => $payload['region'] ?? 'CN_MAINLAND',
            'locale'        => $payload['locale'] ?? 'zh-CN',
            'meta_json'     => [
                'answer_count'            => count($payload['answers']),
                'question_count'          => $attempt->question_count,
                'type_code'               => $result->type_code,
                'scores_pct'              => $scoresPct,
                'axis_states'             => $axisStates,
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
            ],
        ]);

        return response()->json([
            'ok'         => true,
            'attempt_id' => $attemptId,
            'result_id'  => $resultId,
            'result'     => [
                'type_code'               => $typeCode,
                'scores'                  => $scoresJson,
                'scores_pct'              => $scoresPct,
                'axis_states'             => $axisStates,
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
            ],
        ], 201);
    });
}

    /**
     * GET /api/v0.2/attempts/{id}/result
     * 注意：这里会写 result_view（但会在 logEvent 内 10 秒去抖）
     */
    public function getResult(Request $request, string $attemptId)
    {
        $result = Result::where('attempt_id', $attemptId)->first();

        if (!$result) {
            return response()->json([
                'ok'      => false,
                'error'   => 'RESULT_NOT_FOUND',
                'message' => 'Result not found for given attempt_id',
            ], 404);
        }

        $attempt = Attempt::where('id', $attemptId)->first();

        $shareId = trim((string) ($request->query('share_id') ?? $request->header('X-Share-Id') ?? ''));

        $this->logEvent('result_view', $request, [
            'anon_id'       => $attempt?->anon_id,
            'scale_code'    => $result->scale_code,
            'scale_version' => $result->scale_version,
            'attempt_id'    => $attemptId,
            'region'        => $attempt?->region ?? 'CN_MAINLAND',
            'locale'        => $attempt?->locale ?? 'zh-CN',
            'meta_json'     => [
                'type_code' => $result->type_code,
                'share_id'  => $shareId !== '' ? $shareId : null,
            ],
        ]);

        // ✅ 强制 5 轴全量输出
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        $scoresJson = $result->scores_json ?? [];
        $scoresPct  = $result->scores_pct ?? [];
        $axisStates = $result->axis_states ?? [];

        foreach ($dims as $d) {
            if (!array_key_exists($d, $scoresJson)) {
                $scoresJson[$d] = ['a' => 0, 'b' => 0, 'neutral' => 0, 'sum' => 0, 'total' => 0];
            }
            if (!array_key_exists($d, $scoresPct)) {
                $scoresPct[$d] = 50;
            }
            if (!array_key_exists($d, $axisStates)) {
                $axisStates[$d] = 'moderate';
            }
        }

        return response()->json([
            'ok'                      => true,
            'attempt_id'              => $attemptId,
            'scale_code'              => $result->scale_code,
            'scale_version'           => $result->scale_version,

            'type_code'               => $result->type_code,
            'scores'                  => $scoresJson,

            'scores_pct'              => $scoresPct,
            'axis_states'             => $axisStates,
            'profile_version'         => $result->profile_version ?? config('fap.profile_version', 'mbti32-v2.5'),
            'content_package_version' => $result->content_package_version ?? $this->currentContentPackageVersion(),

            'computed_at'             => $result->computed_at,
        ]);
    }

    /**
 * GET /api/v0.2/attempts/{id}/share
 * ✅ 返回分享文案骨架
 * ✅ 用 events 表做“10分钟去重”，避免重复请求导致 share_generate 膨胀
 * ✅ share_id 在 TTL 内复用（从最近一次 share_generate 的 meta_json.share_id 读取）
 */
public function getShare(Request $request, string $attemptId)
{
    $result = Result::where('attempt_id', $attemptId)->first();

    if (!$result) {
        return response()->json([
            'ok'      => false,
            'error'   => 'RESULT_NOT_FOUND',
            'message' => 'Result not found for given attempt_id',
        ], 404);
    }

    $attempt = Attempt::find($attemptId);

    $ttlMinutes = (int) config('fap.share_id_ttl_minutes', 10);
    if ($ttlMinutes <= 0) $ttlMinutes = 10;

    $typeCode = (string) $result->type_code;

    $contentPackageVersion = (string) (
        $result->content_package_version
        ?? $this->currentContentPackageVersion()
    );

    // ✅ 强并发幂等：Redis lock + cache + events
    $store = Cache::store(); // 你后面还要用 $store->get/$store->put，保留
    $lock  = Cache::lock($this->shareLockKey($attemptId), 8); // ✅ IDE/运行时都稳

    try {
        $locked = $lock->block(3, function () use (
            $store, $attemptId, $ttlMinutes, $request, $attempt, $result, $typeCode, $contentPackageVersion
        ) {
            $cacheKey = $this->shareCacheKey($attemptId);

            // 1) 先走 cache（最快）
            $cachedShareId = $store->get($cacheKey);
            if (is_string($cachedShareId) && trim($cachedShareId) !== '') {
                return [
                    'share_id' => trim($cachedShareId),
                    'is_new'   => false,
                ];
            }

            // 2) 查 events：TTL 内是否已有 share_generate
            $recent = \App\Models\Event::where('event_code', 'share_generate')
                ->where('attempt_id', $attemptId)
                ->where('occurred_at', '>=', now()->subMinutes($ttlMinutes))
                ->orderByDesc('occurred_at')
                ->first();

            $shareId = null;

            if ($recent) {
                $meta = $recent->meta_json ?? null;
                if (is_string($meta) && $meta !== '') {
                    $decoded = json_decode($meta, true);
                    if (is_array($decoded)) $meta = $decoded;
                }
                if (is_array($meta) && !empty($meta['share_id']) && is_string($meta['share_id'])) {
                    $shareId = trim($meta['share_id']);
                }
            }

            // 3) 仍没有：生成一次，并写 event（在 lock 内，只会发生一次）
            $isNew = false;
            if (!is_string($shareId) || $shareId === '') {
                $shareId = (string) Str::uuid();
                $isNew = true;

                // ✅ 从 header 里拿 experiment/version（跟 /events 一致）
$experiment = (string) ($request->header('X-Experiment') ?? '');
$version    = (string) ($request->header('X-App-Version') ?? '');

$this->logEvent('share_generate', $request, [
    'anon_id'       => $attempt?->anon_id,
    'scale_code'    => $result->scale_code,
    'scale_version' => $result->scale_version,
    'attempt_id'    => $attemptId,
    'channel'       => $attempt?->channel,
    'region'        => $attempt?->region ?? 'CN_MAINLAND',
    'locale'        => $attempt?->locale ?? 'zh-CN',
    'meta_json'     => [
        'engine'                  => 'v1.2',
        'type_code'               => $typeCode,
        'share_id'                => $shareId,
        'profile_version'         => $result->profile_version ?? config('fap.profile_version', 'mbti32-v2.5'),
        'content_package_version' => $contentPackageVersion,
        'share_id_ttl_minutes'    => $ttlMinutes,

        // ✅ AB 字段：share_generate 也要有（不然漏斗断）
        'experiment'              => $experiment !== '' ? $experiment : null,
        'version'                 => $version !== '' ? $version : null,
    ],
]);
            }

            // 4) 回写 cache（无论新旧，统一缓存到 TTL）
            $store->put($cacheKey, $shareId, now()->addMinutes($ttlMinutes));

            return [
                'share_id' => $shareId,
                'is_new'   => $isNew,
            ];
        });
    } catch (\Throwable $e) {
        // 拿不到锁/Redis 异常：给个可观测的错误（也可降级到“只查 recent 不写入”）
        Log::warning('[share] lock failed', [
            'attempt_id' => $attemptId,
            'err' => $e->getMessage(),
        ]);

        return response()->json([
            'ok'      => false,
            'error'   => 'SHARE_BUSY',
            'message' => 'Share generation is busy, please retry.',
        ], 429);
    } finally {
        optional($lock)->release();
    }

    $shareId = (string) ($locked['share_id'] ?? '');

    // ====== lock 外：加载内容包文案（不影响幂等，避免占锁太久）======
    $snippet = $this->loadShareSnippet($contentPackageVersion, $typeCode);
    $profile = $this->loadTypeProfile($contentPackageVersion, $typeCode);
    $merged  = array_merge($profile ?: [], $snippet ?: []);

    return response()->json([
        'ok'                      => true,
        'attempt_id'              => $attemptId,
        'share_id'                => $shareId,
        'content_package_version' => $contentPackageVersion,
        'type_code'               => $typeCode,

        'type_name'     => $merged['type_name'] ?? null,
        'tagline'       => $merged['tagline'] ?? null,
        'rarity'        => $merged['rarity'] ?? null,
        'keywords'      => $merged['keywords'] ?? [],
        'short_summary' => $merged['short_summary'] ?? null,
    ]);
}

/**
 * GET /api/v0.2/attempts/{id}/report
 * v1.2 动态报告引擎（M3-0：先把 JSON 契约定死）
 *
 * 约定：
 * - 不让前端传阈值、不让前端算 Top2、不让前端判断 borderline
 * - 字段必须稳定存在（哪怕内容是占位）
 */
public function getReport(Request $request, string $attemptId)
{
    $result = Result::where('attempt_id', $attemptId)->first();

    if (!$result) {
        return response()->json([
            'ok'      => false,
            'error'   => 'RESULT_NOT_FOUND',
            'message' => 'Result not found for given attempt_id',
        ], 404);
    }

    $attempt = Attempt::find($attemptId);

    $shareId = trim((string) ($request->query('share_id') ?? $request->header('X-Share-Id') ?? ''));

    // ✅ 事件仍然由 Controller 负责（因为它需要 $request）
    $this->logEvent('report_view', $request, [
        'anon_id'       => $attempt?->anon_id,
        'scale_code'    => $result->scale_code,
        'scale_version' => $result->scale_version,
        'attempt_id'    => $attemptId,
        'region'        => $attempt?->region ?? 'CN_MAINLAND',
        'locale'        => $attempt?->locale ?? 'zh-CN',
        'meta_json'     => [
            'type_code' => $result->type_code,
            'engine'    => 'v1.2',
            'share_id'  => $shareId !== '' ? $shareId : null,
        ],
    ]);

    /** @var \App\Services\Report\ReportComposer $composer */
    $composer = app(ReportComposer::class);

    $res = $composer->compose($attemptId, [
        // composer 里要用到的默认/上下文
        'defaultProfileVersion'        => config('fap.profile_version', 'mbti32-v2.5'),
        'defaultContentPackageVersion' => $this->currentContentPackageVersion(),
        'currentContentPackageVersion' => fn () => $this->currentContentPackageVersion(),

        // 把 Controller 私有 loader/helper 用闭包注入
        'loadTypeProfile' => fn (string $pkg, string $type)
            => $this->loadTypeProfile($pkg, $type),

        'loadReportAssetItems' => fn (string $pkg, string $file, ?string $k = 'type_code')
            => $this->loadReportAssetItems($pkg, $file, $k),

        'buildRoleCard' => fn (string $pkg, string $type)
            => $this->buildRoleCard($pkg, $type),

        'buildStrategyCard' => fn (string $pkg, string $type)
            => $this->buildStrategyCard($pkg, $type),

        'buildBorderlineNote' => fn (array $scoresPct, string $pkg)
            => $this->buildBorderlineNote($scoresPct, $pkg),

        'buildRecommendedReads' => fn (string $pkg, string $type, array $scoresPct)
            => $this->buildRecommendedReads($pkg, $type, $scoresPct),
    ]);

    if (!($res['ok'] ?? false)) {
        return response()->json([
            'ok'      => false,
            'error'   => $res['error'] ?? 'REPORT_FAILED',
            'message' => $res['message'] ?? 'Report compose failed',
        ], (int) ($res['status'] ?? 500));
    }

    $reportPayload = $res['report'] ?? [];

    // ✅ 最终兜底：强制 highlights 满足契约（kind/id/title/text + tips/tags 非空）
if (isset($reportPayload['highlights']) && is_array($reportPayload['highlights'])) {
    $type = (string)($res['type_code'] ?? $result->type_code ?? '');
    $reportPayload['highlights'] = $this->finalizeHighlightsSchema($reportPayload['highlights'], $type);
}

    return response()->json([
        'ok'         => true,
        'attempt_id' => $attemptId,
        'type_code'  => $res['type_code'] ?? $result->type_code,
        'report'     => $reportPayload,

        // 兼容旧返回（你之前做了 ...$reportPayload）
        ...$reportPayload,
    ]);
}

    // =========================
    // Private helpers
    // =========================

/**
 * M3-3: 从 templates + overrides 动态生成 highlights
 *
 * 输出结构（每张卡字段固定）：
 * {id, dim, side, level, pct, delta, title, text, tips, tags}
 *
 * overrides 支持两种写法（二选一即可）：
 * 1) items[type_code][card_id] = {...partial fields...}
 * 2) items[type_code][dim][side][level] = {...partial fields...}
 */
private function buildHighlights(array $scoresPct, array $axisStates, string $typeCode, string $contentPackageVersion): array
{
    $tpl = $this->loadReportAssetJson($contentPackageVersion, 'report_highlights_templates.json');
    $ovr = $this->loadReportAssetJson($contentPackageVersion, 'report_highlights_overrides.json');

    // fallback: old static highlights by type (report_highlights.json)
    $oldItems   = $this->loadReportAssetItems($contentPackageVersion, 'report_highlights.json');
    $oldPerType = is_array($oldItems[$typeCode] ?? null) ? $oldItems[$typeCode] : [];

    $tplRules     = is_array($tpl['rules'] ?? null) ? $tpl['rules'] : [];
    $tplTemplates = is_array($tpl['templates'] ?? null) ? $tpl['templates'] : [];

    // 规则（读取 templates.rules；没有就用默认）
    $topN        = (int) ($tplRules['top_n'] ?? 2);
    $maxItems    = (int) ($tplRules['max_items'] ?? 2);

    // ✅ M3 要求：最少 3 条（硬指标）
    $minItems    = (int) ($tplRules['min_items'] ?? 3);
    if ($minItems < 3) $minItems = 3;

    // ✅ 统一 delta 口径：0..50（与 report.scores.delta 一致）
    $minDelta    = (int) ($tplRules['min_delta'] ?? 15); // 0..50 的阈值（建议 10~20）
    $minLevel    = (string) ($tplRules['min_level'] ?? 'clear');
    $allowEmpty  = (bool) ($tplRules['allow_empty'] ?? true);

    $allowedLvls = $tplRules['allowed_levels'] ?? ['clear','strong','very_strong'];
    $levelOrder  = $tplRules['level_order'] ?? ['very_weak','weak','moderate','clear','strong','very_strong'];
    $idFormat    = (string) ($tplRules['id_format'] ?? '${dim}_${side}_${level}');

    if (!is_array($allowedLvls)) $allowedLvls = ['clear','strong','very_strong'];
    if (!is_array($levelOrder))  $levelOrder  = ['very_weak','weak','moderate','clear','strong','very_strong'];

    // overrides
    $ovrItems = is_array($ovr['items'] ?? null) ? $ovr['items'] : [];
    $perType  = is_array($ovrItems[$typeCode] ?? null) ? $ovrItems[$typeCode] : [];

    $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];
    $candidates = [];

    foreach ($dims as $dim) {
        $rawPct = (int) ($scoresPct[$dim] ?? 50);
        $level  = (string) ($axisStates[$dim] ?? 'moderate');

        [$p1, $p2] = $this->getDimensionPoles($dim);

        // side：按 rawPct 决定落在哪一极
        $side = ($rawPct >= 50) ? $p1 : $p2;

        // ✅ pct：统一成“偏向 side 的强度”(50..100)
        $displayPct = ($rawPct >= 50) ? $rawPct : (100 - $rawPct);

        // ✅ delta：0..50
        $delta = abs($displayPct - 50);

        // gate: allowed levels
        if (!in_array($level, $allowedLvls, true)) {
            continue;
        }

        // gate: min_level（按 level_order 比较）
        $idxLevel = array_search($level, $levelOrder, true);
        $idxMin   = array_search($minLevel, $levelOrder, true);
        if ($idxLevel === false || $idxMin === false || $idxLevel < $idxMin) {
            continue;
        }

        // gate: min_delta (0..50)
        if ($delta < $minDelta) {
            continue;
        }

        // template hit: templates[dim][side][level]
        $hit = $tplTemplates[$dim][$side][$level] ?? null;
        if (!is_array($hit)) {
            continue;
        }

        // ensure id
        $id = (string) ($hit['id'] ?? '');
        if ($id === '') {
            $id = str_replace(['${dim}','${side}','${level}'], [$dim,$side,$level], $idFormat);
        }

        // normalize base card
        $card = [
            'id'    => $id,
            'dim'   => $dim,
            'side'  => $side,
            'level' => $level,
            'pct'   => $displayPct,   // ✅ 50..100
            'delta' => $delta,        // ✅ 0..50
            'title' => (string) ($hit['title'] ?? ''),
            'text'  => (string) ($hit['text'] ?? ''),
            'tips'  => is_array($hit['tips'] ?? null) ? $hit['tips'] : [],
            'tags'  => is_array($hit['tags'] ?? null) ? $hit['tags'] : [],
        ];

        // overrides (mode=merge)
        $override = null;

        // 1) by card_id
        if (isset($perType[$id]) && is_array($perType[$id])) {
            $override = $perType[$id];
        }

        // 2) by dim/side/level
        if ($override === null) {
            $o2 = $perType[$dim][$side][$level] ?? null;
            if (is_array($o2)) $override = $o2;
        }

        if (is_array($override)) {
    // ✅ 关键：忽略 null 覆盖（防止 title/tips/tags 被覆盖成 null）
    $card = $this->mergeNonNullRecursive($card, $override);

    // ✅ tips/tags：只有 override 给了“数组”才覆盖；给 null 就当没给
    if (array_key_exists('tips', $override) && is_array($override['tips'])) {
        $card['tips'] = $override['tips'];
    }
    if (array_key_exists('tags', $override) && is_array($override['tags'])) {
        $card['tags'] = $override['tags'];
    }

    if (!is_array($card['tips'] ?? null)) $card['tips'] = [];
    if (!is_array($card['tags'] ?? null)) $card['tags'] = [];
        }

        $candidates[] = $card;
    }

    // sort by delta desc
    usort($candidates, function ($a, $b) {
        return (int) ($b['delta'] ?? 0) <=> (int) ($a['delta'] ?? 0);
    });

    // ====== 取数策略：至少 3 条（minItems），但也尊重 maxItems / topN 的意图 ======
    $take = max($minItems, min(max($topN, 0), max($maxItems, 0)));
    if ($take < $minItems) $take = $minItems;

    $out  = array_slice($candidates, 0, $take);

    // ====== 若模板命中为空：fallback 旧版（但必须归一化到新结构）======
    if (empty($out)) {
        $norm = [];
        if (is_array($oldPerType) && !empty($oldPerType)) {
            foreach (array_values($oldPerType) as $c) {
                if (!is_array($c)) continue;

                $id = (string) ($c['id'] ?? '');
                if ($id === '') continue;

                $dim   = $c['dim']   ?? null;
                $side  = $c['side']  ?? null;
                $level = $c['level'] ?? null;

                // 尝试从 id 解析：EI_E_clear / AT_A_very_strong 这类
                if ((!$dim || !$side || !$level)
                    && preg_match('/^(EI|SN|TF|JP|AT)_([EISNTFJPA])_(clear|strong|very_strong)$/', $id, $m)) {
                    $dim   = $m[1];
                    $side  = $m[2];
                    $level = $m[3];
                }

                if (!$dim || !$side || !$level) continue;

                $rawPct = (int) ($scoresPct[$dim] ?? 50);
                $displayPct = ($rawPct >= 50) ? $rawPct : (100 - $rawPct);
                $delta = abs($displayPct - 50);

                $title = (string) ($c['title'] ?? '');
                $text  = (string) ($c['text']  ?? $title);

                $norm[] = [
                    'id'    => $id,
                    'dim'   => $dim,
                    'side'  => $side,
                    'level' => $level,
                    'pct'   => $displayPct,
                    'delta' => $delta,
                    'title' => $title,
                    'text'  => $text,
                    'tips'  => is_array($c['tips'] ?? null) ? $c['tips'] : [],
                    'tags'  => is_array($c['tags'] ?? null) ? $c['tags'] : [],
                ];
            }
        }

        // 旧版也可能不足 3：继续补齐
        $out = array_slice($norm, 0, $take);
    }

    // =========================
    // ✅ M3 硬保证：至少 3 条（补齐：强项 / 风险 / 建议）
    // =========================

    // 统一成 list + 去重（按 id）
    $out = array_values(array_filter($out ?? [], fn($x) => is_array($x)));
    $seen = [];
    $uniq = [];
    foreach ($out as $h) {
        $id = (string)($h['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) continue;
        if ($id !== '') $seen[$id] = true;
        $uniq[] = $h;
    }
    $out = $uniq;

    // 生成 fallback highlight 的小工具（不依赖模板也能出卡）
    $makeFallback = function (string $kind, string $dim, string $side, string $level, int $pct, int $delta) use ($typeCode) {
        $dimName = [
            'EI' => '能量来源',
            'SN' => '信息偏好',
            'TF' => '决策方式',
            'JP' => '行事节奏',
            'AT' => '压力姿态',
        ][$dim] ?? $dim;

        $hint = match ($dim) {
            'EI' => ($side === 'E' ? '更可能在互动中获得能量与清晰度' : '更可能在独处中恢复能量与思考质量'),
            'SN' => ($side === 'S' ? '更重视可落地的细节与现实路径' : '更擅长从趋势与可能性中抓重点'),
            'TF' => ($side === 'T' ? '更倾向用标准/逻辑来做取舍' : '更倾向用感受/价值来做取舍'),
            'JP' => ($side === 'J' ? '更喜欢计划与收束，推进更稳' : '更喜欢灵活与探索，适应更快'),
            'AT' => ($side === 'A' ? '更稳、更敢拍板' : '更敏感、更会自省与校准'),
            default => '把优势用在对的场景',
        };

        $title = match ($kind) {
            'strength' => "强项：你的{$dimName}更偏 {$side}",
            'risk'     => "盲点：{$dimName}容易出现“惯性误判”",
            default    => "建议：把{$dimName}优势用对地方",
        };

        $text = match ($kind) {
            'strength' => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）：{$hint}。这会让你在相关场景里更容易做出高质量决策与行动。",
            'risk'     => "在「{$dimName}」上，你更偏 {$side}（强度 {$pct}%）。优势用过头时可能变成惯性：建议你在关键场景加入一次“反向校验”，避免单一路径误判。",
            default    => "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）。给自己加一个小流程：先写下第一反应，再补一个反向备选，然后再做决定/表达，效果会更稳。",
        };

        $tips = match ($kind) {
            'strength' => ["把这个优势固定成你的“常用模板/流程”", "在团队里明确：你负责哪类决策最擅长"],
            'risk'     => ["重要决定前写一个“反方理由”", "找一个互补型的人做 2 分钟校验"],
            default    => ["第一反应写下来，再补一个反向备选", "给重要决定加 10 分钟冷却/复盘"],
        };

        return [
            'id'    => "hl_fallback_{$kind}_{$typeCode}_{$dim}_{$side}",
            'dim'   => $dim,
            'side'  => $side,
            'level' => $level,
            'pct'   => $pct,
            'delta' => $delta,
            'title' => $title,
            'text'  => $text,
            'tips'  => $tips,
            'tags'  => ["kind:{$kind}", "axis:{$dim}:{$side}", "fallback:true"],
        ];
    };

    // 计算每轴（side/pct/delta/level）
    $axisInfo = [];
    foreach (['EI','SN','TF','JP','AT'] as $dim) {
        $rawPct = (int)($scoresPct[$dim] ?? 50);
        $level  = (string)($axisStates[$dim] ?? 'moderate');
        [$p1, $p2] = $this->getDimensionPoles($dim);
        $side = ($rawPct >= 50) ? $p1 : $p2;
        $pct  = ($rawPct >= 50) ? $rawPct : (100 - $rawPct);
        $delta= abs($pct - 50);
        $axisInfo[$dim] = compact('dim','side','pct','delta','level');
    }

    // 选 strongest / weakest
    $byDeltaDesc = array_values($axisInfo);
    usort($byDeltaDesc, fn($a,$b) => ($b['delta'] ?? 0) <=> ($a['delta'] ?? 0));
    $byDeltaAsc  = array_values($axisInfo);
    usort($byDeltaAsc, fn($a,$b) => ($a['delta'] ?? 0) <=> ($b['delta'] ?? 0));

    // 确保至少有：strength / risk / action（三类）
    $needKinds = ['strength', 'risk', 'action'];
    $hasKinds = [];
    foreach ($out as $h) {
        $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];
        foreach ($tags as $t) {
            if (is_string($t) && str_starts_with($t, 'kind:')) {
                $k = substr($t, 5);
                $hasKinds[$k] = true;
            }
        }
    }

    foreach ($needKinds as $k) {
        if (isset($hasKinds[$k])) continue;

        if ($k === 'strength') {
            $pick = $byDeltaDesc[0] ?? null;
        } elseif ($k === 'risk') {
            $pick = $byDeltaAsc[0] ?? null;
        } else { // action
            // 优先用 AT（最贴近“行动建议/压力策略”），否则用 strongest
            $pick = $axisInfo['AT'] ?? ($byDeltaDesc[0] ?? null);
        }

        if ($pick) {
            $out[] = $makeFallback(
                $k,
                (string)$pick['dim'],
                (string)$pick['side'],
                (string)$pick['level'],
                (int)$pick['pct'],
                (int)$pick['delta']
            );
        }
    }

    // 最终：至少 3 条，不超过 8 条
    $out = array_values(array_filter($out, fn($x) => is_array($x)));
    // 去重（按 id）
    $seen = [];
    $uniq = [];
    foreach ($out as $h) {
        $id = (string)($h['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) continue;
        if ($id !== '') $seen[$id] = true;
        $uniq[] = $h;
    }
    $out = $uniq;

    // 仍然按 delta desc 排一下（更像“先给用户看最重要的”）
    usort($out, fn($a,$b) => (int)($b['delta'] ?? 0) <=> (int)($a['delta'] ?? 0));

    if (count($out) < 3 && !$allowEmpty) {
        // allowEmpty=false 也不可能返回空：补 3 条
        while (count($out) < 3) {
            $pick = $byDeltaDesc[count($out)] ?? ($byDeltaDesc[0] ?? null);
            if (!$pick) break;
            $out[] = $makeFallback(
                'action',
                (string)$pick['dim'],
                (string)$pick['side'],
                (string)$pick['level'],
                (int)$pick['pct'],
                (int)$pick['delta']
            );
        }
    }

    // 先补齐 kind/axis 标签
$out = $this->normalizeHighlightKinds($out);

// ✅ UX 排序：strength / risk / action 优先，其次 axis；同类再按 delta 降序
$out = $this->sortHighlightsForUX($out);

return array_slice($out, 0, 8);
}

/**
 * 给 highlights 自动补 kind 标签：
 * - 如果 card 没有任何 kind:*，默认补 kind:axis
 * - 不覆盖已有 kind（比如 fallback:true 的那三张）
 * - 顺便补 axis:{dim}:{side} 标签（若缺失）
 */
private function normalizeHighlightKinds(array $cards): array
{
    foreach ($cards as &$c) {
        if (!is_array($c)) continue;

        $tags = $c['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        // 是否已有 kind:*
        $hasKind = false;
        foreach ($tags as $t) {
            if (is_string($t) && str_starts_with($t, 'kind:')) {
                $hasKind = true;
                break;
            }
        }

        // 没 kind 的：补成 kind:axis（避免跟 fallback 的 strength/risk/action 重复）
        if (!$hasKind) {
            $tags[] = 'kind:axis';
        }

        // ✅ 同步写入 kind 字段（CI 要求 report.highlights[].kind）
if (!is_string($c['kind'] ?? null) || trim((string)$c['kind']) === '') {
    $k = null;
    foreach ($tags as $t) {
        if (is_string($t) && str_starts_with($t, 'kind:')) {
            $k = substr($t, 5);
            break;
        }
    }
    $c['kind'] = is_string($k) && $k !== '' ? $k : 'axis';
}

        // 补 axis:DIM:SIDE（如果缺）
        $dim  = (string)($c['dim'] ?? '');
        $side = (string)($c['side'] ?? '');
        if ($dim !== '' && $side !== '') {
            $axisTag = "axis:{$dim}:{$side}";
            if (!in_array($axisTag, $tags, true)) {
                $tags[] = $axisTag;
            }
        }

        // 去重（保持顺序）
        $dedup = [];
        foreach ($tags as $t) {
            if (!is_string($t) || $t === '') continue;
            if (!in_array($t, $dedup, true)) $dedup[] = $t;
        }

        $c['tags'] = $dedup;
    }
    unset($c);

    return $cards;
}

    /**
 * borderline_note：对“靠近中间”的轴给解释（最多 2 条）
 *
 * delta100 = abs(pct-50)*2  (0..100)
 * - strong: delta100 <= 12  (abs<=6)
 * - light : delta100 14..24 (abs 7..12)
 *
 * ✅ 排序规则“定死”：
 * 1) delta 小优先（越接近 50 越要解释）
 * 2) 同 delta 用固定优先级：EI > SN > TF > JP > AT
 */
private function buildBorderlineNote(array $scoresPct, string $contentPackageVersion): array
{
    $tpl   = $this->loadReportAssetJson($contentPackageVersion, 'report_borderline_templates.json');
    $items = is_array($tpl['items'] ?? null) ? $tpl['items'] : [];

    $dims = ['EI','SN','TF','JP','AT'];
    $priority = ['EI'=>0,'SN'=>1,'TF'=>2,'JP'=>3,'AT'=>4];

    // ✅ 默认 true：强+轻都输出；以后想关 light 就改成 false 或接 config
    $includeLight = true;

    $cands = [];
    foreach ($dims as $dim) {
        $pct = (int) ($scoresPct[$dim] ?? 50);
        $delta100 = abs($pct - 50) * 2;

        // strong
        if ($delta100 <= 12) {
            $cands[] = ['dim'=>$dim,'pct'=>$pct,'delta'=>$delta100];
            continue;
        }

        // light
        if ($includeLight && $delta100 >= 14 && $delta100 <= 24) {
            $cands[] = ['dim'=>$dim,'pct'=>$pct,'delta'=>$delta100];
            continue;
        }
    }

    usort($cands, function ($a, $b) use ($priority) {
        $da = (int)($a['delta'] ?? 999);
        $db = (int)($b['delta'] ?? 999);
        if ($da !== $db) return $da <=> $db;

        $pa = $priority[$a['dim'] ?? 'AT'] ?? 99;
        $pb = $priority[$b['dim'] ?? 'AT'] ?? 99;
        return $pa <=> $pb;
    });

    $out = [];
    foreach ($cands as $c) {
        if (count($out) >= 2) break;

        $dim = (string)($c['dim'] ?? '');
        $t = $items[$dim] ?? null;
        if (!is_array($t)) continue;

        $out[] = [
            'dim'         => $dim,
            'title'       => (string) ($t['title'] ?? ''),
            'text'        => (string) ($t['text'] ?? ''),
            'examples'    => is_array($t['examples'] ?? null) ? $t['examples'] : [],
            'suggestions' => is_array($t['suggestions'] ?? null) ? $t['suggestions'] : [],
        ];
    }

    return ['items' => $out];
}

    /**
 * 读取“非 items 结构”的 report assets（templates/overrides）
 * - 走与你 loadReportAssetItems 同一套多路径兜底
 * - 返回整个 JSON array（不做 items 结构重建）
 *
 * ✅ config-cache 安全：不再直接读 env()
 */
private function loadReportAssetJson(string $contentPackageVersion, string $filename): array
{
    $contentPackageVersion = $this->normalizeContentPackageDir($contentPackageVersion); // ✅
    static $cache = [];

    $cacheKey = $contentPackageVersion . '|' . $filename . '|RAW';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $pkg = trim($contentPackageVersion, "/\\");

    // ✅ env -> config（config-cache 安全）
    $cfgRoot = config('fap.content_packages_dir', null);
    $cfgRoot = is_string($cfgRoot) && $cfgRoot !== '' ? rtrim($cfgRoot, '/') : null;

    $candidates = array_values(array_filter([
        storage_path("app/private/content_packages/{$pkg}/{$filename}"),
        storage_path("app/content_packages/{$pkg}/{$filename}"),
        base_path("../content_packages/{$pkg}/{$filename}"),
        base_path("content_packages/{$pkg}/{$filename}"),
        $cfgRoot ? "{$cfgRoot}/{$pkg}/{$filename}" : null,
    ]));

    $path = null;
    foreach ($candidates as $p) {
        if (is_string($p) && $p !== '' && file_exists($p)) {
            $path = $p;
            break;
        }
    }

    if ($path === null) {
        return $cache[$cacheKey] = [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $cache[$cacheKey] = [];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return $cache[$cacheKey] = [];
    }

    return $cache[$cacheKey] = $json;
}

    /**
     * 从内容包读取 share_snippets.json 并返回指定 type_code（统一走多路径兜底）
     */
    private function loadShareSnippet(string $contentPackageVersion, string $typeCode): ?array
    {
        $items = $this->loadReportAssetItems($contentPackageVersion, 'share_snippets.json');
        $snippet = $items[$typeCode] ?? null;
        return is_array($snippet) ? $snippet : null;
    }

    /**
     * 维度极性定义（第一极 / 第二极）
     */
    private function getDimensionPoles(string $dim): array
    {
        return match ($dim) {
            'EI' => ['E', 'I'],
            'SN' => ['S', 'N'],
            'TF' => ['T', 'F'],
            'JP' => ['J', 'P'],
            'AT' => ['A', 'T'],
            default => ['', ''],
        };
    }

    private function shareLockKey(string $attemptId): string
{
    return "lock:attempt:{$attemptId}:share";
}

private function shareCacheKey(string $attemptId): string
{
    return "attempt:{$attemptId}:share_id";
}

/**
 * 在内容包目录里找某个文件（兼容：根目录 or 子目录）
 * - 支持多根目录兜底（与 loadReportAssetJson/loadReportAssetItems 对齐）
 * - 优先命中 root/<filename>
 * - 否则递归搜索（限制深度，避免扫太大）
 */
private function findPackageFile(string $pkg, string $filename, int $maxDepth = 3): ?string
{
    $pkg = $this->normalizeContentPackageDir($pkg);   // ✅ 加这一行
    $pkg = trim($pkg, "/\\");
    $filename = trim($filename, "/\\");

    // ✅ config-cache 安全：用 config 取 content_packages_dir
    $cfgRoot = config('fap.content_packages_dir', null);
    $cfgRoot = is_string($cfgRoot) && $cfgRoot !== '' ? rtrim($cfgRoot, '/') : null;

    // ✅ 与其它 asset loader 对齐：多路径 root candidates
    $roots = array_values(array_filter([
        storage_path("app/private/content_packages/{$pkg}"),
        storage_path("app/content_packages/{$pkg}"),
        base_path("../content_packages/{$pkg}"),
        base_path("content_packages/{$pkg}"),
        $cfgRoot ? "{$cfgRoot}/{$pkg}" : null,
    ], fn($p) => is_string($p) && $p !== '' && is_dir($p)));

    if (empty($roots)) {
        return null;
    }

    foreach ($roots as $root) {
        // 1) 优先根目录直达
        $direct = $root . DIRECTORY_SEPARATOR . $filename;
        if (is_file($direct)) {
            return $direct;
        }

        // 2) 递归找（限制深度）
        try {
            $dirIter = new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );
            $iter = new \RecursiveIteratorIterator($dirIter, \RecursiveIteratorIterator::SELF_FIRST);
            
            // ✅ 真正限制扫描深度（避免扫爆 content_packages）
            $iter->setMaxDepth($maxDepth);

            foreach ($iter as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) continue;
                if ($fileInfo->getFilename() !== $filename) continue;

                // 计算相对深度（root 下面第几层）
                $relDir = str_replace($root, '', $fileInfo->getPath());
                $relDir = trim(str_replace('\\', '/', $relDir), '/');
                $depth = ($relDir === '') ? 0 : substr_count($relDir, '/') + 1;

                if ($depth <= $maxDepth) {
                    return $fileInfo->getPathname();
                }
            }
        } catch (\Throwable $e) {
            // ignore this root and continue
            continue;
        }
    }

    return null;
}

    /**
     * 读取题库，并构建 question_id => meta 索引
     * meta: dimension, key_pole, direction, score_map
     */
    private function getQuestionsIndex(): array
    {
        if ($this->questionsIndex !== null) {
            return $this->questionsIndex;
        }

        $pkg = $this->normalizeContentPackageDir($this->currentContentPackageVersion());
        $path = $this->findPackageFile($pkg, 'questions.json');

        if (!$path || !is_file($path)) {
            $this->questionsIndex = [];
            return $this->questionsIndex;
        }

        $json = json_decode(file_get_contents($path), true);
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
            ];
        }

        $this->questionsIndex = $idx;
        return $this->questionsIndex;
    }

    /**
     * 从内容包读取 type_profiles.json 并返回指定 type_code 的 profile
     * 兼容：root/type_profiles.json 或子目录内的 type_profiles.json
     */
    private function loadTypeProfile(string $contentPackageVersion, string $typeCode): array
    {
        $contentPackageVersion = $this->normalizeContentPackageDir($contentPackageVersion); // ✅
        $path = $this->findPackageFile($contentPackageVersion, 'type_profiles.json');

        if (!$path || !is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $profile = $items[$typeCode] ?? null;
        return is_array($profile) ? $profile : [];
    }

    /**
 * 读取 report assets（identity_cards / share_snippets / 其它 assets）
 * - 多路径兜底（storage private -> storage -> repo root -> backend）
 * - 兼容 JSON 结构：{items:{...}} / {items:[...]} / 直接是对象/数组
 * - 若 items 是 list，会按 type_code（或 meta.type_code）重建索引，避免 $items["ENTJ-A"] 取不到
 *
 * ✅ config-cache 安全：不再直接读 env()
 */
private function loadReportAssetItems(string $contentPackageVersion, string $filename, ?string $primaryIndexKey = 'type_code'): array
{
    $contentPackageVersion = $this->normalizeContentPackageDir($contentPackageVersion); // ✅
    static $cache = [];

    $cacheKey = $contentPackageVersion . '|' . $filename . '|' . (string)($primaryIndexKey ?? 'null');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $pkg = trim($contentPackageVersion, "/\\");

    // ✅ env -> config（config-cache 安全）
    $cfgRoot = config('fap.content_packages_dir', null);
    $cfgRoot = is_string($cfgRoot) && $cfgRoot !== '' ? rtrim($cfgRoot, '/') : null;

    $candidates = array_values(array_filter([
        storage_path("app/private/content_packages/{$pkg}/{$filename}"),
        storage_path("app/content_packages/{$pkg}/{$filename}"),
        base_path("../content_packages/{$pkg}/{$filename}"),
        base_path("content_packages/{$pkg}/{$filename}"),
        $cfgRoot ? "{$cfgRoot}/{$pkg}/{$filename}" : null,
    ]));

    $path = null;
    foreach ($candidates as $p) {
        if (is_string($p) && $p !== '' && file_exists($p)) {
            $path = $p;
            break;
        }
    }

    if ($path === null) {
        return $cache[$cacheKey] = [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $cache[$cacheKey] = [];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return $cache[$cacheKey] = [];
    }

    $items = $json['items'] ?? $json;
    if (!is_array($items)) {
        return $cache[$cacheKey] = [];
    }

    $keys = array_keys($items);
    $isList = (count($keys) > 0) && ($keys === range(0, count($keys) - 1));

    if ($isList) {
        $indexed = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;

            $k = null;
            if ($primaryIndexKey && isset($it[$primaryIndexKey])) {
                $k = $it[$primaryIndexKey];
            } elseif (isset($it['type_code'])) {
                $k = $it['type_code'];
            } elseif (isset($it['meta']['type_code'])) {
                $k = $it['meta']['type_code'];
            } elseif (isset($it['id'])) {
                $k = $it['id'];
            } elseif (isset($it['code'])) {
                $k = $it['code'];
            }

            if (!$k) continue;
            $indexed[(string) $k] = $it;
        }
        $items = $indexed;
    }

    return $cache[$cacheKey] = $items;
}

    /**
 * M3-5: role_code 规则（16P 同款）
 * - 若第二字母是 N：Role = N + (第三字母 T/F) => NT / NF
 * - 若第二字母是 S：Role = S + (第四字母 J/P) => SJ / SP
 */
private function roleCodeFromType(string $typeCode): string
{
    if (preg_match('/^(E|I)(S|N)(T|F)(J|P)-(A|T)$/', $typeCode, $m)) {
        $sn = $m[2]; // S/N
        $tf = $m[3]; // T/F
        $jp = $m[4]; // J/P
        if ($sn === 'N') return 'N' . $tf;   // NT / NF
        return 'S' . $jp;                   // SJ / SP
    }
    return 'NT';
}

/**
 * M3-5: strategy_code 规则（EI + AT）
 * - EA / ET / IA / IT
 */
private function strategyCodeFromType(string $typeCode): string
{
    if (preg_match('/^(E|I)(S|N)(T|F)(J|P)-(A|T)$/', $typeCode, $m)) {
        $ei = $m[1]; // E/I
        $at = $m[5]; // A/T
        return $ei . $at; // EA/ET/IA/IT
    }
    return 'EA';
}

private function buildRoleCard(string $contentPackageVersion, string $typeCode): array
{
    $items = $this->loadReportAssetItems($contentPackageVersion, 'report_roles.json', 'code');
    $code  = $this->roleCodeFromType($typeCode);

    $base = [
        'code'     => $code,
        'title'    => '',
        'subtitle' => '',
        'theme'    => ['color' => ''],
        'desc'     => '',
        'tags'     => [],
    ];

    $card = $items[$code] ?? null;
    if (!is_array($card)) $card = [];

    $out = array_replace_recursive($base, $card);

    if (!is_array($out['theme'] ?? null)) $out['theme'] = ['color' => ''];
    if (!is_string($out['theme']['color'] ?? '')) $out['theme']['color'] = '';
    if (!is_array($out['tags'] ?? null)) $out['tags'] = [];

    $out['code'] = $code; // 强制一致
    return $out;
}

private function buildStrategyCard(string $contentPackageVersion, string $typeCode): array
{
    $items = $this->loadReportAssetItems($contentPackageVersion, 'report_strategies.json', 'code');
    $code  = $this->strategyCodeFromType($typeCode);

    $base = [
        'code'     => $code,
        'title'    => '',
        'subtitle' => '',
        'desc'     => '',
        'tags'     => [],
    ];

    $card = $items[$code] ?? null;
    if (!is_array($card)) $card = [];

    $out = array_replace_recursive($base, $card);

    if (!is_array($out['tags'] ?? null)) $out['tags'] = [];
    $out['code'] = $code; // 强制一致
    return $out;
}

private function buildRecommendedReads(string $contentPackageVersion, string $typeCode, array $scoresPct, int $max = 8): array
{
    $raw   = $this->loadReportAssetJson($contentPackageVersion, 'report_recommended_reads.json');
    $items = is_array($raw['items'] ?? null) ? $raw['items'] : [];
    $rules = is_array($raw['rules'] ?? null) ? $raw['rules'] : [];

    // ----------------------------
    // Debug switch (dev only)
    // ----------------------------
    $debugReads = (
    app()->environment('local', 'development')
    && (bool) config('fap.reads_debug', false)
);
    $debug = [
        'pkg' => $contentPackageVersion,
        'type' => $typeCode,
        'max' => $max,
        'rules' => [],
        'axis' => [],
        'buckets' => [],
        'min_items_fill' => null,
    ];

    // ----------------------------
    // 1) rules
    // ----------------------------
    $maxItems  = (int) ($rules['max_items'] ?? $max);
    $minItems  = (int) ($rules['min_items'] ?? 0);
    $sortMode  = (string) ($rules['sort'] ?? ''); // e.g. "priority_desc"
    $fillOrder = is_array($rules['fill_order'] ?? null)
        ? $rules['fill_order']
        : ['by_type','by_role','by_strategy','by_top_axis','fallback'];

    $bucketQuota = is_array($rules['bucket_quota'] ?? null) ? $rules['bucket_quota'] : [];
    $defaults    = is_array($rules['defaults'] ?? null) ? $rules['defaults'] : [];

    $dedupe = is_array($rules['dedupe'] ?? null) ? $rules['dedupe'] : [];
    $hardBy = is_array($dedupe['hard_by'] ?? null) ? $dedupe['hard_by'] : ['id'];
    $softBy = is_array($dedupe['soft_by'] ?? null) ? $dedupe['soft_by'] : ['canonical_id','canonical_url','url'];
    $forbidTags = $rules['forbid_tags'] ?? [];
if (!is_array($forbidTags)) $forbidTags = [];

$requireAnyTags = $rules['require_any_tags'] ?? [];
if (!is_array($requireAnyTags)) $requireAnyTags = [];

$requireAllTags = $rules['require_all_tags'] ?? [];
if (!is_array($requireAllTags)) $requireAllTags = [];

// ============================
// ✅ RuleEngine (reads) setup
// ============================
/** @var \App\Services\Rules\RuleEngine $re */
$re = app(RuleEngine::class);

// ✅ 关键改动：reads_debug 开了就强制打开 RE explain
$debugRE = $debugReads || (
    app()->environment('local', 'development')
    && (bool) config('fap.re_debug', false)
);

$ctx  = "reads:{$typeCode}";
$seed = crc32($ctx . '|' . $contentPackageVersion . '|' . $typeCode);

// userTagsSet：把“用户画像标签”转成 set（给 require/forbid 命中用）
$roleCode = $this->roleCodeFromType($typeCode);
$strategyCode = $this->strategyCodeFromType($typeCode);

$userTags = [
    "type:{$typeCode}",
    "role:{$roleCode}",
    "strategy:{$strategyCode}",
];

// 补齐全部轴 side（这样 reads 以后也能写 axis 规则）
foreach (['EI','SN','TF','JP','AT'] as $d) {
    $pct = (int)($scoresPct[$d] ?? 50);
    [$p1, $p2] = $this->getDimensionPoles($d);
    $side = ($pct >= 50) ? $p1 : $p2;
    $userTags[] = "axis:{$d}:{$side}";
}

$userTags = array_values(array_unique(array_filter($userTags, fn($x) => is_string($x) && trim($x) !== '')));
// RuleEngine 用 set（更快更明确）
$userTagsSet = array_fill_keys($userTags, true);

// globalRules：把 reads 的 require_*_tags / forbid_tags 映射成 RuleEngine 字段
$globalRules = [
    'require_all' => array_values(array_filter($requireAllTags, fn($x)=>is_string($x)&&trim($x)!=='')),
    'require_any' => array_values(array_filter($requireAnyTags, fn($x)=>is_string($x)&&trim($x)!=='')),
    'forbid'      => array_values(array_filter($forbidTags, fn($x)=>is_string($x)&&trim($x)!=='')),
    'min_match'   => 0,
];

// explain 需要的容器
$reSelectedExplain = [];
$reRejectedSamples = [];

    if ($debugReads) {
    $debug['rules'] = [
        'max_items' => $maxItems,
        'min_items' => $minItems,
        'sort' => $sortMode,
        'fill_order' => $fillOrder,
        'bucket_quota' => $bucketQuota,
        'hard_by' => $hardBy,
        'soft_by' => $softBy,
        'forbid_tags' => $forbidTags,
        'require_any_tags' => $requireAnyTags,
        'require_all_tags' => $requireAllTags,
    ];
}

    // ----------------------------
    // 2) items buckets
    // ----------------------------
    $byType     = is_array($items['by_type'] ?? null) ? $items['by_type'] : [];
    $byRole     = is_array($items['by_role'] ?? null) ? $items['by_role'] : [];
    $byStrategy = is_array($items['by_strategy'] ?? null) ? $items['by_strategy'] : [];
    $byTopAxis  = is_array($items['by_top_axis'] ?? null) ? $items['by_top_axis'] : [];
    $fallback   = is_array($items['fallback'] ?? null) ? $items['fallback'] : [];

    $roleCode     = $this->roleCodeFromType($typeCode);     // NT/NF/SJ/SP
    $strategyCode = $this->strategyCodeFromType($typeCode); // EA/ET/IA/IT

    // ----------------------------
    // 3) top axis (best delta)
    // ----------------------------
    $dims = ['EI','SN','TF','JP','AT'];
    $best = ['dim' => 'EI', 'delta' => -1, 'side' => 'E'];

    foreach ($dims as $dim) {
        $pct   = (int) ($scoresPct[$dim] ?? 50);
        $delta = abs($pct - 50) * 2;

        [$p1, $p2] = $this->getDimensionPoles($dim);
        $side = ($pct >= 50) ? $p1 : $p2;

        if ($delta > $best['delta']) {
            $best = ['dim' => $dim, 'delta' => $delta, 'side' => $side];
        }
    }

    $plainAxisKey  = $best['dim'] . ':' . $best['side'];        // "EI:E"
    $prefAxisKey   = 'axis:' . $plainAxisKey;                   // "axis:EI:E"
    $axisKeyFormat = (string) ($rules['axis_key_format'] ?? ''); // e.g. "axis:${DIM}:${SIDE}"

    $formattedAxisKey = '';
    if ($axisKeyFormat !== '') {
        $formattedAxisKey = str_replace(
            ['${DIM}', '${SIDE}'],
            [$best['dim'], $best['side']],
            $axisKeyFormat
        );
    }

    if ($debugReads) {
        $debug['axis'] = [
            'best' => $best,
            'plain' => $plainAxisKey,
            'pref' => $prefAxisKey,
            'format' => $axisKeyFormat,
            'formatted' => $formattedAxisKey,
        ];
    }

    // ----------------------------
    // 4) build candidate lists per bucket
    // ----------------------------
    $bucketLists = [
        'by_type'     => (is_array($byType[$typeCode] ?? null) ? $byType[$typeCode] : []),
        'by_role'     => (is_array($byRole[$roleCode] ?? null) ? $byRole[$roleCode] : []),
        'by_strategy' => (is_array($byStrategy[$strategyCode] ?? null) ? $byStrategy[$strategyCode] : []),
        'by_top_axis' => [],
        'fallback'    => $fallback,
    ];

    // 选择存在的轴桶（优先：axis_key_format -> axis:EI:E -> EI:E）
    if ($formattedAxisKey !== '' && is_array($byTopAxis[$formattedAxisKey] ?? null)) {
        $bucketLists['by_top_axis'] = $byTopAxis[$formattedAxisKey];
    } elseif (is_array($byTopAxis[$prefAxisKey] ?? null)) {
        $bucketLists['by_top_axis'] = $byTopAxis[$prefAxisKey];
    } elseif (is_array($byTopAxis[$plainAxisKey] ?? null)) {
        $bucketLists['by_top_axis'] = $byTopAxis[$plainAxisKey];
    } else {
        $bucketLists['by_top_axis'] = [];
    }

    // ============================
// ✅ 用 RuleEngine 统一：过滤 + 打分 + 稳定打散排序
// ============================
$rankBucket = function (string $bucketName, array $list) use (
    $re, $userTagsSet, $globalRules, $seed, $ctx, $debugRE,
    &$reRejectedSamples
): array {
    if (!is_array($list) || empty($list)) return [];

    $ranked = [];

    foreach ($list as $it) {
        if (!is_array($it)) continue;

        $id = (string)($it['id'] ?? '');
        if ($id === '') continue;

        $tags = is_array($it['tags'] ?? null) ? $it['tags'] : [];
        $tags = array_values(array_filter($tags, fn($x)=>is_string($x)&&trim($x)!==''));

        $item = [
            'id'       => $id,
            'priority' => (int)($it['priority'] ?? 0),
            'tags'     => $tags,
            'rules'    => is_array($it['rules'] ?? null) ? $it['rules'] : [], // 允许 items 自带 rules（没有就空）
            '_raw'     => $it, // 保留原对象
        ];

        // ✅ 让 RuleEngine 评估（要求你的 RuleEngine 已提供 evaluate() / 或等价公开方法）
        $ev = $re->evaluate($item, $userTagsSet, [
            'ctx'          => $ctx,
            'seed'         => $seed,
            'bucket'       => $bucketName,
            'global_rules' => $globalRules,
            'debug'        => $debugRE,
        ]);

        if (!($ev['ok'] ?? false)) {
            if ($debugRE && count($reRejectedSamples) < 8) {
                $reRejectedSamples[] = [
                    'id'       => $id,
                    'reason'   => $ev['reason'] ?? 'rejected',
                    'detail'   => $ev['detail'] ?? null,
                    'hit'      => (int)($ev['hit'] ?? 0),
                    'priority' => (int)($ev['priority'] ?? $item['priority']),
                    'min_match'=> (int)($ev['min_match'] ?? 0),
                    'score'    => (int)($ev['score'] ?? 0),
                ];
            }
            continue;
        }

        // 把 RE 结果挂回原 item，供后续 quota/dedupe 选择时使用
        $raw = $item['_raw'];
        $raw['_re'] = [
            'hit'       => (int)($ev['hit'] ?? 0),
            'priority'  => (int)($ev['priority'] ?? $item['priority']),
            'min_match' => (int)($ev['min_match'] ?? 0),
            'score'     => (int)($ev['score'] ?? 0),
            'shuffle'   => (int)($ev['shuffle'] ?? 0),
        ];
        $ranked[] = $raw;
    }

    usort($ranked, function ($a, $b) {
        $sa = (int)(($a['_re']['score'] ?? 0));
        $sb = (int)(($b['_re']['score'] ?? 0));
        if ($sa !== $sb) return $sb <=> $sa;

        $sha = (int)(($a['_re']['shuffle'] ?? 0));
        $shb = (int)(($b['_re']['shuffle'] ?? 0));
        if ($sha !== $shb) return $sha <=> $shb;

        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });

    return $ranked;
};

foreach ($bucketLists as $k => &$list) {
    $list = $rankBucket((string)$k, is_array($list) ? $list : []);
}
unset($list);

    // ----------------------------
    // 5) dedupe state + helpers
    // ----------------------------
    $seenId   = [];
    $seenCid  = [];
    $seenCUrl = []; // key = normalized canonical_url
    $seenUrl  = []; // key = normalized url

    $getNonEmptyString = function ($v): string {
        if ($v === null) return '';
        if (is_string($v)) return trim($v);
        if (is_numeric($v)) return (string) $v;
        return '';
    };

    // URL 归一化（用于 soft dedupe）：
    // - host(可选)+path
    // - query 只保留白名单业务参数（默认仅保留 id）
    $normalizeUrlKey = function (?string $url) use ($getNonEmptyString): string {
        $url = $getNonEmptyString($url);
        if ($url === '') return '';

        $parts = parse_url($url);
        if (!is_array($parts)) return $url;

        $host  = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $path  = isset($parts['path']) ? (string) $parts['path'] : '';
        $query = isset($parts['query']) ? (string) $parts['query'] : '';

        if ($path === '') return $url;

        // 去掉尾部 /
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // 只保留业务参数（默认 id）
        $keep = ['id'];
        $qs = [];
        if ($query !== '') {
            parse_str($query, $qs);
        }

        $filtered = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $qs) && $qs[$k] !== '' && $qs[$k] !== null) {
                $filtered[$k] = $qs[$k];
            }
        }

        ksort($filtered);
        $q = http_build_query($filtered);
        $key = $q !== '' ? ($path . '?' . $q) : $path;

        return $host !== '' ? ($host . $key) : $key;
    };

    // 返回 dup 信息：null=不重复；否则 ['by'=>..., 'key'=>...]
    $dupCheck = function (array $it) use (
        &$seenId, &$seenCid, &$seenCUrl, &$seenUrl,
        $hardBy, $softBy, $getNonEmptyString, $normalizeUrlKey
    ): ?array {
        // hard: id
        if (in_array('id', $hardBy, true)) {
            $id = $getNonEmptyString($it['id'] ?? '');
            if ($id !== '' && isset($seenId[$id])) {
                return ['by' => 'id', 'key' => $id];
            }
        }

        // soft
        foreach ($softBy as $k) {
            $v = $getNonEmptyString($it[$k] ?? '');
            if ($v === '') continue;

            if ($k === 'canonical_id') {
                if (isset($seenCid[$v])) return ['by' => 'canonical_id', 'key' => $v];
                continue;
            }

            if ($k === 'canonical_url') {
                $key = $normalizeUrlKey($v);
                if ($key !== '' && isset($seenCUrl[$key])) return ['by' => 'canonical_urlKey', 'key' => $key];
                continue;
            }

            if ($k === 'url') {
                $key = $normalizeUrlKey($v);
                if ($key !== '' && isset($seenUrl[$key])) return ['by' => 'urlKey', 'key' => $key];
                continue;
            }
        }

        return null;
    };

    $markSeen = function (array $it) use (
        &$seenId, &$seenCid, &$seenCUrl, &$seenUrl,
        $getNonEmptyString, $normalizeUrlKey
    ): void {
        $id = $getNonEmptyString($it['id'] ?? '');
        if ($id !== '') $seenId[$id] = true;

        $cid = $getNonEmptyString($it['canonical_id'] ?? '');
        if ($cid !== '') $seenCid[$cid] = true;

        $curlKey = $normalizeUrlKey($getNonEmptyString($it['canonical_url'] ?? ''));
        if ($curlKey !== '') $seenCUrl[$curlKey] = true;

        $urlKey = $normalizeUrlKey($getNonEmptyString($it['url'] ?? ''));
        if ($urlKey !== '') $seenUrl[$urlKey] = true;
    };

    // ----------------------------
    // 6) normalize output
    // ----------------------------
    $normalize = function (array $it) use ($defaults, $getNonEmptyString): array {
        $it = array_merge($defaults, $it);

        $id  = $getNonEmptyString($it['id'] ?? '');
        $cid = $getNonEmptyString($it['canonical_id'] ?? '');
        $cuz = $getNonEmptyString($it['canonical_url'] ?? '');

        $out = [
            'id'            => $id,
            'canonical_id'  => ($cid === '' ? null : $cid),
            'canonical_url' => ($cuz === '' ? null : $cuz),

            'type'     => (string) ($it['type'] ?? 'article'),
            'title'    => (string) ($it['title'] ?? ''),
            'desc'     => (string) ($it['desc'] ?? ''),
            'url'      => (string) ($it['url'] ?? ''),
            'cover'    => (string) ($it['cover'] ?? ''),
            'priority' => (int) ($it['priority'] ?? 0),
            'tags'     => is_array($it['tags'] ?? null) ? $it['tags'] : [],
        ];

        if (array_key_exists('cta', $it)) $out['cta'] = (string) $it['cta'];
        if (array_key_exists('estimated_minutes', $it)) $out['estimated_minutes'] = (int) $it['estimated_minutes'];
        if (array_key_exists('locale', $it)) $out['locale'] = (string) $it['locale'];
        if (array_key_exists('access', $it)) $out['access'] = (string) $it['access'];
        if (array_key_exists('channel', $it)) $out['channel'] = (string) $it['channel'];
        if (array_key_exists('status', $it)) $out['status'] = (string) $it['status'];
        if (array_key_exists('published_at', $it)) $out['published_at'] = (string) $it['published_at'];
        if (array_key_exists('updated_at', $it)) $out['updated_at'] = (string) $it['updated_at'];

        return $out;
    };

    // ----------------------------
    // 7) quota helper
    // ----------------------------
    $resolveCap = function ($capRaw, int $remaining): int {
        if (is_string($capRaw)) {
            $s = strtolower(trim($capRaw));
            if ($s === 'remaining' || $s === '*' || $s === 'all') return $remaining;
            if (is_numeric($capRaw)) return (int) $capRaw;
            return 0;
        }
        if (is_int($capRaw) || is_float($capRaw)) return (int) $capRaw;
        return $remaining;
    };

    // ----------------------------
    // 8) fill by bucket (quota + debug dup reasons)
    // ----------------------------
    $out = [];
    $SAMPLE_LIMIT = 5;

    foreach ($fillOrder as $bucketName) {
        if (count($out) >= $maxItems) break;

        $list = $bucketLists[$bucketName] ?? [];
        if (!is_array($list) || empty($list)) {
            if ($debugReads) {
                $debug['buckets'][$bucketName] = [
                    'candidates' => is_array($list) ? count($list) : 0,
                    'cap' => 0,
                    'taken' => 0,
                    'skip_no_id' => 0,
                    'skip_dup' => 0,
                    'skip_dup_by' => [],
                    'dup_samples' => [],
                    'skip_invalid' => 0,
                    'stop_cap' => false,
                    'skip_empty' => true,
                ];
            }
            continue;
        }

        $remaining = $maxItems - count($out);
        $capRaw = $bucketQuota[$bucketName] ?? $remaining;
        $cap    = $resolveCap($capRaw, $remaining);
        if ($cap <= 0) {
            if ($debugReads) {
                $debug['buckets'][$bucketName] = [
                    'candidates' => count($list),
                    'cap' => $cap,
                    'taken' => 0,
                    'skip_no_id' => 0,
                    'skip_dup' => 0,
                    'skip_dup_by' => [],
                    'dup_samples' => [],
                    'skip_invalid' => 0,
                    'stop_cap' => false,
                    'skip_empty' => false,
                    'skip_reason' => 'cap<=0',
                ];
            }
            continue;
        }
        $cap = min($cap, $remaining);

        if ($debugReads) {
            $debug['buckets'][$bucketName] = [
                'candidates' => count($list),
                'cap' => $cap,
                'taken' => 0,
                'skip_no_id' => 0,
                'skip_dup' => 0,
                'skip_dup_by' => [],
                'dup_samples' => [],
                'skip_invalid' => 0,
                'stop_cap' => false,
                'skip_empty' => false,
            ];
        }

        $taken = 0;
        foreach ($list as $it) {
            if (count($out) >= $maxItems) break;

            if ($taken >= $cap) {
                if ($debugReads) $debug['buckets'][$bucketName]['stop_cap'] = true;
                break;
            }

            if (!is_array($it)) {
                if ($debugReads) $debug['buckets'][$bucketName]['skip_invalid']++;
                continue;
            }

            $id = $getNonEmptyString($it['id'] ?? '');
            if ($id === '') {
                if ($debugReads) $debug['buckets'][$bucketName]['skip_no_id']++;
                continue;
            }

            $dup = $dupCheck($it);
            if ($dup !== null) {
                if ($debugReads) {
                    $debug['buckets'][$bucketName]['skip_dup']++;

                    $by = (string) ($dup['by'] ?? 'unknown');
                    $debug['buckets'][$bucketName]['skip_dup_by'][$by] =
                        (int) ($debug['buckets'][$bucketName]['skip_dup_by'][$by] ?? 0) + 1;

                    if (count($debug['buckets'][$bucketName]['dup_samples']) < $SAMPLE_LIMIT) {
                        $debug['buckets'][$bucketName]['dup_samples'][] = [
                            'id' => $id,
                            'dup_by' => $by,
                            'dup_key' => (string) ($dup['key'] ?? ''),
                        ];
                    }
                }
                continue;
            }

            $markSeen($it);

// ✅ 记录 explain（最终只对“真正被选中”的条目输出）
if (is_array($it['_re'] ?? null)) {
    $reSelectedExplain[] = [
        'id'        => (string)($it['id'] ?? ''),
        'hit'       => (int)($it['_re']['hit'] ?? 0),
        'priority'  => (int)($it['_re']['priority'] ?? 0),
        'min_match' => (int)($it['_re']['min_match'] ?? 0),
        'score'     => (int)($it['_re']['score'] ?? 0),
    ];
}

$out[] = $normalize($it);
$taken++;

            if ($debugReads) $debug['buckets'][$bucketName]['taken']++;
        }
    }

    // min_items：强制用 fallback 补齐
    if ($minItems > 0 && count($out) < min($minItems, $maxItems)) {
        $need = min($minItems, $maxItems) - count($out);

        if ($debugReads) {
            $debug['min_items_fill'] = [
                'need' => $need,
                'taken' => 0,
                'skip_no_id' => 0,
                'skip_dup' => 0,
                'skip_dup_by' => [],
                'dup_samples' => [],
                'skip_invalid' => 0,
            ];
        }

        if ($need > 0 && is_array($bucketLists['fallback'] ?? null)) {
            foreach ($bucketLists['fallback'] as $it) {
                if (count($out) >= $maxItems) break;
                if ($need <= 0) break;

                if (!is_array($it)) {
                    if ($debugReads) $debug['min_items_fill']['skip_invalid']++;
                    continue;
                }

                $id = $getNonEmptyString($it['id'] ?? '');
                if ($id === '') {
                    if ($debugReads) $debug['min_items_fill']['skip_no_id']++;
                    continue;
                }

                $dup = $dupCheck($it);
                if ($dup !== null) {
                    if ($debugReads) {
                        $debug['min_items_fill']['skip_dup']++;

                        $by = (string) ($dup['by'] ?? 'unknown');
                        $debug['min_items_fill']['skip_dup_by'][$by] =
                            (int) ($debug['min_items_fill']['skip_dup_by'][$by] ?? 0) + 1;

                        if (count($debug['min_items_fill']['dup_samples']) < $SAMPLE_LIMIT) {
                            $debug['min_items_fill']['dup_samples'][] = [
                                'id' => $id,
                                'dup_by' => $by,
                                'dup_key' => (string) ($dup['key'] ?? ''),
                            ];
                        }
                    }
                    continue;
                }

                $markSeen($it);

// ✅ 记录 explain（最终只对“真正被选中”的条目输出）
if (is_array($it['_re'] ?? null)) {
    $reSelectedExplain[] = [
        'id'        => (string)($it['id'] ?? ''),
        'hit'       => (int)($it['_re']['hit'] ?? 0),
        'priority'  => (int)($it['_re']['priority'] ?? 0),
        'min_match' => (int)($it['_re']['min_match'] ?? 0),
        'score'     => (int)($it['_re']['score'] ?? 0),
    ];
}

$out[] = $normalize($it);
$taken++;

                if ($debugReads) $debug['min_items_fill']['taken']++;
            }
        }
    }

    // 可选：最终排序（严格遵循 rules.sort=priority_desc）
    if ($sortMode === 'priority_desc') {
        usort($out, fn($a, $b) => (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0));
    }

    if ($debugReads) {
        $debug['result'] = [
            'count' => count($out),
            'ids' => array_values(array_map(fn($x) => $x['id'] ?? null, $out)),
        ];
        Log::debug('[recommended_reads] build', $debug);
    }

// ✅ 统一 [RE] explain：reads
$re->explain($ctx, $reSelectedExplain, $reRejectedSamples, [
    'debug' => $debugRE,
    'seed'  => $seed,
]);

    return $out;
}

private function pctTowardFirst(int $first, int $second): int
{
    $t = $first + $second;
    if ($t <= 0) return 50;
    return (int) round(($first / $t) * 100);
}

private function buildIdentityLayer(array $profile, array $scoresPct, array $axisStates, string $typeCode): array
{
    // 选出最强/次强轴（delta 最大）
    $dims = ['EI','SN','TF','JP','AT'];
    $rank = [];
    foreach ($dims as $d) {
        $raw = (int)($scoresPct[$d] ?? 50);
        $delta = abs($raw - 50);
        $rank[] = ['dim'=>$d,'delta'=>$delta,'raw'=>$raw];
    }
    usort($rank, fn($a,$b)=> $b['delta'] <=> $a['delta']);
    $top1 = $rank[0]['dim'];
    $top2 = $rank[1]['dim'];

    $tagline = (string)($profile['tagline'] ?? $typeCode);
    $keywords = (array)($profile['keywords'] ?? []);
    $tags = array_values(array_slice($keywords, 0, 3));

    // 用最强轴拼一句更“像人”的定位
    $axisPhrase = match ($top1) {
        'EI' => '社交与表达更主动',
        'SN' => '更偏直觉/趋势推演',
        'TF' => '决策更偏理性与原则',
        'JP' => '更偏计划与掌控节奏',
        'AT' => '心态更稳、更敢拍板',
        default => '更有明确偏好',
    };

    $subtitle = $tagline . ' · ' . $axisPhrase;

    $desc = "你在「{$typeCode}」的表现更像一个{$tagline}：{$axisPhrase}。"
          . "你更愿意把目标说清、把规则定好，然后推动事情发生。"
          . "在压力下，你可能会更快进入“推进/收束”的模式；给自己留一点复盘与缓冲，会让输出更稳。";

    return [
        'id' => "identity_{$typeCode}",
        'type_code' => $typeCode,
        'title' => $profile['type_name'] ?? $typeCode,
        'subtitle' => $subtitle,
        'tags' => $tags ?: ['目标感','执行','推进'],
        'desc' => $desc,
        'meta' => [
            'top_dims' => [$top1, $top2],
        ],
    ];
}

private function buildSectionFallbackCards(string $section, array $profile, array $scores, array $scoresPct, string $typeCode): array
{
    // $scores 是你 report.scores 那个结构（pct>=50 / side / state / delta）
    $cards = [];

    // 找最强轴 + 最弱轴（delta最大/最小）
    $dims = ['EI','SN','TF','JP','AT'];
    $rank = [];
    foreach ($dims as $d) {
        $raw = (int)($scoresPct[$d] ?? 50);
        $delta = abs($raw - 50);
        $rank[] = ['dim'=>$d,'delta'=>$delta,'raw'=>$raw];
    }
    usort($rank, fn($a,$b)=> $b['delta'] <=> $a['delta']);
    $strongDim = $rank[0]['dim'];
    $weakDim   = $rank[count($rank)-1]['dim'];

    $typeName = (string)($profile['type_name'] ?? $typeCode);
    $tagline  = (string)($profile['tagline'] ?? '');

    // 通用：强项卡
    $cards[] = [
        'id' => "{$section}_strength_{$strongDim}_01",
        'title' => "你的强项更集中在 {$strongDim}",
        'desc'  => "从分数来看，你在 {$strongDim} 轴上偏好更明显，这会直接影响你在「{$section}」里的行为选择。",
        'bullets' => [
            "当你做决策时更容易沿着“{$scores[$strongDim]['side']}”这条路径推进",
            "强项用得好会变成效率；用过头可能变成惯性",
        ],
        'tags' => ["axis:$strongDim", "state:".$scores[$strongDim]['state']],
        'priority' => 120,
    ];

    // 通用：提醒/盲点卡（用最弱轴）
    $cards[] = [
        'id' => "{$section}_blindspot_{$weakDim}_01",
        'title' => "一个容易被忽略的点：{$weakDim}",
        'desc'  => "你在 {$weakDim} 轴上的偏好相对不强，更多取决于情境。优势是灵活，但也更容易“用着用着就累”。",
        'bullets' => [
            "重要场景先明确：这次更需要 {$weakDim} 的哪一端？",
            "用清单/模板把临场消耗降下来",
        ],
        'tags' => ["axis:$weakDim", "state:".$scores[$weakDim]['state']],
        'priority' => 110,
    ];

    // section-specific：再补一张“更像该区块”的卡（保证 ≥2 且更像人）
    if ($section === 'career') {
        $cards[] = [
            'id' => "career_style_01",
            'title' => "你更适合的工作方式",
            'desc'  => "{$typeName}（{$tagline}）通常在“目标清晰、边界明确、能推进交付”的环境里发挥更好。",
            'bullets' => ["给你明确目标与权限", "配合可复用流程/模板", "用里程碑驱动协作"],
            'tags' => ["topic:career"],
            'priority' => 100,
        ];
    } elseif ($section === 'relationships') {
        $cards[] = [
            'id' => "relationships_script_01",
            'title' => "更顺的一种沟通句式",
            'desc'  => "把分歧从“对错”拉回“协商”：先对齐目标，再讲事实和请求。",
            'bullets' => ["目标：我们都想……", "事实：现在发生的是……", "请求：我希望我们可以……"],
            'tags' => ["topic:relationships"],
            'priority' => 100,
        ];
    } elseif ($section === 'growth') {
        $cards[] = [
            'id' => "growth_nextstep_01",
            'title' => "一个你立刻能做的下一步",
            'desc'  => "把你最强项变成系统，把最弱项变成工具：这是最快的成长路径。",
            'bullets' => ["强项：沉淀成模板/流程", "弱项：用提醒/仪式降低消耗", "每周一次复盘：保留有效、删掉无效"],
            'tags' => ["topic:growth"],
            'priority' => 100,
        ];
    } else { // traits
        $cards[] = [
            'id' => "traits_core_01",
            'title' => "你的核心气质画像",
            'desc'  => "你更像是“把事情推进到结果”的类型：直面问题、偏行动、要可落地。",
            'bullets' => ["喜欢清晰规则与预期", "对低效/含糊更敏感", "愿意承担并带节奏"],
            'tags' => ["topic:traits"],
            'priority' => 100,
        ];
    }

    // 最终保证至少 2 张
    return array_values($cards);
}

/**
 * ✅ M3-2.4 / 方案B：通用 section cards selector
 * - traits: 固定 4 张（Top3 + guardrail EI/J P）
 * - other: 默认 3 张（按 match + priority/delta）
 */
private function buildSectionCards(
    string $section,
    string $contentPackageVersion,
    string $typeCode,
    array $scoresPct,
    array $axisStates
): array {
    $raw = $this->loadReportAssetJson($contentPackageVersion, "report_cards_{$section}.json");
    $items = is_array($raw['items'] ?? null) ? $raw['items'] : [];
    $rules = is_array($raw['rules'] ?? null) ? $raw['rules'] : [];

    // 默认每个 section 3 张；traits 固定 4 张（方案B）
    $max = (int)($rules['max_cards'] ?? 3);
    if ($section === 'traits') $max = 4;
    if ($max <= 0) $max = ($section === 'traits' ? 4 : 3);

    // 轴信息（delta 用 displayPct：50..100 的那套，delta=0..50）
    $axisInfo = $this->buildAxisInfo($scoresPct, $axisStates);

    // 统一 normalize items
    $cards = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $id = (string)($it['id'] ?? '');
        if ($id === '') continue;

        $cards[] = [
            'id'       => $id,
            'title'    => (string)($it['title'] ?? ''),
            'desc'     => (string)($it['desc'] ?? ''),
            'bullets'  => is_array($it['bullets'] ?? null) ? $it['bullets'] : [],
            'tips'     => is_array($it['tips'] ?? null) ? $it['tips'] : [],
            'tags'     => is_array($it['tags'] ?? null) ? $it['tags'] : [],
            'priority' => (int)($it['priority'] ?? 0),
            'match'    => is_array($it['match'] ?? null) ? $it['match'] : null,
        ];
    }

    // 先做 match 过滤
    $matched = [];
    foreach ($cards as $c) {
        if ($this->cardMatches($c, $typeCode, $axisInfo)) {
            // attach computed axis delta (for sorting convenience)
            $c['_axis_dim'] = $this->cardAxisDim($c);
            $c['_delta']    = $this->cardDelta($c, $axisInfo);
            $matched[] = $c;
        }
    }

    // traits：走方案B（Top3 strict + guardrail + fill）
    if ($section === 'traits') {
        return $this->pickTraitsB($matched, $typeCode, $axisInfo, $max);
    }

    // 其它 section：直接按排序取 max，并不足用 fallback 补齐
    $out = $this->pickTopN($matched, $max, $axisInfo, true);

    if (count($out) < $max) {
        $need = $max - count($out);
        $out = array_merge($out, $this->makeFallbackCards($section, $typeCode, $axisInfo, $need));
    }

    return array_values($out);
}

/**
 * 方案B：Traits 固定 4 张
 * - Top3：严格池（排除 intro 卡：axis.min_delta<=0 或 kind:intro）
 * - Guardrail：EI 优先；若 EI 已在 Top3，则 JP
 *   - guardrail 先找 intro/light 卡；找不到再放宽；再找不到就 fallback
 * - 最终不足补齐到 4
 */
private function pickTraitsB(array $matched, string $typeCode, array $axisInfo, int $max = 4): array
{
    // 1) strict pool：排除 intro/light
    $strict = array_values(array_filter($matched, function ($c) {
        $minDelta = $this->cardAxisMinDelta($c);
        if ($minDelta !== null && $minDelta <= 0) return false;

        $tags = is_array($c['tags'] ?? null) ? $c['tags'] : [];
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            if ($t === 'kind:intro' || $t === 'kind:axis_intro') return false;
        }
        return true;
    }));

    // 2) Top3 strict
    $top = $this->pickTopN($strict, 3, $axisInfo, true);

    // 当前已出现的轴 dim
    $hasDim = [];
    foreach ($top as $c) {
        $d = $this->cardAxisDim($c);
        if ($d) $hasDim[$d] = true;
    }

    // 3) guardrail 轴：EI 优先，否则 JP
    $guardDim = !isset($hasDim['EI']) ? 'EI' : (!isset($hasDim['JP']) ? 'JP' : null);

    if ($guardDim !== null) {
        $guard = $this->pickGuardrailAxisCard($matched, $guardDim, $axisInfo, $hasDim);
        if ($guard !== null) {
            $top[] = $guard;
            $hasDim[$guardDim] = true;
        } else {
            // 真找不到：fallback 一张 guardrail
            $top[] = $this->makeFallbackAxisIntroCard('traits', $typeCode, $axisInfo, $guardDim);
            $hasDim[$guardDim] = true;
        }
    }

    // 4) fill to 4：从 strict 里继续捞（避免重复轴）
    if (count($top) < $max) {
        $need = $max - count($top);
        $more = $this->pickTopN($strict, 50, $axisInfo, true); // 拿更多当候选
        foreach ($more as $c) {
            if ($need <= 0) break;
            // 去重：id + dim
            if ($this->containsId($top, (string)($c['id'] ?? ''))) continue;
            $d = $this->cardAxisDim($c);
            if ($d && isset($hasDim[$d])) continue;

            $top[] = $c;
            if ($d) $hasDim[$d] = true;
            $need--;
        }
    }

    // 5) still short：fallback 补齐到 4
    if (count($top) < $max) {
        $need = $max - count($top);
        $top = array_merge($top, $this->makeFallbackCards('traits', $typeCode, $axisInfo, $need));
    }

    // 最终：稳定排序（按 priority/delta/id）+ 截断 4
    $top = $this->sortCards($top);
    return array_slice(array_values($top), 0, $max);
}

private function pickGuardrailAxisCard(array $matched, string $dim, array $axisInfo, array $hasDim): ?array
{
    $side = (string)($axisInfo[$dim]['side'] ?? '');
    if ($side === '') return null;

    // 候选：该 dim+side 的卡
    $cands = array_values(array_filter($matched, function ($c) use ($dim, $side) {
        $m = is_array($c['match'] ?? null) ? $c['match'] : null;
        $ax = is_array($m['axis'] ?? null) ? $m['axis'] : null;
        if (!$ax) return false;

        if (($ax['dim'] ?? null) !== $dim) return false;
        if (($ax['side'] ?? null) !== $side) return false;

        return true;
    }));

    if (empty($cands)) return null;

    // 先找 intro/light（min_delta<=0 或 tags kind:intro）
    $intro = array_values(array_filter($cands, function ($c) {
        $minDelta = $this->cardAxisMinDelta($c);
        if ($minDelta !== null && $minDelta <= 0) return true;

        $tags = is_array($c['tags'] ?? null) ? $c['tags'] : [];
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            if ($t === 'kind:intro' || $t === 'kind:axis_intro') return true;
        }
        return false;
    }));

    $pickFrom = !empty($intro) ? $intro : $cands;

    $pickFrom = $this->sortCards($pickFrom);

    foreach ($pickFrom as $c) {
        $id = (string)($c['id'] ?? '');
        if ($id === '') continue;

        // 同一轴 dim 只出一张
        $d = $this->cardAxisDim($c);
        if ($d && isset($hasDim[$d])) continue;

        return $c;
    }

    return null;
}

/** 统一 match 判断：axis/type/role/strategy */
private function cardMatches(array $card, string $typeCode, array $axisInfo): bool
{
    $match = is_array($card['match'] ?? null) ? $card['match'] : null;
    if ($match === null) {
        // 没 match：默认可用（当作通用卡）
        return true;
    }

    // type
    if (isset($match['type'])) {
        $t = is_array($match['type']) ? $match['type'] : null;
        if ($t && isset($t['type_code']) && (string)$t['type_code'] !== $typeCode) return false;
    }

    // role
    if (isset($match['role'])) {
        $r = is_array($match['role']) ? $match['role'] : null;
        if ($r && isset($r['code'])) {
            $want = (string)$r['code'];
            $have = $this->roleCodeFromType($typeCode);
            if ($want !== $have) return false;
        }
    }

    // strategy
    if (isset($match['strategy'])) {
        $s = is_array($match['strategy']) ? $match['strategy'] : null;
        if ($s && isset($s['code'])) {
            $want = (string)$s['code'];
            $have = $this->strategyCodeFromType($typeCode);
            if ($want !== $have) return false;
        }
    }

    // axis
    if (isset($match['axis'])) {
        $ax = is_array($match['axis']) ? $match['axis'] : null;
        if (!$ax) return false;

        $dim = (string)($ax['dim'] ?? '');
        $side= (string)($ax['side'] ?? '');
        $minDelta = isset($ax['min_delta']) ? (int)$ax['min_delta'] : 0;

        if ($dim === '' || $side === '') return false;
        if (!isset($axisInfo[$dim])) return false;

        $haveSide  = (string)($axisInfo[$dim]['side'] ?? '');
        $haveDelta = (int)($axisInfo[$dim]['delta'] ?? 0);

        if ($haveSide !== $side) return false;
        if ($haveDelta < $minDelta) return false;
    }

    return true;
}

private function buildAxisInfo(array $scoresPct, array $axisStates): array
{
    $dims = ['EI','SN','TF','JP','AT'];
    $out = [];
    foreach ($dims as $dim) {
        $rawPct = (int)($scoresPct[$dim] ?? 50);
        [$p1,$p2] = $this->getDimensionPoles($dim);

        $side = ($rawPct >= 50) ? $p1 : $p2;
        $displayPct = ($rawPct >= 50) ? $rawPct : (100 - $rawPct); // 50..100
        $delta = abs($displayPct - 50); // 0..50
        $level = (string)($axisStates[$dim] ?? 'moderate');

        $out[$dim] = [
            'dim' => $dim,
            'raw_pct' => $rawPct,
            'pct' => $displayPct,
            'delta' => $delta,
            'side' => $side,
            'level' => $level,
        ];
    }
    return $out;
}

private function pickTopN(array $cards, int $n, array $axisInfo, bool $dedupeAxisDim = true): array
{
    $cards = $this->sortCards($cards);

    $out = [];
    $seenId = [];
    $seenDim = [];

    foreach ($cards as $c) {
        if (count($out) >= $n) break;

        $id = (string)($c['id'] ?? '');
        if ($id === '' || isset($seenId[$id])) continue;

        $dim = $this->cardAxisDim($c);

        if ($dedupeAxisDim && $dim && isset($seenDim[$dim])) {
            continue;
        }

        $seenId[$id] = true;
        if ($dim) $seenDim[$dim] = true;

        $out[] = $c;
    }

    return $out;
}

private function sortCards(array $cards): array
{
    $cards = array_values(array_filter($cards, fn($x) => is_array($x)));

    usort($cards, function ($a, $b) {
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;

        $da = (int)($a['_delta'] ?? 0);
        $db = (int)($b['_delta'] ?? 0);
        if ($da !== $db) return $db <=> $da;

        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });

    return $cards;
}

private function cardAxisDim(array $card): ?string
{
    $m = is_array($card['match'] ?? null) ? $card['match'] : null;
    $ax = is_array($m['axis'] ?? null) ? $m['axis'] : null;
    if ($ax && isset($ax['dim'])) {
        $dim = (string)$ax['dim'];
        return $dim !== '' ? $dim : null;
    }
    return null;
}

private function cardAxisMinDelta(array $card): ?int
{
    $m = is_array($card['match'] ?? null) ? $card['match'] : null;
    $ax = is_array($m['axis'] ?? null) ? $m['axis'] : null;
    if ($ax && array_key_exists('min_delta', $ax)) {
        return (int)$ax['min_delta'];
    }
    return null;
}

private function cardDelta(array $card, array $axisInfo): int
{
    $dim = $this->cardAxisDim($card);
    if (!$dim) return 0;
    return (int)($axisInfo[$dim]['delta'] ?? 0);
}

private function containsId(array $cards, string $id): bool
{
    foreach ($cards as $c) {
        if ((string)($c['id'] ?? '') === $id) return true;
    }
    return false;
}

/**
 * fallback：补齐用（生成“解释型卡”，不依赖内容库）
 */
private function makeFallbackCards(string $section, string $typeCode, array $axisInfo, int $need): array
{
    $out = [];
    if ($need <= 0) return $out;

    // 以 delta 从大到小挑轴，生成解释卡
    $dims = array_values($axisInfo);
    usort($dims, fn($a,$b) => (int)($b['delta'] ?? 0) <=> (int)($a['delta'] ?? 0));

    $i = 0;
    while (count($out) < $need && $i < count($dims)) {
        $d = $dims[$i];
        $out[] = $this->makeFallbackAxisIntroCard($section, $typeCode, $axisInfo, (string)$d['dim']);
        $i++;
    }

    // 还不够就重复用 EI/JP
    while (count($out) < $need) {
        $out[] = $this->makeFallbackAxisIntroCard($section, $typeCode, $axisInfo, 'EI');
    }

    return $out;
}

/**
 * 生成某个轴的“轻量解释卡”（适合 guardrail / fallback）
 */
private function makeFallbackAxisIntroCard(string $section, string $typeCode, array $axisInfo, string $dim): array
{
    $a = $axisInfo[$dim] ?? ['side'=>'','pct'=>50,'delta'=>0,'level'=>'moderate'];
    $side = (string)($a['side'] ?? '');
    $pct  = (int)($a['pct'] ?? 50);
    $delta= (int)($a['delta'] ?? 0);

    $dimName = [
        'EI' => '能量来源',
        'SN' => '信息偏好',
        'TF' => '决策方式',
        'JP' => '行事节奏',
        'AT' => '压力姿态',
    ][$dim] ?? $dim;

    $desc = "你在「{$dimName}」更偏 {$side}（强度 {$pct}%）。当这条轴接近 50 时，更说明你会随情境切换；当偏好更强时，你会更稳定地沿着这条路径做选择。";

    return [
        'id' => "{$section}_fallback_{$dim}_{$side}_intro",
        'title' => "解释一下：{$dimName} 偏 {$side}",
        'desc' => $desc,
        'bullets' => [
            "这是一个“偏好”，不是能力高低",
            "越靠近 50，越容易受场景影响（更灵活也更耗能）",
        ],
        'tips' => [
            "关键场景先写下第一反应，再补一个反向选项",
            "用清单/模板减少临场消耗",
        ],
        'tags' => ["kind:axis_intro", "axis:{$dim}:{$side}", "fallback:true"],
        'priority' => 1,
        'match' => [
            'axis' => [
                'dim' => $dim,
                'side' => $side,
                'min_delta' => 0,
            ]
        ],
        '_delta' => $delta,
        '_axis_dim' => $dim,
    ];
}

/**
 * M3-2.5：从内容包 report_cards_{section}.json 里选卡
 * - 支持 match.axis: {dim, side, min_delta}
 * - 支持 kind:core 兜底补齐
 * - 默认 target_cards=3（可由 rules 覆盖）
 * - 若 assets 不存在/命中为空：自动 fallback 到 buildSectionFallbackCards()
 */
private function buildSectionCardsFromAssets(
    string $section,
    string $contentPackageVersion,
    string $typeCode,
    array $scores,      // report.scores（每轴：pct/state/side/delta）
    array $profile,     // type_profiles 里那条
    array $scoresPct    // 兼容 fallback 用
): array {
    $file = "report_cards_{$section}.json";
    $json = $this->loadReportAssetJson($contentPackageVersion, $file);

    $items = is_array($json['items'] ?? null) ? $json['items'] : [];
    $rules = is_array($json['rules'] ?? null) ? $json['rules'] : [];

    // 没内容：直接 fallback
    if (!is_array($items) || empty($items)) {
        return $this->buildSectionFallbackCards($section, $profile, $scores, $scoresPct, $typeCode);
    }

    // ✅ 方案B：traits 固定 4 张（Top3 strongest axes + 保底 EI 否则 JP）
if ($section === 'traits') {
    return $this->buildTraitsCardsFixed4FromAssets(
        $items,
        $scores,      // report.scores: side/delta 已经算好了
        $profile,
        $scoresPct,
        $typeCode
    );
}

    $minCards    = (int)($rules['min_cards'] ?? 2);
    $targetCards = (int)($rules['target_cards'] ?? 3);
    $maxCards    = (int)($rules['max_cards'] ?? 6);
    if ($targetCards < 1) $targetCards = 3;
    if ($minCards < 1) $minCards = 2;
    if ($maxCards < $targetCards) $maxCards = max($targetCards, 6);

    $fallbackTags = $rules['fallback_tags'] ?? ['kind:core'];
    if (!is_array($fallbackTags)) $fallbackTags = ['kind:core'];

    // 过滤：只拿本 section（容错）
    $items = array_values(array_filter($items, function ($c) use ($section) {
        return is_array($c) && (($c['section'] ?? $section) === $section);
    }));

    // 标注命中 + 用于排序的指标（priority / matched_delta）
    $scored = [];
    foreach ($items as $c) {
        $m = $this->cardMatchesScores($c, $scores);

        $prio = (int)($c['priority'] ?? 0);
        $scored[] = [
            'card'          => $c,
            'matched'       => (bool)($m['matched'] ?? false),
            'matched_dim'   => (string)($m['dim'] ?? ''),
            'matched_delta' => (int)($m['delta'] ?? 0),
            'priority'      => $prio,
        ];
    }

    // 排序：matched 优先 -> priority -> matched_delta -> id
    usort($scored, function ($a, $b) {
        if (($a['matched'] ?? false) !== ($b['matched'] ?? false)) {
            return ($b['matched'] ?? false) <=> ($a['matched'] ?? false);
        }
        $pa = (int)($a['priority'] ?? 0);
        $pb = (int)($b['priority'] ?? 0);
        if ($pa !== $pb) return $pb <=> $pa;

        $da = (int)($a['matched_delta'] ?? 0);
        $db = (int)($b['matched_delta'] ?? 0);
        if ($da !== $db) return $db <=> $da;

        return strcmp((string)($a['card']['id'] ?? ''), (string)($b['card']['id'] ?? ''));
    });

    $out = [];
    $seen = [];

    $push = function (array $card) use (&$out, &$seen, $section) {
        $id = (string)($card['id'] ?? '');
        if ($id === '' || isset($seen[$id])) return;
        $seen[$id] = true;

        // ✅ 统一输出结构（前端契约稳定）
        $out[] = [
            'id'       => $id,
            'section'  => (string)($card['section'] ?? $section),
            'title'    => (string)($card['title'] ?? ''),
            'desc'     => (string)($card['desc'] ?? ''),
            'bullets'  => is_array($card['bullets'] ?? null) ? array_values($card['bullets']) : [],
            'tips'     => is_array($card['tips'] ?? null) ? array_values($card['tips']) : [],
            'tags'     => is_array($card['tags'] ?? null) ? array_values($card['tags']) : [],
            'priority' => (int)($card['priority'] ?? 0),

            // ✅ debug/可视化：把内容库里的 match 带出来
            'match'    => $card['match'] ?? null,
        ];
    };

    // 1) 先拿命中的
    foreach ($scored as $row) {
        if (count($out) >= $targetCards) break;
        if (!($row['matched'] ?? false)) continue;
        $push($row['card']);
    }

    // 2) 不足用 fallback_tags 补齐（如 kind:core）
    if (count($out) < $targetCards) {
        foreach ($scored as $row) {
            if (count($out) >= $targetCards) break;
            $card = $row['card'];
            $tags = is_array($card['tags'] ?? null) ? $card['tags'] : [];

            $ok = false;
            foreach ($fallbackTags as $ft) {
                if (in_array($ft, $tags, true)) { $ok = true; break; }
            }
            if (!$ok) continue;

            $push($card);
        }
    }

    // 3) 仍不足：用剩余的补齐
    if (count($out) < $targetCards) {
        foreach ($scored as $row) {
            if (count($out) >= $targetCards) break;
            $push($row['card']);
        }
    }

    // 还不足（极端情况）就 fallback
    if (count($out) < max(1, $minCards)) {
        return $this->buildSectionFallbackCards($section, $profile, $scores, $scoresPct, $typeCode);
    }

    $limit = max($minCards, $targetCards);
    $limit = min($limit, $maxCards);

    return array_slice($out, 0, $limit);
}

/**
 * 判断卡片是否命中 scores（支持 match.axis）
 * match.axis: { dim: "EI", side: "E", min_delta: 15 }
 * scores[dim] 结构：['pct'=>..,'state'=>..,'side'=>..,'delta'=>..]
 */
private function cardMatchesScores(array $card, array $scores): array
{
    $match = $card['match'] ?? null;
    if (!is_array($match)) {
        return ['matched' => false, 'dim' => '', 'delta' => 0];
    }

    $axis = $match['axis'] ?? null;
    if (!is_array($axis)) {
        return ['matched' => false, 'dim' => '', 'delta' => 0];
    }

    $dim = (string)($axis['dim'] ?? '');
    $side = (string)($axis['side'] ?? '');
    $minDelta = (int)($axis['min_delta'] ?? 0); // 0..50

    if ($dim === '' || $side === '') {
        return ['matched' => false, 'dim' => '', 'delta' => 0];
    }

    $s = $scores[$dim] ?? null;
    if (!is_array($s)) {
        return ['matched' => false, 'dim' => $dim, 'delta' => 0];
    }

    $scoreSide = (string)($s['side'] ?? '');
    $delta = (int)($s['delta'] ?? 0);

    if ($scoreSide !== $side) {
        return ['matched' => false, 'dim' => $dim, 'delta' => $delta];
    }

    if ($delta < $minDelta) {
        return ['matched' => false, 'dim' => $dim, 'delta' => $delta];
    }

    return ['matched' => true, 'dim' => $dim, 'delta' => $delta];
}

private function sortHighlightsForUX(array $items): array
{
    $items = array_values(array_filter($items, fn($x) => is_array($x)));

    usort($items, function ($a, $b) {
        $pa = $this->highlightKindPriority($a);
        $pb = $this->highlightKindPriority($b);

        if ($pa !== $pb) {
            return $pa <=> $pb; // 小的更靠前
        }

        // 同 kind：优先 delta 大的
        $da = (int)($a['delta'] ?? 0);
        $db = (int)($b['delta'] ?? 0);
        if ($da !== $db) {
            return $db <=> $da;
        }

        // 再兜底：id 排序稳定
        return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });

    return $items;
}

private function highlightKindPriority(array $h): int
{
    $kind = null;
    $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];
    foreach ($tags as $t) {
        if (is_string($t) && str_starts_with($t, 'kind:')) {
            $kind = substr($t, 5);
            break;
        }
    }

    return match ($kind) {
        'strength' => 0,
        'risk'     => 1,
        'action'   => 2,
        'axis'     => 3,
        default    => 9,
    };
}

/**
 * 方案B：Traits 固定 4 张
 * - 先按 scores.delta 从大到小找 strongest axes
 * - 每个 axis 只取 1 张（dedupe dim）
 * - Top3：优先拿“非 intro 卡”（min_delta>0 且 tags 不含 kind:intro/axis_intro）
 * - Guardrail：EI 优先，否则 JP（优先拿 intro 卡；找不到就用 fallback axis intro）
 */
private function buildTraitsCardsFixed4FromAssets(
    array $items,
    array $scores,
    array $profile,
    array $scoresPct,
    string $typeCode
): array {
    $dims = ['EI','SN','TF','JP','AT'];

    // 1) 预处理：把 items 统一成可输出的 card（沿用 buildSectionCardsFromAssets 里的 push 结构）
    $cards = [];
    foreach ($items as $c) {
    if (!is_array($c)) continue;

    // ✅ 强制只吃 traits（防 assets 混入其它 section 卡）
    $sec = (string)($c['section'] ?? 'traits');
    if ($sec !== 'traits') continue;

    $id = (string)($c['id'] ?? '');
    if ($id === '') continue;

    $cards[] = $c;
}

    // 2) 轴强度排序（delta desc）
    $axisRank = [];
    foreach ($dims as $dim) {
        $axisRank[] = [
            'dim'   => $dim,
            'delta' => (int)($scores[$dim]['delta'] ?? 0),
            'side'  => (string)($scores[$dim]['side'] ?? ''),
        ];
    }
    usort($axisRank, fn($a,$b) => ($b['delta'] ?? 0) <=> ($a['delta'] ?? 0));

    // 小工具：判断是否 intro/light 卡
    $isIntro = function(array $card): bool {
        $match = is_array($card['match'] ?? null) ? $card['match'] : null;
        $ax    = is_array($match['axis'] ?? null) ? $match['axis'] : null;
        $minDelta = is_array($ax) && array_key_exists('min_delta', $ax) ? (int)$ax['min_delta'] : null;

        if ($minDelta !== null && $minDelta <= 0) return true;

        $tags = is_array($card['tags'] ?? null) ? $card['tags'] : [];
        foreach ($tags as $t) {
            if (!is_string($t)) continue;
            if ($t === 'kind:intro' || $t === 'kind:axis_intro') return true;
        }
        return false;
    };

    // 小工具：从卡池里挑某个 dim+side 的“最佳卡”
    $pickBest = function(string $dim, string $side, bool $preferIntro) use ($cards, $scores, $isIntro): ?array {
        $delta = (int)($scores[$dim]['delta'] ?? 0);

        $cands = [];
        foreach ($cards as $c) {
            $match = is_array($c['match'] ?? null) ? $c['match'] : null;
            $ax    = is_array($match['axis'] ?? null) ? $match['axis'] : null;
            if (!is_array($ax)) continue;

            if (($ax['dim'] ?? null) !== $dim) continue;
            if (($ax['side'] ?? null) !== $side) continue;

            $minDelta = isset($ax['min_delta']) ? (int)$ax['min_delta'] : 0;
            if ($delta < $minDelta) continue;

            $cands[] = $c;
        }
        if (empty($cands)) return null;

        // intro 偏好：guardrail 时先挑 intro
        if ($preferIntro) {
            $intro = array_values(array_filter($cands, fn($c) => $isIntro($c)));
            if (!empty($intro)) $cands = $intro;
        } else {
            // Top3 时：排除 intro
            $nonIntro = array_values(array_filter($cands, fn($c) => !$isIntro($c)));
            if (!empty($nonIntro)) $cands = $nonIntro;
        }

        // 按 priority desc，再按 id 稳定
        usort($cands, function($a,$b){
            $pa = (int)($a['priority'] ?? 0);
            $pb = (int)($b['priority'] ?? 0);
            if ($pa !== $pb) return $pb <=> $pa;
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        return $cands[0] ?? null;
    };

    // 输出统一化（同 buildSectionCardsFromAssets 的 push）
    $normalizeOut = function(array $card): array {
        return [
            'id'       => (string)($card['id'] ?? ''),
            'section'  => (string)($card['section'] ?? 'traits'),
            'title'    => (string)($card['title'] ?? ''),
            'desc'     => (string)($card['desc'] ?? ''),
            'bullets'  => is_array($card['bullets'] ?? null) ? array_values($card['bullets']) : [],
            'tips'     => is_array($card['tips'] ?? null) ? array_values($card['tips']) : [],
            'tags'     => is_array($card['tags'] ?? null) ? array_values($card['tags']) : [],
            'priority' => (int)($card['priority'] ?? 0),
            'match'    => $card['match'] ?? null,
        ];
    };

    // 3) Top3：按 strongest axis 逐个 dim 取 1 张（非 intro 优先）
    $out = [];
    $seenId = [];
    $seenDim = [];

    foreach ($axisRank as $ax) {
        if (count($out) >= 3) break;

        $dim  = (string)$ax['dim'];
        $side = (string)$ax['side'];
        if ($side === '') continue;

        $card = $pickBest($dim, $side, false);
        if (!$card) continue;

        $id = (string)($card['id'] ?? '');
        if ($id === '' || isset($seenId[$id])) continue;

        $seenId[$id] = true;
        $seenDim[$dim] = true;
        $out[] = $normalizeOut($card);
    }

    // 4) Guardrail：EI 优先，否则 JP（优先 intro）
    $guardDim = !isset($seenDim['EI']) ? 'EI' : (!isset($seenDim['JP']) ? 'JP' : null);
    if ($guardDim) {
        $side = (string)($scores[$guardDim]['side'] ?? '');
        $guard = $side !== '' ? $pickBest($guardDim, $side, true) : null;

        if ($guard) {
            $id = (string)($guard['id'] ?? '');
            if ($id !== '' && !isset($seenId[$id])) {
                $seenId[$id] = true;
                $seenDim[$guardDim] = true;
                $out[] = $normalizeOut($guard);
            }
        } else {
            // 真找不到对应资产：用你已有的 fallback 解释卡（guardrail 也成立）
            $out[] = $this->makeFallbackAxisIntroCard('traits', $typeCode, $this->buildAxisInfo($scoresPct, []), $guardDim);
        }
    }

    // 5) 不足 4：用 section fallback 补齐
    if (count($out) < 4) {
        $need = 4 - count($out);
        $fallback = $this->buildSectionFallbackCards('traits', $profile, $scores, $scoresPct, $typeCode);
        foreach ($fallback as $c) {
            if ($need <= 0) break;
            $id = (string)($c['id'] ?? '');
            if ($id === '' || isset($seenId[$id])) continue;
            $seenId[$id] = true;
            $out[] = $c;
            $need--;
        }
    }

    return array_slice(array_values($out), 0, 4);
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

/**
 * 归一化内容包入参：
 * - v0.2.1-TEST              => MBTI-CN-v0.2.1-TEST
 * - MBTI.cn-mainland.zh-CN.v0.2.1-TEST => MBTI-CN-v0.2.1-TEST
 * - MBTI-CN-v0.2.1-TEST      => 原样返回
 */
private function normalizeContentPackageDir(string $pkgOrVersion): string
{
    $s = trim((string)$pkgOrVersion);
    if ($s === '') return $this->currentContentPackageVersion();

    if (str_starts_with($s, 'MBTI-CN-')) {
        return $s;
    }

    // pack_id: MBTI.cn-mainland.zh-CN.v0.2.1-TEST
    if (substr_count($s, '.') >= 3) {
        $parts = explode('.', $s);
        $ver = implode('.', array_slice($parts, 3)); // v0.2.1-TEST
        return 'MBTI-CN-' . $ver;
    }

    // pure version: v0.2.1-TEST
    if (str_starts_with($s, 'v')) {
        return 'MBTI-CN-' . $s;
    }

    // 兜底：当作目录名
    return $s;
}

private function mergeNonNullRecursive(array $base, array $override): array
{
    foreach ($override as $k => $v) {
        if ($v === null) continue; // ✅ 关键：不允许 null 覆盖

        if (is_array($v) && is_array($base[$k] ?? null)) {
            $base[$k] = $this->mergeNonNullRecursive($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

private function finalizeHighlightsSchema(array $highlights, string $typeCode): array
{
    $out = [];

    foreach ($highlights as $h) {
        if (!is_array($h)) continue;

        // kind：优先字段，其次 tags(kind:xxx)，最后按 id 前缀兜底
        $kind = is_string($h['kind'] ?? null) ? trim($h['kind']) : '';
        if ($kind === '') {
            $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];
            foreach ($tags as $t) {
                if (is_string($t) && str_starts_with($t, 'kind:')) {
                    $kind = trim(substr($t, 5));
                    break;
                }
            }
        }
        if ($kind === '') {
            $id0 = (string)($h['id'] ?? '');
            if (str_starts_with($id0, 'hl.action')) $kind = 'action';
            elseif (str_starts_with($id0, 'hl.blindspot')) $kind = 'blindspot';
            else $kind = 'axis';
        }
        $h['kind'] = $kind;

        // id
        $id = is_string($h['id'] ?? null) ? trim($h['id']) : '';
        if ($id === '') $id = 'hl.generated.' . (string) \Illuminate\Support\Str::uuid();
        $h['id'] = $id;

        // title
        $title = is_string($h['title'] ?? null) ? trim($h['title']) : '';
        if ($title === '') {
            $title = match ($kind) {
                'blindspot' => '盲点提醒',
                'action'    => '行动建议',
                'strength'  => '你的优势',
                'risk'      => '风险提醒',
                default     => '要点',
            };
        }
        $h['title'] = $title;

        // text：优先 text，其次 desc，最后给一个兜底句（确保 length>0）
        $text = is_string($h['text'] ?? null) ? trim($h['text']) : '';
        if ($text === '') {
            $desc = is_string($h['desc'] ?? null) ? trim($h['desc']) : '';
            $text = $desc !== '' ? $desc : '这一条是系统生成的重点提示，可作为自我观察的参考。';
        }
        $h['text'] = $text;

        // tips/tags：必须 array 且 length>=1
        $tips = is_array($h['tips'] ?? null) ? $h['tips'] : [];
        $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];

        $tips = array_values(array_filter($tips, fn($x) => is_string($x) && trim($x) !== ''));
        $tags = array_values(array_filter($tags, fn($x) => is_string($x) && trim($x) !== ''));

        if (count($tips) < 1) {
            $tips = match ($kind) {
                'action'    => ['把目标写成 1 句话，再拆成 3 个可交付节点'],
                'blindspot' => ['重要场景先做一次“反向校验”再决定'],
                default     => ['先做一小步，再迭代优化'],
            };
        }
        if (count($tags) < 1) {
            $tags = ['generated', "kind:{$kind}", "type:{$typeCode}"];
        } else {
            // 确保 kind 标签存在（你后续也用它排序）
            $hasKindTag = false;
            foreach ($tags as $t) if (str_starts_with($t, 'kind:')) { $hasKindTag = true; break; }
            if (!$hasKindTag) $tags[] = "kind:{$kind}";
        }

        $h['tips'] = $tips;
        $h['tags'] = $tags;

        $out[] = $h;
    }

    return $out;
}
}