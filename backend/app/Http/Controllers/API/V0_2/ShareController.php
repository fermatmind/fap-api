<?php

namespace App\Http\Controllers\Api\V0_2;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ShareController extends Controller
{
    /**
     * POST /api/v0.2/shares/{shareId}/click
     *
     * 目标：
     * 1) 记录 share_click 事件
     * 2) meta_json 必带 share_id/channel/page/ua/ip
     * 3) experiment/version：优先 header，其次 body，再否则从 share_generate 继承
     */
    public function click(Request $request, string $shareId)
    {
        $data = $request->validate([
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'attempt_id'  => ['required', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            'meta_json'   => ['nullable', 'array'],

            // 可选：body 传 experiment/version（也支持 header）
            'experiment'  => ['nullable', 'string', 'max:64'],
            'version'     => ['nullable', 'string', 'max:64'],
        ]);

        // ---------- 1) 组装 meta ----------
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];

        // 强制写入 share_id（不覆盖也无所谓，这里统一覆盖为 path 里的 shareId）
        $meta['share_id'] = $shareId;

        // 通用字段（不覆盖客户端已有）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // ---------- 2) experiment/version：header > body > inherit(share_generate) ----------
        $experiment = (string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?? ($data['version'] ?? ''));

        // 如果 header/body 都没给，则从 share_generate 继承
        if ($experiment === '' || $version === '') {
            $gen = Event::where('event_code', 'share_generate')
                ->whereRaw('JSON_EXTRACT(meta_json, "$.share_id") = ?', [$shareId])
                ->orderByDesc('occurred_at')
                ->first();

            if ($gen && is_array($gen->meta_json)) {
                if ($experiment === '') $experiment = (string) ($gen->meta_json['experiment'] ?? '');
                if ($version === '')    $version    = (string) ($gen->meta_json['version'] ?? '');
            }
        }

        // 写回 meta（不覆盖客户端已显式传入的 experiment/version）
        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // 清理空值（可选）
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // ---------- 3) 写入 Event ----------
        $event = new Event();
        $event->id = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();

        $event->event_code  = 'share_click';
        $event->anon_id     = $data['anon_id'] ?? null;
        $event->attempt_id  = $data['attempt_id'];
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