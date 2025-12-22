<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /**
     * POST /api/v0.2/events
     * 通用事件上报：自动合并 experiment/version/ip/ua/ref
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'event_code'  => ['required', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'attempt_id'  => ['required', 'string', 'max:64'],

            // 可选：客户端传事件发生时间（ISO8601 / "2025-12-17 10:01:28" 都行）
            'occurred_at' => ['nullable', 'date'],

            'meta_json'   => ['nullable', 'array'],

            // ✅ AB / 版本：允许 body 直接传（也支持 header）
            'experiment'  => ['nullable', 'string', 'max:64'],
            'version'     => ['nullable', 'string', 'max:64'],
        ]);

        // ---------- meta_json 合并：把 experiment/version 全链路塞进去 ----------
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];

        // ✅ 优先 header，其次 body
        $experiment = (string) ($request->header('X-Experiment') ?: ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?: ($data['version'] ?? ''));

        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // ✅ 通用字段（不覆盖客户端 meta 里已有的）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // ✅ 清理空值（不删 0 / false）
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // ---------- 写入事件 ----------
        $event = new Event();

        // ✅ 保险：自己生成 UUID
        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = $data['event_code'];
        $event->anon_id     = $data['anon_id'] ?? null;
        $event->attempt_id  = $data['attempt_id'];

        // ✅ meta_json：始终保存数组（空数组也可以）
        $event->meta_json   = $meta;

        // ✅ 不传就用服务端当前时间，避免 occurred_at NOT NULL 报错
        $event->occurred_at = !empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now();

        $event->save();

        return response()->json([
            'ok' => true,
            'id' => $event->id,
        ]);
    }

    /**
     * POST /api/v0.2/shares/{share_id}/click
     * share_click 闭环：分享落地页被打开/被点击时调用
     *
     * body:
     *  - attempt_id (required)
     *  - anon_id (optional)
     *  - occurred_at (optional)
     *  - meta_json (optional) 例如 { "channel":"wx", "page":"share_landing" }
     *  - experiment/version (optional, 同 store 规则)
     *
     * header:
     *  - X-Experiment / X-App-Version（可选）
     */
    public function shareClick(Request $request, string $share_id)
    {
        $data = $request->validate([
            'attempt_id'  => ['required', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],

            'meta_json'   => ['nullable', 'array'],
            'experiment'  => ['nullable', 'string', 'max:64'],
            'version'     => ['nullable', 'string', 'max:64'],
        ]);

        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];

        // ✅ 强制写入 share_id（避免前端忘传）
        if (!isset($meta['share_id'])) $meta['share_id'] = $share_id;

        // ✅ 默认字段（不覆盖已有）
        if (!isset($meta['channel'])) $meta['channel'] = 'unknown';
        if (!isset($meta['page']))    $meta['page']    = 'share_landing';

        // ✅ experiment/version：优先 header，其次 body
        $experiment = (string) ($request->header('X-Experiment') ?: ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?: ($data['version'] ?? ''));

        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // ✅ 通用字段（不覆盖已有）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        $event = new Event();

        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code = 'share_click';
        $event->anon_id    = $data['anon_id'] ?? null;
        $event->attempt_id = $data['attempt_id'];
        $event->meta_json  = $meta;

        $event->occurred_at = !empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now();

        $event->save();

        return response()->json([
            'ok' => true,
            'id' => $event->id,
        ]);
    }
}