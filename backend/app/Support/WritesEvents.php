<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use App\Models\Event;

trait WritesEvents
{
    /**
     * 内部方法：写 events 表
     * - result_view：强制 10s 去抖（anon_id + attempt_id + event_code）
     * - share_generate/share_click：轻量去重（anon_id + attempt_id + share_style + page_session_id）
     */
    protected function logEvent(string $eventCode, Request $request, array $extra = []): void
    {
        try {
            $anonId    = $extra['anon_id'] ?? $request->input('anon_id');
            $attemptId = $extra['attempt_id'] ?? null;

            // ============ A) result_view 10s 去抖（强制） ============
            if ($eventCode === 'result_view' && $anonId && $attemptId) {
                $exists = Event::query()
                    ->where('event_code', 'result_view')
                    ->where('anon_id', $anonId)
                    ->where('attempt_id', $attemptId)
                    ->where('occurred_at', '>=', now()->subSeconds(10))
                    ->exists();

                if ($exists) {
                    return; // 丢弃：不报错、不影响业务接口返回
                }
            }

            // ============ B) share_generate / share_click 轻量去重 ============
            if (in_array($eventCode, ['share_generate', 'share_click'], true) && $anonId && $attemptId) {
                $meta          = $extra['meta_json'] ?? [];
                $shareStyle    = $meta['share_style'] ?? null;
                $pageSessionId = $meta['page_session_id'] ?? null;

                if ($shareStyle && $pageSessionId) {
                    $dup = Event::query()
                        ->where('event_code', $eventCode)
                        ->where('anon_id', $anonId)
                        ->where('attempt_id', $attemptId)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.share_style')) = ?", [$shareStyle])
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.page_session_id')) = ?", [$pageSessionId])
                        ->exists();

                    if ($dup) {
                        return;
                    }
                }
            }

            Event::create([
                'id'              => (string) Str::uuid(),
                'event_code'      => $eventCode,
                'user_id'         => null,
                'anon_id'         => $anonId,

                'scale_code'      => $extra['scale_code']    ?? null,
                'scale_version'   => $extra['scale_version'] ?? null,
                'attempt_id'      => $attemptId,

                'channel'         => $extra['channel']        ?? $request->input('channel', null),
                'region'          => $extra['region']         ?? $request->input('region', 'CN_MAINLAND'),
                'locale'          => $extra['locale']         ?? $request->input('locale', 'zh-CN'),

                'client_platform' => $extra['client_platform'] ?? $request->input('client_platform', null),
                'client_version'  => $extra['client_version']  ?? $request->input('client_version', null),

                'occurred_at'     => now(),
                'meta_json'       => $extra['meta_json'] ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::warning('event_log_failed', [
                'event_code' => $eventCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}