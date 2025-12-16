<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\Event;

class MbtiController extends Controller
{
    /**
     * 缓存：本次请求内复用题库索引
     * @var array|null
     */
    private ?array $questionsIndex = null;

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
     * ✅ 读 content_packages/<pkg>/questions.json
     * ✅ 对外脱敏：不返回 score / key_pole / direction / irt / is_active
     */
    public function questions()
    {
        $pkg  = env('MBTI_CONTENT_PACKAGE', 'MBTI-CN-v0.2.1-TEST');
        $path = $this->contentPackagePath($pkg, 'questions.json');

        if (!is_file($path)) {
            return response()->json([
                'ok'    => false,
                'error' => 'questions.json not found',
                'path'  => $path,
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
        $items = array_values(array_filter($items, fn($q) => ($q['is_active'] ?? true) === true));
        usort($items, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

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
            'ok'       => true,
            'scale_code' => 'MBTI',
            'version'  => 'v0.2',
            'region'   => 'CN_MAINLAND',
            'locale'   => 'zh-CN',
            'content_package_version' => $pkg,
            'items'    => $safe,
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

        // 维度：固定 5 轴
        $dims = ['EI','SN','TF','JP','AT'];

        // 统计
        $dimN        = array_fill_keys($dims, 0); // 题数
        $sumTowardP1 = array_fill_keys($dims, 0); // 朝 “第一极” 的累积分（-2..+2）

        // 额外归档：用于审计/可视化
        $countP1     = array_fill_keys($dims, 0); // >0
        $countP2     = array_fill_keys($dims, 0); // <0
        $countNeutral= array_fill_keys($dims, 0); // ==0

        foreach ($payload['answers'] as $a) {
            $qid  = $a['question_id'];
            $code = strtoupper($a['code'] ?? '');

            $meta = $index[$qid];
            $dim  = $meta['dimension'];

            if (!isset($dimN[$dim])) {
                continue;
            }

            // 取选项分值
            $scoreMap = $meta['score_map'] ?? [];
            if (!array_key_exists($code, $scoreMap)) {
                // 理论上不会发生（validate 已限制 A~E），但还是防御一下
                continue;
            }

            $rawScore  = (int) $scoreMap[$code];                 // 例如 A=2 ... E=-2
            $direction = (int) ($meta['direction'] ?? 1);        // 1 或 -1
            $keyPole   = (string) ($meta['key_pole'] ?? '');     // 例如 E/I/S/N/T/F/J/P/A/T

            // 先应用 direction（决定该题正负方向）
            $signed = $rawScore * ($direction === 0 ? 1 : $direction);

            // 把“signed 分数”统一换算成：朝维度第一极（P1）的分数
            // 维度第一极定义：EI=>E, SN=>S, TF=>T, JP=>J, AT=>A
            [$p1, $p2] = $this->getDimensionPoles($dim);

            // 约定：signed 的“正方向”指向 key_pole
            // 若 key_pole 是 p1，则 signed 正 -> 朝 p1
            // 若 key_pole 是 p2，则 signed 正 -> 朝 p2，所以朝 p1 要取反
            $towardP1 = $signed;
            if ($keyPole === $p2) {
                $towardP1 = -$signed;
            }

            $dimN[$dim] += 1;
            $sumTowardP1[$dim] += $towardP1;

            if ($towardP1 > 0) $countP1[$dim] += 1;
            elseif ($towardP1 < 0) $countP2[$dim] += 1;
            else $countNeutral[$dim] += 1;
        }

        // scores_pct：把 sumTowardP1（范围 [-2n, +2n]）映射到 0~100（50 是中性）
        $scoresPct = [];
        foreach ($dims as $dim) {
            $n = (int) $dimN[$dim];
            if ($n <= 0) {
                $scoresPct[$dim] = 50;
                continue;
            }
            // 公式：pct = (sum + 2n) / (4n) * 100
            $scoresPct[$dim] = (int) round((($sumTowardP1[$dim] + 2 * $n) / (4 * $n)) * 100);
        }

        // type_code：四字母 + -A/-T（AT>=50 => A，否则 T）
        $letters = [
            'EI' => $scoresPct['EI'] >= 50 ? 'E' : 'I',
            'SN' => $scoresPct['SN'] >= 50 ? 'S' : 'N',
            'TF' => $scoresPct['TF'] >= 50 ? 'T' : 'F',
            'JP' => $scoresPct['JP'] >= 50 ? 'J' : 'P',
        ];
        $atSuffix = $scoresPct['AT'] >= 50 ? 'A' : 'T';
        $typeCode = implode('', $letters) . '-' . $atSuffix;

        // axis_states：把连续百分比离散化
        $axisStates = [];
        foreach ($scoresPct as $dim => $pct) {
            $d = abs($pct - 50);
            $axisStates[$dim] = match (true) {
                $d <= 4  => 'very_weak',
                $d <= 9  => 'weak',
                $d <= 19 => 'moderate',
                $d <= 29 => 'clear',
                $d <= 39 => 'strong',
                default  => 'very_strong',
            };
        }

        // scores_json：可审计（仍保持 a/b/total 字段，兼容你原来的结构）
        $scoresJson = [];
        foreach ($dims as $dim) {
            $scoresJson[$dim] = [
                'a'       => $countP1[$dim],          // 朝 P1 的次数（>0）
                'b'       => $countP2[$dim],          // 朝 P2 的次数（<0）
                'neutral' => $countNeutral[$dim],     // 中立次数（=0）
                'sum'     => $sumTowardP1[$dim],      // 朝 P1 的累计分
                'total'   => $dimN[$dim],             // 该维度题数
            ];
        }

        // answers_summary_json：最小归档
        $answersSummary = [
            'answer_count' => count($payload['answers']),
            'dims_total'   => $dimN,
            'dims_sum_p1'  => $sumTowardP1,
        ];

        $profileVersion        = config('fap.profile_version', 'mbti32-v2.5');
        $contentPackageVersion = config('fap.content_package_version', 'MBTI-CN-v0.2.1');

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
            $contentPackageVersion
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

            // 3) 记录 test_submit 事件
            $this->logEvent('test_submit', $request, [
                'anon_id'       => $payload['anon_id'],
                'scale_code'    => $attempt->scale_code,
                'scale_version' => $attempt->scale_version,
                'attempt_id'    => $attemptId,
                'channel'       => $attempt->channel,
                'region'        => $payload['region'] ?? 'CN_MAINLAND',
                'locale'        => $payload['locale'] ?? 'zh-CN',
                'meta_json'     => [
                    'answer_count'   => count($payload['answers']),
                    'question_count' => $attempt->question_count,
                    'type_code'      => $result->type_code,
                    'scores_pct'     => $scoresPct,
                    'axis_states'    => $axisStates,
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
     */
    public function getResult(Request $request, string $attemptId)
    {
        $result = Result::where('attempt_id', $attemptId)->first();

        if (! $result) {
            return response()->json([
                'ok'      => false,
                'error'   => 'RESULT_NOT_FOUND',
                'message' => 'Result not found for given attempt_id',
            ], 404);
        }

        $attempt = Attempt::where('id', $attemptId)->first();

        $this->logEvent('result_view', $request, [
            'anon_id'       => $attempt?->anon_id,
            'scale_code'    => $result->scale_code,
            'scale_version' => $result->scale_version,
            'attempt_id'    => $attemptId,
            'region'        => $attempt?->region ?? 'CN_MAINLAND',
            'locale'        => $attempt?->locale ?? 'zh-CN',
            'meta_json'     => [
                'type_code' => $result->type_code,
            ],
        ]);

        // ✅ 强制 5 轴全量输出
        $dims = ['EI','SN','TF','JP','AT'];

        $scoresJson = $result->scores_json ?? [];
        $scoresPct  = $result->scores_pct ?? [];
        $axisStates = $result->axis_states ?? [];

        foreach ($dims as $d) {
            if (!array_key_exists($d, $scoresJson)) {
                $scoresJson[$d] = ['a'=>0,'b'=>0,'neutral'=>0,'sum'=>0,'total'=>0];
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
            'content_package_version' => $result->content_package_version ?? config('fap.content_package_version', 'MBTI-CN-v0.2.1'),

            'computed_at'             => $result->computed_at,
        ]);
    }

    /**
     * GET /api/v0.2/attempts/{id}/share
     */
    public function getShare(Request $request, string $attemptId)
    {
        $result = Result::where('attempt_id', $attemptId)->first();

        if (! $result) {
            return response()->json([
                'ok'      => false,
                'error'   => 'RESULT_NOT_FOUND',
                'message' => 'Result not found for given attempt_id',
            ], 404);
        }

        $attempt = Attempt::where('id', $attemptId)->first();

        $shareId = (string) Str::uuid();

        $contentPackageVersion = $result->content_package_version
            ?? config('fap.content_package_version', 'MBTI-CN-v0.2.1');

        $typeCode = $result->type_code;

        $profile = $this->loadTypeProfile($contentPackageVersion, $typeCode);

        $this->logEvent('share_view', $request, [
            'anon_id'       => $attempt?->anon_id,
            'scale_code'    => $result->scale_code,
            'scale_version' => $result->scale_version,
            'attempt_id'    => $attemptId,
            'region'        => $attempt?->region ?? 'CN_MAINLAND',
            'locale'        => $attempt?->locale ?? 'zh-CN',
            'meta_json'     => [
                'share_id'  => $shareId,
                'type_code' => $typeCode,
                'pkg'       => $contentPackageVersion,
            ],
        ]);

        return response()->json([
            'ok'                      => true,
            'attempt_id'              => $attemptId,
            'share_id'                => $shareId,
            'content_package_version' => $contentPackageVersion,
            'type_code'               => $typeCode,

            'type_name'     => $profile['type_name'] ?? null,
            'tagline'       => $profile['tagline'] ?? null,
            'rarity'        => $profile['rarity'] ?? null,
            'keywords'      => $profile['keywords'] ?? [],
            'short_summary' => $profile['short_summary'] ?? null,
        ]);
    }

    /**
     * 内部方法：写 events 表
     */
    protected function logEvent(string $eventCode, Request $request, array $extra = []): void
    {
        try {
            $eventId = (string) Str::uuid();

            Event::create([
                'id'              => $eventId,
                'event_code'      => $eventCode,
                'user_id'         => null,
                'anon_id'         => $extra['anon_id'] ?? $request->input('anon_id'),

                'scale_code'      => $extra['scale_code']     ?? null,
                'scale_version'   => $extra['scale_version']  ?? null,
                'attempt_id'      => $extra['attempt_id']     ?? null,

                'channel'         => $request->input('channel', $extra['channel'] ?? null),
                'region'          => $extra['region']         ?? 'CN_MAINLAND',
                'locale'          => $extra['locale']         ?? 'zh-CN',

                'client_platform' => $request->input('client_platform', $extra['client_platform'] ?? null),
                'client_version'  => $request->input('client_version',  $extra['client_version'] ?? null),

                'occurred_at'     => now(),
                'meta_json'       => $extra['meta_json']      ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::warning('event_log_failed', [
                'event_code' => $eventCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }
     
    /**
     * 内容包文件路径（统一入口）
     * Laravel base_path() = backend/，内容包在仓库根目录 content_packages/
     */
    private function contentPackagePath(string $pkg, string $file): string
    {
        // 防止传入 ../ 之类的路径穿越
        $pkg  = trim($pkg, "/\\");
        $file = trim($file, "/\\");

        return base_path("../content_packages/{$pkg}/{$file}");
    }

    /**
     * 维度极性定义（第一极 / 第二极）
     * EI=>E/I, SN=>S/N, TF=>T/F, JP=>J/P, AT=>A/T
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

    /**
     * 读取题库，并构建 question_id => meta 索引
     * meta: dimension, key_pole, direction, score_map
     */
    private function getQuestionsIndex(): array
    {
        if ($this->questionsIndex !== null) {
            return $this->questionsIndex;
        }

        $pkg  = env('MBTI_CONTENT_PACKAGE', 'MBTI-CN-v0.2.1-TEST');
        $path = $this->contentPackagePath($pkg, 'questions.json');

        if (!is_file($path)) {
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

        $items = array_values(array_filter($items, fn($q) => ($q['is_active'] ?? true) === true));

        $idx = [];
        foreach ($items as $q) {
            $qid = $q['question_id'] ?? null;
            if (!$qid) continue;

            // options score map
            $scoreMap = [];
            foreach (($q['options'] ?? []) as $o) {
                if (!isset($o['code'])) continue;
                $scoreMap[strtoupper($o['code'])] = (int) ($o['score'] ?? 0);
            }

            $idx[$qid] = [
                'dimension'  => $q['dimension'] ?? null,
                'key_pole'   => $q['key_pole'] ?? null,
                'direction'  => $q['direction'] ?? 1,
                'score_map'  => $scoreMap,
            ];
        }

        $this->questionsIndex = $idx;
        return $this->questionsIndex;
    }

    /**
     * 从内容包读取 type_profiles.json 并返回指定 type_code 的 profile
     * 注意：Laravel 的 base_path() 是 backend 目录，所以要用 ../content_packages
     */
    private function loadTypeProfile(string $contentPackageVersion, string $typeCode): array
    {
        $path = $this->contentPackagePath($contentPackageVersion, 'type_profiles.json');

        if (!is_file($path)) {
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
}