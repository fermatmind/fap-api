<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /**
     * ✅ C-2：events 开始校验 fm_token（Authorization: Bearer fm_xxx）
     * 先做最小版：有 Bearer token 且格式合法才允许写入。
     * （后续你可以在这里接上“查库验证 token 是否存在/未过期”。）
     */
    private function requireFmToken(Request $request): string
    {
        $token = (string) $request->bearerToken();

        if ($token === '') {
            abort(response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Missing Authorization Bearer token.',
            ], 401));
        }

        // ✅ 你现在的 token 形态：fm_<uuid>
        // 例：fm_c76c882a-e0ba-4868-8f7d-1916b3c60844
        if (!preg_match('/^fm_[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $token)) {
            abort(response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Invalid token format.',
            ], 401));
        }

        return $token;
    }

    public function store(Request $request)
    {
        // ✅ 先鉴权（没有 token 直接 401）
        $token = $this->requireFmToken($request);

        $data = $request->validate([
            'event_code'  => ['required', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],

            // ✅ 事件 attempt_id 你现在用 UUID（更严一点）
            'attempt_id'  => ['required', 'uuid'],

            'occurred_at' => ['nullable', 'date'],

            // ✅ 兼容两种入参：props / meta_json
            'props'       => ['nullable', 'array'],
            'meta_json'   => ['nullable', 'array'],
        ]);

        // ✅ meta_json：props + meta_json 合并（meta_json 覆盖同名字段）
        $props = $data['props'] ?? [];
        $meta  = $data['meta_json'] ?? [];
        $mergedMeta = array_merge($props, $meta);
        if (empty($mergedMeta)) {
            $mergedMeta = null;
        } else {
            // 可选：把 token 是否存在记一下（不建议存明文 token）
            // 只存一个标记，避免泄露
            if (is_array($mergedMeta)) {
                $mergedMeta['_auth'] = $mergedMeta['_auth'] ?? 'bearer';
            }
        }

        $event = new Event();

        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = $data['event_code'];
        $event->anon_id     = $data['anon_id'] ?? null;
        $event->attempt_id  = $data['attempt_id'];
        $event->meta_json   = $mergedMeta;

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