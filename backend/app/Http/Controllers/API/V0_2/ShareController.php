<?php

namespace App\Http\Controllers\Api\V0_2;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    /**
     * POST /api/v0.2/shares/{shareId}/click
     * 用于分享落地页打开/点击分享链接时，打 share_click 事件
     */
    public function click(Request $request, string $shareId)
    {
        // ✅ 强烈建议：share 链接里带 attempt_id，这样 share_click 能闭环到 attempt
        // 例如：/share?share_id=xxx&attempt_id=yyy
        $attemptId = (string) ($request->input('attempt_id') ?? $request->query('attempt_id') ?? '');

        // experiment/version：允许前端从 header 或 body 传
        $experiment = (string) ($request->header('X-Experiment') ?? $request->input('experiment') ?? '');
        $version    = (string) ($request->header('X-App-Version') ?? $request->input('version') ?? '');

        $meta = array_filter([
            'share_id'   => $shareId,
            'scene'      => (string) ($request->input('scene') ?? ''), // 可选：landing/open/click
            'ref'        => (string) ($request->input('ref') ?? $request->header('Referer') ?? ''),
            'ua'         => (string) ($request->userAgent() ?? ''),
            'ip'         => (string) ($request->ip() ?? ''),
            'experiment' => $experiment ?: null,
            'version'    => $version ?: null,
        ], fn($v) => $v !== null && $v !== '');

        Event::create([
            'event_code'  => 'share_click',
            'anon_id'     => (string) ($request->input('anon_id') ?? ''),
            'attempt_id'  => $attemptId !== '' ? $attemptId : null,
            'occurred_at' => now(),
            'meta_json'   => $meta,
        ]);

        return response()->json(['ok' => true]);
    }
}