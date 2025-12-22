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
     * 目标：落地页点击分享链接时记录 share_click，并确保 meta_json 里有 share_id + (experiment/version 尽量全链路可用)
     */
    public function click(Request $request, string $shareId)
    {
        $data = $request->validate([
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'attempt_id'  => ['required', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
            'meta_json'   => ['nullable', 'array'],

            // 允许 body 传（也支持 header）
            'experiment'  => ['nullable', 'string', 'max:64'],
            'version'     => ['nullable', 'string', 'max:64'],
        ]);

        $attemptId = (string) $data['attempt_id'];

        // -------- meta 合并 --------
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];

        // 强制写入 share_id（以 path 为准）
        if (!isset($meta['share_id']) || !is_string($meta['share_id']) || trim($meta['share_id']) === '') {
            $meta['share_id'] = $shareId;
        }

        // 1) 先拿 header/body
        $experiment = (string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? ''));
        $version    = (string) ($request->header('X-App-Version') ?? ($data['version'] ?? ''));

        // 2) 如果还没有 experiment/version：从 share_generate 继承（同 share_id）
        if ($experiment === '' || $version === '') {
            $recentGen = Event::where('event_code', 'share_generate')
                ->where('attempt_id', $attemptId)
                ->whereRaw('JSON_EXTRACT(meta_json, "$.share_id") = ?', [$shareId])
                ->orderByDesc('occurred_at')
                ->first();

            if ($recentGen && is_array($recentGen->meta_json)) {
                if ($experiment === '') {
                    $experiment = (string) ($recentGen->meta_json['experiment'] ?? '');
                }
                if ($version === '') {
                    $version = (string) ($recentGen->meta_json['version'] ?? '');
                }
            }
        }

        // 3) 落到 meta（不覆盖客户端已有值）
        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // 通用字段（不覆盖已有）
        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // 清理空值（可选）
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // -------- 写事件 --------
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