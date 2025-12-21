<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

use App\Models\Attempt;
use App\Models\Result;

use App\Support\WritesEvents;

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
        $pkg  = $this->currentContentPackageVersion();

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
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        // 统计
        $dimN        = array_fill_keys($dims, 0);
        $sumTowardP1 = array_fill_keys($dims, 0);

        // 额外归档：用于审计/可视化
        $countP1      = array_fill_keys($dims, 0);
        $countP2      = array_fill_keys($dims, 0);
        $countNeutral = array_fill_keys($dims, 0);

        foreach ($payload['answers'] as $a) {
            $qid  = $a['question_id'];
            $code = strtoupper($a['code'] ?? '');

            $meta = $index[$qid];
            $dim  = $meta['dimension'];

            if (!isset($dimN[$dim])) {
                continue;
            }

            $scoreMap = $meta['score_map'] ?? [];
            if (!array_key_exists($code, $scoreMap)) {
                continue;
            }

            $rawScore  = (int) $scoreMap[$code];
            $direction = (int) ($meta['direction'] ?? 1);
            $keyPole   = (string) ($meta['key_pole'] ?? '');

            $signed = $rawScore * ($direction === 0 ? 1 : $direction);

            [$p1, $p2] = $this->getDimensionPoles($dim);

            $towardP1 = $signed;
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

        // scores_pct：[-2n,+2n] -> [0,100]
        $scoresPct = [];
        foreach ($dims as $dim) {
            $n = (int) $dimN[$dim];
            if ($n <= 0) {
                $scoresPct[$dim] = 50;
                continue;
            }
            $scoresPct[$dim] = (int) round((($sumTowardP1[$dim] + 2 * $n) / (4 * $n)) * 100);
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

        // axis_states：离散化
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

        // scores_json：可审计
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

        $answersSummary = [
            'answer_count' => count($payload['answers']),
            'dims_total'   => $dimN,
            'dims_sum_p1'  => $sumTowardP1,
        ];

        $profileVersion        = config('fap.profile_version', 'mbti32-v2.5');
        $contentPackageVersion = $this->currentContentPackageVersion();

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
     * ✅ 只返回分享文案骨架
     * ✅ 不写 events（share_generate/share_click 由前端 POST /events 上报）
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

        $shareId = (string) Str::uuid();

        $contentPackageVersion = $result->content_package_version
            ?? $this->currentContentPackageVersion();

        $typeCode = $result->type_code;

        // ✅ 统一走多路径兜底（修掉 share_snippets “storage-only” 的坑）
        $snippet = $this->loadShareSnippet($contentPackageVersion, $typeCode);
        $profile = $this->loadTypeProfile($contentPackageVersion, $typeCode);

        $merged = array_merge($profile ?: [], $snippet ?: []);

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

        $attempt = Attempt::where('id', $attemptId)->first();

        // 版本信息：全部从 results/配置里取（不依赖前端）
        $profileVersion = $result->profile_version
            ?? config('fap.profile_version', 'mbti32-v2.5');

        $contentPackageVersion = $result->content_package_version
            ?? $this->currentContentPackageVersion();

        // 读 type_profiles（最小骨架）
        $profile = $this->loadTypeProfile($contentPackageVersion, $result->type_code);

        // ✅ 强制 5 轴全量输出
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];

        $scoresPct  = $result->scores_pct ?? [];
        $axisStates = $result->axis_states ?? [];

        foreach ($dims as $d) {
            if (!array_key_exists($d, $scoresPct)) {
                $scoresPct[$d] = 50;
            }
            if (!array_key_exists($d, $axisStates)) {
                $axisStates[$d] = 'moderate';
            }
        }

        // scores（稳定结构：pct/state/side/delta）
        // ✅ delta 统一为 0~100：abs(pct-50)*2（用于 highlight 排序/阈值更直观）
        $scores = [];
        foreach ($dims as $dim) {
            $pct   = (int) ($scoresPct[$dim] ?? 50);
            $state = (string) ($axisStates[$dim] ?? 'moderate');

            [$p1, $p2] = $this->getDimensionPoles($dim);
            $side = ($pct >= 50) ? $p1 : $p2;

            $scores[$dim] = [
                'pct'   => $pct,
                'state' => $state,
                'side'  => $side,
                'delta' => abs($pct - 50) * 2,
            ];
        }

        // 可选：记录 report_view（和 result_view 类似）
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
            ],
        ]);

        // =========================
        // M3-1：读 report 资产（identity / reads）
        // M3-3：highlights 改为 templates + overrides 动态生成
        // M3-4：borderline_note 改为“按 delta 命中 + 按轴模板”
        // =========================
        $typeCode = $result->type_code;

        $identityItems = $this->loadReportAssetItems($contentPackageVersion, 'report_identity_cards.json');
