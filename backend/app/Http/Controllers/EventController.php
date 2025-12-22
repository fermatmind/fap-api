<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventController extends Controller
{
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

        // 优先：header，其次：body 字段
        $experiment = (string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?? ($data['version'] ?? ''));

        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // 通用字段（不覆盖客户端 meta 里已有的）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // 清理空值（可选，但建议做）
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // ---------- 写入事件 ----------
        $event = new Event();

        // ✅ 保险：自己生成 UUID（如果你模型已经自动生成，也不影响）
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
}