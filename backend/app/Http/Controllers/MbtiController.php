<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MbtiController extends Controller
{
    /**
     * 健康检查接口
     * GET /api/v0.2/health
     */
    public function health()
    {
        return response()->json([
            'ok'      => true,
            'service' => 'Fermat Assessment Platform API',
            'version' => 'v0.2-skeleton',
            'time'    => now()->toISOString(),
        ]);
    }

    /**
     * MBTI 量表元信息
     * GET /api/v0.2/scales/MBTI
     */
    public function scaleMeta()
    {
        return response()->json([
            'scale_code'      => 'MBTI',
            'title'           => 'MBTI v2.5 · FermatMind',
            'question_count'  => 144, // 先写死
            'region'          => 'CN_MAINLAND',
            'locale'          => 'zh-CN',
            'version'         => 'v0.2',
            'price_tier'      => 'FREE',
        ]);
    }

    /**
     * MBTI 题目 Demo（先用少量静态题）
     * GET /api/v0.2/scales/MBTI/questions
     */
    public function questions()
    {
        $items = [
            [
                'question_id' => 'MBTI-001',
                'order'       => 1,
                'text'        => '在一群人当中，你更常：',
                'dimension'   => 'EI',
                'options'     => [
                    ['code' => 'A', 'text' => '主动开启话题、带动气氛'],
                    ['code' => 'B', 'text' => '安静观察，等别人先开口'],
                ],
            ],
            [
                'question_id' => 'MBTI-002',
                'order'       => 2,
                'text'        => '做决定前，你更看重：',
                'dimension'   => 'TF',
                'options'     => [
                    ['code' => 'A', 'text' => '客观分析、利弊和逻辑'],
                    ['code' => 'B', 'text' => '双方感受与关系氛围'],
                ],
            ],
            [
                'question_id' => 'MBTI-003',
                'order'       => 3,
                'text'        => '规划生活时，你更倾向：',
                'dimension'   => 'JP',
                'options'     => [
                    ['code' => 'A', 'text' => '提前计划，按步骤来'],
                    ['code' => 'B', 'text' => '保持弹性，走一步看一步'],
                ],
            ],
            [
                'question_id' => 'MBTI-004',
                'order'       => 4,
                'text'        => '接收信息时，你更在意：',
                'dimension'   => 'SN',
                'options'     => [
                    ['code' => 'A', 'text' => '具体事实和细节'],
                    ['code' => 'B', 'text' => '整体意义和可能性'],
                ],
            ],
            [
                'question_id' => 'MBTI-005',
                'order'       => 5,
                'text'        => '周末安排，你更像：',
                'dimension'   => 'EI',
                'options'     => [
                    ['code' => 'A', 'text' => '和一群人一起活动更有能量'],
                    ['code' => 'B', 'text' => '自己待着或和很少几个人更舒服'],
                ],
            ],
        ];

        return response()->json([
            'scale_code' => 'MBTI',
            'version'    => 'v0.2',
            'region'     => 'CN_MAINLAND',
            'locale'     => 'zh-CN',
            'items'      => $items,
        ]);
    }

    /**
     * 接收一次作答（Stage 2 先用本地 JSON 文件保存）
     * POST /api/v0.2/attempts
     *
     * 期望前端发送的 JSON 大致结构：
     * {
     *   "anon_id": "xxx",          // 可选，匿名 id
     *   "scale_code": "MBTI",
     *   "answers": [
     *     {"question_id": "MBTI-001", "choice": "A"},
     *     {"question_id": "MBTI-002", "choice": "B"}
     *   ]
     * }
     */
    public function createAttempt(Request $request)
    {
        // 1）拿到基础字段（全部容错处理）
        $anonId     = $request->input('anon_id', 'anon-demo');
        $scaleCode  = $request->input('scale_code', 'MBTI');
        $answers    = $request->input('answers', []);

        // 2）生成一个简单的 attempt_id
        $attemptId = 'mbti-' . uniqid();

        // 3）这里先不做真实打分，写一个“固定 Demo 结果”
        $result = [
            'type_code' => 'ENFJ-A',
            'dimensions' => [
                'EI' => [
                    'score_E' => 70,
                    'score_I' => 30,
                ],
                'SN' => [
                    'score_S' => 35,
                    'score_N' => 65,
                ],
                'TF' => [
                    'score_T' => 40,
                    'score_F' => 60,
                ],
                'JP' => [
                    'score_J' => 55,
                    'score_P' => 45,
                ],
                'AT' => [
                    'score_A' => 60,
                    'score_T' => 40,
                ],
            ],
        ];

        // 4）把这次作答 + 结果写到本地文件（storage/app/attempts/xxx.json）
        $record = [
            'attempt_id' => $attemptId,
            'anon_id'    => $anonId,
            'scale_code' => $scaleCode,
            'answers'    => $answers,
            'result'     => $result,
            'created_at' => now()->toISOString(),
        ];

        // 确保目录存在
        Storage::disk('local')->makeDirectory('attempts');

        Storage::disk('local')->put(
            "attempts/{$attemptId}.json",
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        // 5）返回给前端：attempt_id + 一个结果预览
        return response()->json([
            'ok'         => true,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'result_preview' => [
                'type_code' => $result['type_code'],
            ],
        ], 201);
    }

    /**
     * 查询一次作答的结果
     * GET /api/v0.2/attempts/{id}/result
     */
    public function getResult(string $id)
    {
        $path = "attempts/{$id}.json";

        if (! Storage::disk('local')->exists($path)) {
            return response()->json([
                'ok'    => false,
                'error' => 'attempt_not_found',
            ], 404);
        }

        $content = Storage::disk('local')->get($path);
        $record  = json_decode($content, true);

        return response()->json([
            'ok'         => true,
            'attempt_id' => $record['attempt_id'] ?? $id,
            'scale_code' => $record['scale_code'] ?? 'MBTI',
            'result'     => $record['result'] ?? null,
            'created_at' => $record['created_at'] ?? null,
        ]);
    }
}