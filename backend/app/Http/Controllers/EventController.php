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
        ]);

        $event = new Event();

        // ✅ 保险：自己生成 UUID（如果你模型已经自动生成，也不影响）
        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = $data['event_code'];
        $event->anon_id     = $data['anon_id'] ?? null;
        $event->attempt_id  = $data['attempt_id'];
        $event->meta_json   = $data['meta_json'] ?? null;

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