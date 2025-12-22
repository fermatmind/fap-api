<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    public function store(Request $request)
    {
        // ✅ 手动 Validator：永远 JSON 返回（避免没 Accept 时 302 redirect）
        $v = Validator::make($request->all(), [
            'event_code'  => ['required', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],

            // ✅ attempt_id 允许不传（当 meta_json.share_id 存在时可反查）
            'attempt_id'  => ['nullable', 'string', 'max:64'],

            'occurred_at' => ['nullable', 'date'],
            'meta_json'   => ['nullable', 'array'],

            // ✅ AB / 版本：允许 body 直接传（也支持 header）
            'experiment'  => ['nullable', 'string', 'max:64'],
            'version'     => ['nullable', 'string', 'max:64'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'error'  => 'VALIDATION_FAILED',
                'errors' => $v->errors(),
            ], 422);
        }

        $data = $v->validated();

        // ---------- meta_json 合并 ----------
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];

        // 优先：header，其次：body 字段
        $experiment = (string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?? ($data['version'] ?? ''));

        // ---------- attempt_id 允许为空：用 share_id 反查 share_generate ----------
        $attemptId = (string) ($data['attempt_id'] ?? '');

        $shareId = '';
        if (isset($meta['share_id']) && is_string($meta['share_id'])) {
            $shareId = trim($meta['share_id']);
        }

        $gen = null;
        if ($attemptId === '' && $shareId !== '') {
            $gen = Event::where('event_code', 'share_generate')
                ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(meta_json, "$.share_id")) = ?', [$shareId])
                ->orderByDesc('occurred_at')
                ->first();

            if ($gen) {
                $attemptId = (string) ($gen->attempt_id ?? '');
            }
        }

        // inherit exp/version from share_generate if still empty
        if ($gen && $experiment === '') $experiment = (string) (($gen->meta_json['experiment'] ?? '') ?: '');
        if ($gen && $version === '')    $version    = (string) (($gen->meta_json['version'] ?? '') ?: '');

        // attempt_id 仍为空：无法落库（你也可以改成允许空 attempt_id，但漏斗会断）
        if ($attemptId === '') {
            return response()->json([
                'ok'      => false,
                'error'   => 'ATTEMPT_ID_REQUIRED',
                'message' => 'attempt_id is required unless meta_json.share_id can resolve it.',
            ], 422);
        }

        // ---------- meta_json 填充：experiment/version + 通用字段 ----------
        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // 清理空值
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // ---------- 写入事件 ----------
        $event = new Event();

        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = $data['event_code'];
        $event->anon_id     = $data['anon_id'] ?? null;
        $event->attempt_id  = $attemptId;
        $event->meta_json   = $meta;

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