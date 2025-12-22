<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class ShareController extends Controller
{
    /**
     * POST /api/v0.2/shares/{shareId}/click
     * - attempt_id 可不传：会从 share_generate 反查
     * - experiment/version：优先 header/body，其次继承 share_generate.meta_json
     * - 永远返回 JSON（不再 302）
     */
    public function click(Request $request, string $shareId)
    {
        // 1) 手动 Validator：确保永远 JSON 返回（避免 ValidationException 走 302）
        $v = Validator::make($request->all(), [
            'attempt_id'  => ['nullable', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
            'meta_json'   => ['nullable', 'array'],

            // AB / 版本：允许 body 传（也支持 header）
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

        // 2) meta 合并（先拿客户端 meta_json）
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];
        if (!isset($meta['share_id'])) $meta['share_id'] = $shareId;

        // 3) 取 experiment/version：优先 header，其次 body
        $experiment = (string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?? ($data['version'] ?? ''));

        // 4) attempt_id 允许不传：用 share_generate 反查 attempt_id，同时可继承 experiment/version
        $attemptId = (string) ($data['attempt_id'] ?? '');

        $gen = Event::where('event_code', 'share_generate')
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(meta_json, "$.share_id")) = ?', [$shareId])
            ->orderByDesc('occurred_at')
            ->first();

        if ($attemptId === '' && $gen) {
            $attemptId = (string) ($gen->attempt_id ?? '');
        }

        // inherit exp/version from share_generate if still empty
        if ($gen && $experiment === '') {
            $experiment = (string) (($gen->meta_json['experiment'] ?? '') ?: '');
        }
        if ($gen && $version === '') {
            $version = (string) (($gen->meta_json['version'] ?? '') ?: '');
        }

        // attempt_id 仍然没有：说明 share_id 找不到对应 generate，无法闭环
        if ($attemptId === '') {
            return response()->json([
                'ok'     => false,
                'error'  => 'SHARE_NOT_FOUND',
                'message'=> 'No share_generate found for this share_id, cannot resolve attempt_id.',
            ], 404);
        }

        // 5) 把 experiment/version 写入 meta（不覆盖客户端已有）
        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // 通用字段（不覆盖客户端 meta）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // 清理空值
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // 6) 写 event
        $event = new Event();
        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = 'share_click';
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