$identityCard  = $identityItems[$typeCode] ?? null;

// ✅ M3-6：按优先级拼装 recommended_reads（type > role > strategy > top_axis > fallback）
$recommendedReads = $this->buildRecommendedReads($contentPackageVersion, $typeCode, $scoresPct);

        // ✅ M3-3 highlights（templates + overrides）
        $highlights = $this->buildHighlights($scoresPct, $axisStates, $typeCode, $contentPackageVersion);

        // ✅ M3-4 borderline_note（按 delta 命中 0~2 条；结构稳定）
        $borderlineNote = $this->buildBorderlineNote($scoresPct, $contentPackageVersion);
        if (!is_array($borderlineNote)) $borderlineNote = ['items' => []];
        if (!is_array($borderlineNote['items'] ?? null)) $borderlineNote['items'] = [];

        // ✅ M3-5 role_card / strategy_card
        $roleCard     = $this->buildRoleCard($contentPackageVersion, $typeCode);
        $strategyCard = $this->buildStrategyCard($contentPackageVersion, $typeCode);

        $reportPayload = [
            'versions' => [
                'engine'                  => 'v1.2',
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
            ],

            'scores' => $scores,

            'profile' => [
                'type_code'     => $profile['type_code'] ?? $typeCode,
                'type_name'     => $profile['type_name'] ?? null,
                'tagline'       => $profile['tagline'] ?? null,
                'rarity'        => $profile['rarity'] ?? null,
                'keywords'      => $profile['keywords'] ?? [],
                'short_summary' => $profile['short_summary'] ?? null,
            ],

            'identity_card' => $identityCard,
            'highlights'    => $highlights,
            'borderline_note' => $borderlineNote,

            'layers' => [
                'role_card'     => $roleCard,
                'strategy_card' => $strategyCard,
                'identity'      => null,
            ],

            'sections' => [
                'traits'        => ['cards' => []],
                'career'        => ['cards' => []],
                'growth'        => ['cards' => []],
                'relationships' => ['cards' => []],
            ],

            'recommended_reads' => $recommendedReads,
        ];

        return response()->json([
            'ok'         => true,
            'attempt_id' => $attemptId,
            'type_code'  => $typeCode,
            'report'     => $reportPayload,
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

        $topN        = (int) ($tplRules['top_n'] ?? 2);
        $maxItems    = (int) ($tplRules['max_items'] ?? 2);
        $minDelta    = (int) ($tplRules['min_delta'] ?? 15);
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
            $pct   = (int) ($scoresPct[$dim] ?? 50);
            $level = (string) ($axisStates[$dim] ?? 'moderate');

            [$p1, $p2] = $this->getDimensionPoles($dim);
            $side = ($pct >= 50) ? $p1 : $p2;

            // delta 0~100
            $delta = abs($pct - 50) * 2;

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

            // gate: min_delta
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
                'pct'   => $pct,
                'delta' => $delta,
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
                // 递归 merge
                $card = array_replace_recursive($card, $override);

                // ✅ tips/tags 若 overrides 提供则整段覆盖
                if (array_key_exists('tips', $override)) {
                    $card['tips'] = is_array($override['tips'] ?? null) ? $override['tips'] : [];
                } else {
                    if (!is_array($card['tips'] ?? null)) $card['tips'] = [];
                }

                if (array_key_exists('tags', $override)) {
                    $card['tags'] = is_array($override['tags'] ?? null) ? $override['tags'] : [];
                } else {
                    if (!is_array($card['tags'] ?? null)) $card['tags'] = [];
                }
            }

            $candidates[] = $card;
        }

        // sort by delta desc
        usort($candidates, function ($a, $b) {
            return (int) ($b['delta'] ?? 0) <=> (int) ($a['delta'] ?? 0);
        });

        $take = min(max($topN, 0), max($maxItems, 0));
        $out  = array_slice($candidates, 0, $take);

        // ✅ 若模板命中为空：fallback 旧版（但必须能归一化到新结构）
        if (empty($out)) {
            if (is_array($oldPerType) && !empty($oldPerType)) {
                $norm = [];
                foreach (array_values($oldPerType) as $c) {
                    if (!is_array($c)) continue;

                    $id = (string) ($c['id'] ?? '');
                    if ($id === '') continue;

                    $dim   = $c['dim']   ?? null;
                    $side  = $c['side']  ?? null;
                    $level = $c['level'] ?? null;

                    // 尝试从 id 解析：AT_A_very_strong 这类
                    if ((!$dim || !$side || !$level)
                        && preg_match('/^(EI|SN|TF|JP|AT)_([EISNTFJPA])_(clear|strong|very_strong)$/', $id, $m)) {
                        $dim   = $m[1];
                        $side  = $m[2];
                        $level = $m[3];
                    }

                    if (!$dim || !$side || !$level) continue;

                    $pct   = (int) ($scoresPct[$dim] ?? 50);
                    $delta = abs($pct - 50) * 2;

                    $title = (string) ($c['title'] ?? '');
                    $text  = (string) ($c['text']  ?? $title);

                    $norm[] = [
                        'id'    => $id,
                        'dim'   => $dim,
                        'side'  => $side,
                        'level' => $level,
                        'pct'   => $pct,
                        'delta' => $delta,
                        'title' => $title,
                        'text'  => $text,
                        'tips'  => is_array($c['tips'] ?? null) ? $c['tips'] : [],
                        'tags'  => is_array($c['tags'] ?? null) ? $c['tags'] : [],
                    ];
                }

                if (!empty($norm)) {
                    return array_slice($norm, 0, $take);
                }
            }

            // allowEmpty 不管 true/false，此处都只能返回空数组（契约：结构稳定）
            return $allowEmpty ? [] : [];
        }

        return $out;
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
     */
    private function loadReportAssetJson(string $contentPackageVersion, string $filename): array
    {
        static $cache = [];

        $cacheKey = $contentPackageVersion . '|' . $filename . '|RAW';
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("../content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
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

    /**
     * 在内容包目录里找某个文件（兼容：根目录 or 子目录）
     * - 优先命中 root/<filename>
     * - 否则递归搜索（限制深度，避免扫太大）
     */
    private function findPackageFile(string $pkg, string $filename, int $maxDepth = 3): ?string
    {
        $pkg = trim($pkg, "/\\");
        $root = base_path("../content_packages/{$pkg}");

        if (!is_dir($root)) {
            return null;
        }

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

            foreach ($iter as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) continue;
                if ($fileInfo->getFilename() !== $filename) continue;

                // 计算相对深度
                $relDir = str_replace($root, '', $fileInfo->getPath());
                $relDir = trim(str_replace('\\', '/', $relDir), '/');
                $depth = ($relDir === '') ? 0 : substr_count($relDir, '/') + 1;

                if ($depth <= $maxDepth) {
                    return $fileInfo->getPathname();
                }
            }
        } catch (\Throwable $e) {
            return null;
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

        $pkg  = $this->currentContentPackageVersion();
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
        $contentPackageVersion = trim($contentPackageVersion, "/\\");
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
     */
    private function loadReportAssetItems(string $contentPackageVersion, string $filename, ?string $primaryIndexKey = 'type_code'): array
    {
        static $cache = [];

        $cacheKey = $contentPackageVersion . '|' . $filename;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("../content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
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
    $debugReads = (app()->environment('local', 'development') && (bool)env('FAP_READS_DEBUG', false));
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
    $maxItems  = (int)($rules['max_items'] ?? $max);
    $minItems  = (int)($rules['min_items'] ?? 0);
    $sortMode  = (string)($rules['sort'] ?? ''); // e.g. "priority_desc"
    $fillOrder = is_array($rules['fill_order'] ?? null)
        ? $rules['fill_order']
        : ['by_type','by_role','by_strategy','by_top_axis','fallback'];

    $bucketQuota = is_array($rules['bucket_quota'] ?? null) ? $rules['bucket_quota'] : [];
    $defaults    = is_array($rules['defaults'] ?? null) ? $rules['defaults'] : [];

    $dedupe = is_array($rules['dedupe'] ?? null) ? $rules['dedupe'] : [];
    $hardBy = is_array($dedupe['hard_by'] ?? null) ? $dedupe['hard_by'] : ['id'];
    $softBy = is_array($dedupe['soft_by'] ?? null) ? $dedupe['soft_by'] : ['canonical_id','canonical_url','url'];

    if ($debugReads) {
        $debug['rules'] = [
            'max_items' => $maxItems,
            'min_items' => $minItems,
            'sort' => $sortMode,
            'fill_order' => $fillOrder,
            'bucket_quota' => $bucketQuota,
            'hard_by' => $hardBy,
            'soft_by' => $softBy,
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
        $pct   = (int)($scoresPct[$dim] ?? 50);
        $delta = abs($pct - 50) * 2;
        [$p1, $p2] = $this->getDimensionPoles($dim);
        $side = ($pct >= 50) ? $p1 : $p2;

        if ($delta > $best['delta']) {
            $best = ['dim' => $dim, 'delta' => $delta, 'side' => $side];
        }
    }

    // by_top_axis key：走新口径（axis_key_format），并兼容旧格式
    $plainAxisKey = $best['dim'] . ':' . $best['side'];        // "EI:E"
    $prefAxisKey  = 'axis:' . $plainAxisKey;                   // "axis:EI:E"
    $axisKeyFormat = (string)($rules['axis_key_format'] ?? ''); // e.g. "axis:${DIM}:${SIDE}"

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

    // 每桶内部按 priority desc
    $sortByPriorityDesc = function (&$list): void {
        if (!is_array($list)) { $list = []; return; }
        usort($list, fn($a, $b) => (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0));
    };

    foreach ($bucketLists as $k => &$list) {
        $sortByPriorityDesc($list);
    }
    unset($list);

    // ----------------------------
    // 5) dedupe state + helpers
    // ----------------------------
    $seenId   = [];
    $seenCid  = [];
    $seenCUrl = [];
    $seenUrl  = [];

    $getNonEmptyString = function ($v): string {
        if ($v === null) return '';
        if (is_string($v)) return trim($v);
        if (is_numeric($v)) return (string)$v;
        return '';
    };

    // URL 归一化：去常见追踪参数 + 参数排序（保留业务参数如 id）
    $normalizeUrlKey = function (?string $url): string {
        $url = trim((string)$url);
        if ($url === '') return '';

        $parts = parse_url($url);
        $path  = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if ($path === '' && $query === '') return $url;

        $qs = [];
        if ($query !== '') {
            parse_str($query, $qs);
        }

        $dropKeys = [
            'utm_source','utm_medium','utm_campaign','utm_term','utm_content',
            'gclid','fbclid','msclkid','wbraid','gbraid',
            '_ga','_gl',
        ];
        foreach ($dropKeys as $k) {
            unset($qs[$k]);
        }

        if (!empty($qs)) {
            ksort($qs);
            $q = http_build_query($qs);
            return $q ? ($path . '?' . $q) : $path;
        }

        return $path;
    };

    $isDup = function (array $it) use (
        &$seenId, &$seenCid, &$seenCUrl, &$seenUrl,
        $hardBy, $softBy, $getNonEmptyString, $normalizeUrlKey
    ): bool {
        // hard dedupe
        if (in_array('id', $hardBy, true)) {
            $id = $getNonEmptyString($it['id'] ?? '');
            if ($id === '' || isset($seenId[$id])) return true;
        }

        // soft dedupe
        foreach ($softBy as $k) {
            $v = $getNonEmptyString($it[$k] ?? '');
            if ($v === '') continue;

            if ($k === 'canonical_id') {
                if (isset($seenCid[$v])) return true;
                continue;
            }

            if ($k === 'canonical_url') {
                $key = $normalizeUrlKey($v);
                if ($key !== '' && isset($seenCUrl[$key])) return true;
                continue;
            }

            if ($k === 'url') {
                $key = $normalizeUrlKey($v);
                if ($key !== '' && isset($seenUrl[$key])) return true;
                continue;
            }

            // 其它 soft key（未来扩展）默认不处理
        }

        return false;
    };

    $markSeen = function (array $it) use (
        &$seenId, &$seenCid, &$seenCUrl, &$seenUrl,
        $getNonEmptyString, $normalizeUrlKey
    ): void {
        $id = $getNonEmptyString($it['id'] ?? '');
        if ($id !== '') $seenId[$id] = true;

        $cid = $getNonEmptyString($it['canonical_id'] ?? '');
        if ($cid !== '') $seenCid[$cid] = true;

        $curl = $normalizeUrlKey($getNonEmptyString($it['canonical_url'] ?? ''));
        if ($curl !== '') $seenCUrl[$curl] = true;

        $url = $normalizeUrlKey($getNonEmptyString($it['url'] ?? ''));
        if ($url !== '') $seenUrl[$url] = true;
    };

    // ----------------------------
    // 6) normalize output (canonical_id 透出 + defaults 下沉)
    // ----------------------------
    $normalize = function (array $it) use ($defaults, $getNonEmptyString): array {
        // defaults 下沉：JSON 里没写的字段用 defaults 填
        $it = array_merge($defaults, $it);

        $id  = $getNonEmptyString($it['id'] ?? '');
        $cid = $getNonEmptyString($it['canonical_id'] ?? '');
        $cuz = $getNonEmptyString($it['canonical_url'] ?? '');

        $out = [
            'id'            => $id,
            'canonical_id'  => ($cid === '' ? null : $cid),
            'canonical_url' => ($cuz === '' ? null : $cuz),

            'type'     => (string)($it['type'] ?? 'article'),
            'title'    => (string)($it['title'] ?? ''),
            'desc'     => (string)($it['desc'] ?? ''),
            'url'      => (string)($it['url'] ?? ''),
            'cover'    => (string)($it['cover'] ?? ''),
            'priority' => (int)($it['priority'] ?? 0),
            'tags'     => is_array($it['tags'] ?? null) ? $it['tags'] : [],
        ];

        // 可选字段（有就带上）
        if (array_key_exists('cta', $it)) $out['cta'] = (string)$it['cta'];
        if (array_key_exists('estimated_minutes', $it)) $out['estimated_minutes'] = (int)$it['estimated_minutes'];
        if (array_key_exists('locale', $it)) $out['locale'] = (string)$it['locale'];
        if (array_key_exists('access', $it)) $out['access'] = (string)$it['access'];
        if (array_key_exists('channel', $it)) $out['channel'] = (string)$it['channel'];
        if (array_key_exists('status', $it)) $out['status'] = (string)$it['status'];
        if (array_key_exists('published_at', $it)) $out['published_at'] = (string)$it['published_at'];
        if (array_key_exists('updated_at', $it)) $out['updated_at'] = (string)$it['updated_at'];

        return $out;
    };

    // ----------------------------
    // 7) quota helper (supports "remaining")
    // ----------------------------
    $resolveCap = function ($capRaw, int $remaining): int {
        if (is_string($capRaw)) {
            $s = strtolower(trim($capRaw));
            if ($s === 'remaining' || $s === '*' || $s === 'all') return $remaining;
            if (is_numeric($capRaw)) return (int)$capRaw;
            return 0;
        }
        if (is_int($capRaw) || is_float($capRaw)) return (int)$capRaw;
        return $remaining;
    };

    // ----------------------------
    // 8) fill by bucket (bucket_quota 真执行 + debug)
    // ----------------------------
    $out = [];

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

            if ($isDup($it)) {
                if ($debugReads) $debug['buckets'][$bucketName]['skip_dup']++;
                continue;
            }

            $markSeen($it);
            $out[] = $normalize($it);
            $taken++;

            if ($debugReads) $debug['buckets'][$bucketName]['taken']++;
        }
    }

    // min_items：强制用 fallback 补齐（不受 quota 限制，但受 maxItems 限制）
    if ($minItems > 0 && count($out) < min($minItems, $maxItems)) {
        $need = min($minItems, $maxItems) - count($out);

        if ($debugReads) {
            $debug['min_items_fill'] = [
                'need' => $need,
                'taken' => 0,
                'skip_no_id' => 0,
                'skip_dup' => 0,
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

                if ($isDup($it)) {
                    if ($debugReads) $debug['min_items_fill']['skip_dup']++;
                    continue;
                }

                $markSeen($it);
                $out[] = $normalize($it);
                $need--;

                if ($debugReads) $debug['min_items_fill']['taken']++;
            }
        }
    }

    // 可选：最终排序（严格遵循 rules.sort=priority_desc）
    if ($sortMode === 'priority_desc') {
        usort($out, fn($a, $b) => (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0));
    }

    if ($debugReads) {
        $debug['result'] = [
            'count' => count($out),
            'ids' => array_values(array_map(fn($x) => $x['id'] ?? null, $out)),
        ];
        Log::debug('[recommended_reads] build', $debug);
    }

    return $out;
}
}