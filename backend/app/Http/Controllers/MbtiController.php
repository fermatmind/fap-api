<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Attempt;
use App\Models\Result;
use App\Models\Event;

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
     * POST /api/v0.2/attempts
     * 接收一次完整作答：Attempt + Result 一起落库 + 记录事件
     */
    public function storeAttempt(Request $request)
    {
        // 核心字段校验（Skeleton 版）
        $validated = $request->validate([
            'anon_id'                => 'required|string|max:64',
            'scale_code'             => 'required|string|max:32',
            'scale_version'          => 'required|string|max:16',
            'question_count'         => 'required|integer|min:1',
            'answers_summary'        => 'required|array',
            'client_platform'        => 'required|string|max:32',
            'client_version'         => 'nullable|string|max:32',
            'channel'                => 'nullable|string|max:32',
            'referrer'               => 'nullable|string|max:255',

            'result'                 => 'required|array',
            'result.type_code'       => 'required|string|max:16',
            'result.scores'          => 'required|array',
            'result.profile_version' => 'nullable|string|max:32',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $attemptId = (string) Str::uuid();
            $resultId  = (string) Str::uuid();

            // 1）attempts
            $attempt = Attempt::create([
                'id'                   => $attemptId,
                'anon_id'              => $validated['anon_id'],
                'user_id'              => null,
                'scale_code'           => $validated['scale_code'],
                'scale_version'        => $validated['scale_version'],
                'question_count'       => $validated['question_count'],
                'answers_summary_json' => $validated['answers_summary'],
                'client_platform'      => $validated['client_platform'],
                'client_version'       => $validated['client_version'] ?? null,
                'channel'              => $validated['channel'] ?? null,
                'referrer'             => $validated['referrer'] ?? null,
                'started_at'           => now(),
                'submitted_at'         => now(),
            ]);

            // 2）results
            $resultPayload = $validated['result'];

            $result = Result::create([
                'id'              => $resultId,
                'attempt_id'      => $attemptId,
                'scale_code'      => $validated['scale_code'],
                'scale_version'   => $validated['scale_version'],
                'type_code'       => $resultPayload['type_code'],
                'scores_json'     => $resultPayload['scores'],
                'profile_version' => $resultPayload['profile_version'] ?? null,
                'is_valid'        => true,
                'computed_at'     => now(),
            ]);

            // 3）记录一次 test_submit 事件
            $this->logEvent('test_submit', $request, [
                'scale_code'     => $attempt->scale_code,
                'scale_version'  => $attempt->scale_version,
                'attempt_id'     => $attemptId,
                'channel'        => $attempt->channel,
                'region'         => 'CN_MAINLAND',
                'locale'         => 'zh-CN',
                'meta_json'      => [
                    'question_count' => $attempt->question_count,
                    'type_code'      => $result->type_code,
                ],
            ]);

            return response()->json([
                'ok'         => true,
                'attempt_id' => $attemptId,
                'result_id'  => $resultId,
                'result'     => [
                    'type_code' => $result->type_code,
                    'scores'    => $result->scores_json,
                ],
            ], 201);
        });
    }

    /**
     * GET /api/v0.2/attempts/{id}/result
     * 通过 attempt_id 取结果，同时打一个 result_view 事件
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

        // 记录一次 result_view 事件
        $this->logEvent('result_view', $request, [
            'scale_code'    => $result->scale_code,
            'scale_version' => $result->scale_version,
            'attempt_id'    => $attemptId,
            'region'        => 'CN_MAINLAND',
            'locale'        => 'zh-CN',
            'meta_json'     => [
                'type_code' => $result->type_code,
            ],
        ]);

        return response()->json([
            'ok'              => true,
            'attempt_id'      => $attemptId,
            'scale_code'      => $result->scale_code,
            'scale_version'   => $result->scale_version,
            'type_code'       => $result->type_code,
            'scores'          => $result->scores_json,
            'profile_version' => $result->profile_version,
            'computed_at'     => $result->computed_at,
        ]);
    }

        /**
     * 内部方法：写 events 表
     */
    protected function logEvent(string $eventCode, Request $request, array $extra = []): void
    {
        try {
            // 给事件生成 UUID 主键
            $eventId = (string) Str::uuid();

            Event::create([
                'id'              => $eventId,
                'event_code'      => $eventCode,
                'user_id'         => null, // 先不做登录用户
                'anon_id'         => $request->input('anon_id'),

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
}