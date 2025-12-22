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

            // ============ A) result_view 10s 去抖（强制，但允许回填 share_id） ============
if ($eventCode === 'result_view' && $anonId && $attemptId) {
    $existing = Event::query()
        ->where('event_code', 'result_view')
        ->where('anon_id', $anonId)
        ->where('attempt_id', $attemptId)
        ->where('occurred_at', '>=', now()->subSeconds(10))
        ->orderByDesc('occurred_at')
        ->first();

    if ($existing) {
        // 这次传入的 meta（来自 $extra['meta_json']）
        $incoming = $extra['meta_json'] ?? [];

        // 旧 meta（确保是数组）
        $old = $existing->meta_json;
        if (!is_array($old)) {
            $old = json_decode((string)$old, true) ?: [];
        }

        // ✅ 回填逻辑：旧的为空/没有，新来的有，就补上
        $keys = ['share_id', 'experiment', 'version', 'page'];
        $changed = false;

        foreach ($keys as $k) {
            $oldEmpty = !isset($old[$k]) || $old[$k] === null || $old[$k] === '';
            $newVal   = $incoming[$k] ?? null;
            $newGood  = $newVal !== null && $newVal !== '';

            if ($oldEmpty && $newGood) {
                $old[$k] = $newVal;
                $changed = true;
            }
        }

        // type_code 也允许补一次（可选，但很实用）
        if ((!isset($old['type_code']) || $old['type_code'] === null || $old['type_code'] === '')
            && !empty($incoming['type_code'])) {
            $old['type_code'] = $incoming['type_code'];
            $changed = true;
        }

        if ($changed) {
            $existing->meta_json = $old;
            $existing->save();
        }

        return; // ✅ 仍然去抖：不新增事件，只做必要回填
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