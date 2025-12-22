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

        $shareId = trim((string)$shareId);

        // ---------- meta ----------
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];
        $meta['share_id'] = $shareId;

        if (!isset($meta['ua']))  $meta['ua']  = (string) ($request->userAgent() ?? '');
        if (!isset($meta['ip']))  $meta['ip']  = (string) ($request->ip() ?? '');
        if (!isset($meta['ref'])) $meta['ref'] = (string) ($request->header('Referer') ?? '');

        // ---------- experiment/version：header > body > inherit(share_generate) ----------
        $experiment = trim((string) ($request->header('X-Experiment') ?? ($data['experiment'] ?? '')));
        $version    = trim((string) ($request->header('X-App-Version') ?? ($data['version'] ?? '')));

        if ($experiment === '' || $version === '') {
            $gen = Event::where('event_code', 'share_generate')
                ->where('attempt_id', $data['attempt_id'])
                ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(meta_json, "$.share_id")) = ?', [$shareId])
                ->orderByDesc('occurred_at')
                ->first();

            if ($gen && is_array($gen->meta_json)) {
                if ($experiment === '') $experiment = trim((string)($gen->meta_json['experiment'] ?? ''));
                if ($version === '')    $version    = trim((string)($gen->meta_json['version'] ?? ''));
            }
        }

        if ($experiment !== '' && !isset($meta['experiment'])) $meta['experiment'] = $experiment;
        if ($version !== '' && !isset($meta['version']))       $meta['version'] = $version;

        // 去掉空值（可选）
        $meta = array_filter($meta, fn($v) => !($v === null || $v === ''));

        // ---------- save ----------
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