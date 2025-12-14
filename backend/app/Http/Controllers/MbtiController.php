<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MbtiController extends Controller
{
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
     * 返回 MBTI 量表元信息（写死的 Demo）
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
     * 返回 MBTI 题目 Demo（先给 5 题写死数据）
     */
    public function questions()
    {
        $items = [
            [
                'question_id' => 'MBTI-001',
                'order'       => 1,
                'dimension'   => 'EI',
                'text'        => '当你在一个新环境时，更容易感到：',
                'options'     => [
                    ['code' => 'A', 'text' => '主动跟人打招呼、聊起来很快'],
                    ['code' => 'B', 'text' => '先观察环境，慢慢再融入'],
                ],
            ],
            [
                'question_id' => 'MBTI-002',
                'order'       => 2,
                'dimension'   => 'TF',
                'text'        => '做决定时，你更看重：',
                'options'     => [
                    ['code' => 'A', 'text' => '逻辑、利弊分析'],
                    ['code' => 'B', 'text' => '感受、关系是否和谐'],
                ],
            ],
            [
                'question_id' => 'MBTI-003',
                'order'       => 3,
                'dimension'   => 'JP',
                'text'        => '面对一件重要的事情，你更倾向于：',
                'options'     => [
                    ['code' => 'A', 'text' => '提前规划好步骤再行动'],
                    ['code' => 'B', 'text' => '边做边调整，比较随性'],
                ],
            ],
            [
                'question_id' => 'MBTI-004',
                'order'       => 4,
                'dimension'   => 'SN',
                'text'        => '你更喜欢的，是哪一种信息：',
                'options'     => [
                    ['code' => 'A', 'text' => '具体细节、真实发生的事实'],
                    ['code' => 'B', 'text' => '大方向、趋势和可能性'],
                ],
            ],
            [
                'question_id' => 'MBTI-005',
                'order'       => 5,
                'dimension'   => 'EI',
                'text'        => '一整天社交活动结束后，你通常：',
                'options'     => [
                    ['code' => 'A', 'text' => '觉得很充实，很有能量'],
                    ['code' => 'B', 'text' => '需要自己安静待一会儿才能恢复'],
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
     * 4. 接收一次测评作答（超简化版，不写文件、不写 DB）
     */
    public function storeAttempt(Request $request)
    {
        $payload   = $request->all();
        $attemptId = (string) Str::uuid();

        return response()->json([
            'ok'         => true,
            'attempt_id' => $attemptId,
            'echo'       => $payload,
        ], 201);
    }

    /**
     * 5. 查询某次测评结果（假数据版）
     */
    public function getResult(string $id)
    {
        return response()->json([
            'ok'             => true,
            'attempt_id'     => $id,
            'scale_code'     => 'MBTI',
            'scale_version'  => 'v0.2',
            'type_code'      => 'ENFJ-A',
            'scores'         => [
                'EI' => 12,
                'SN' => 8,
                'TF' => 10,
                'JP' => 14,
                'AT' => 6
            ],
            'profile_version'=> 'mbti32-v2.5',
            'computed_at'    => now()->toIso8601String(),
        ]);
    }
}