<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use App\Models\Event;

trait WritesEvents
{
    /**
     * 内部方法：写 events 表
     * - result_view / report_view / share_view：强制 10s 去抖（anon_id + attempt_id + event_code）
     *   ✅ 去抖命中时允许回填/覆盖漏斗关键 meta（share_id/experiment/version/channel/...）
     * - share_generate/share_click：轻量去重（anon_id + attempt_id + share_style + page_session_id）
     */
    protected function logEvent(string $eventCode, Request $request, array $extra = []): void
    {
        try {
            $attemptId = $extra['attempt_id'] ?? null;

            // -----------------------------
            // Read headers (M3 funnel)
            // -----------------------------
            $hExperiment     = trim((string) ($request->header('X-Experiment') ?? ''));
            $hAppVersion     = trim((string) ($request->header('X-App-Version') ?? ''));
            $hChannel        = trim((string) ($request->header('X-Channel') ?? ''));
            $hClientPlatform = trim((string) ($request->header('X-Client-Platform') ?? ''));
            $hEntryPage      = trim((string) ($request->header('X-Entry-Page') ?? ''));

            // -----------------------------
            // anon_id source
            // -----------------------------
            $anonId = $extra['anon_id'] ?? $request->input('anon_id');

            // ------------------------------------------------------------
            // ✅ M3 hard: anon_id sanitize（堵住占位符/污染源）
            // - 空字符串 / 非字符串 => null
            // - 命中黑名单（包含匹配，大小写不敏感）=> null
            // ------------------------------------------------------------
            if (!is_string($anonId)) {
                $anonId = null;
            } else {
                $s = trim($anonId);
                if ($s === '') {
                    $anonId = null;
                } else {
                    $lower = mb_strtolower($s, 'UTF-8');
                    $blacklist = [
                        'todo',
                        'placeholder',
                        'fixme',
                        'tbd',
                        '把你查到的anon_id填这里',
                        '把你查到的 anon_id 填这里',
                        '填这里',
                    ];

                    foreach ($blacklist as $bad) {
                        $b = trim((string) $bad);
                        if ($b === '') continue;
                        if (mb_strpos($lower, mb_strtolower($b, 'UTF-8')) !== false) {
                            $anonId = null;
                            break;
                        }
                    }
                }
            }

            // -----------------------------
            // Build incoming meta (array)
            // -----------------------------
            $incoming = $extra['meta_json'] ?? [];
            if (!is_array($incoming)) {
                $incoming = json_decode((string) $incoming, true) ?: [];
            }
            if (!is_array($incoming)) $incoming = [];

            // ✅ 把 share_id 也做一层兜底：query > header > incoming(已有)
            $qShareId = trim((string) ($request->query('share_id') ?? ''));
            $hShareId = trim((string) ($request->header('X-Share-Id') ?? ''));
            if ((!isset($incoming['share_id']) || $incoming['share_id'] === null || $incoming['share_id'] === '') && $qShareId !== '') {
                $incoming['share_id'] = $qShareId;
            }
            if ((!isset($incoming['share_id']) || $incoming['share_id'] === null || $incoming['share_id'] === '') && $hShareId !== '') {
                $incoming['share_id'] = $hShareId;
            }

            // ✅ 确保 view 类事件 meta 也能拿到 header 值（如果 controller 没写）
            //（只补 incoming，不强行覆盖 controller 已写的）
            $fillIncoming = function (string $k, string $v) use (&$incoming): void {
                $oldEmpty = !isset($incoming[$k]) || $incoming[$k] === null || $incoming[$k] === '';
                if ($oldEmpty && $v !== '') $incoming[$k] = $v;
            };

            $fillIncoming('experiment', $hExperiment);
            $fillIncoming('version', $hAppVersion);
            $fillIncoming('channel', $hChannel);
            $fillIncoming('client_platform', $hClientPlatform);
            $fillIncoming('entry_page', $hEntryPage);

            // -----------------------------
// A) 10s debounce + backfill (result_view / report_view / share_view)
// ✅ 注意：去抖只作用于“同 event_code”，不能让 result_view 吃掉 share_view/report_view
// -----------------------------
if (in_array($eventCode, ['result_view', 'report_view', 'share_view'], true) && $anonId && $attemptId) {
    $existing = Event::query()
        ->where('event_code', $eventCode) // ✅ 关键修复：用当前 eventCode
        ->where('anon_id', $anonId)
        ->where('attempt_id', $attemptId)
        ->where('occurred_at', '>=', now()->subSeconds(10))
        ->orderByDesc('occurred_at')
        ->first();

    if ($existing) {
        // old meta normalize to array
        $old = $existing->meta_json;
        if (!is_array($old)) {
            $old = json_decode((string) $old, true) ?: [];
        }
        if (!is_array($old)) $old = [];

        $changed = false;

        // ✅ 漏斗关键字段：incoming 非空就覆盖（允许更完整 header 回填）
        $overwriteKeys = [
            'share_id',
            'experiment',
            'version',
            'channel',
            'client_platform',
            'entry_page',
            'page',
        ];

        foreach ($overwriteKeys as $k) {
            $newVal  = $incoming[$k] ?? null;
            $newGood = $newVal !== null && $newVal !== '';
            if ($newGood) {
                if (!isset($old[$k]) || $old[$k] !== $newVal) {
                    $old[$k] = $newVal;
                    $changed = true;
                }
            }
        }

        // ✅ one-shot：只补空
        $oneShot = ['type_code', 'engine_version', 'engine', 'content_package_version'];
        foreach ($oneShot as $k) {
            $oldEmpty = !isset($old[$k]) || $old[$k] === null || $old[$k] === '';
            $newVal   = $incoming[$k] ?? null;
            $newGood  = $newVal !== null && $newVal !== '';
            if ($oldEmpty && $newGood) {
                $old[$k] = $newVal;
                $changed = true;
            }
        }

        // ✅ 同步覆盖 events 表列（脚本可能查列）
        $colChanged = false;

        $inChannel = (string)($incoming['channel'] ?? '');
        if ($inChannel !== '' && (string)($existing->channel ?? '') !== $inChannel) {
            $existing->channel = $inChannel;
            $colChanged = true;
        }

        $inPlatform = (string)($incoming['client_platform'] ?? '');
        if ($inPlatform !== '' && (string)($existing->client_platform ?? '') !== $inPlatform) {
            $existing->client_platform = $inPlatform;
            $colChanged = true;
        }

        $inVer = (string)($incoming['version'] ?? '');
        if ($inVer !== '' && (string)($existing->client_version ?? '') !== $inVer) {
            $existing->client_version = $inVer;
            $colChanged = true;
        }

        if ($changed) {
            $existing->meta_json = $old;
        }
        if ($changed || $colChanged) {
            $existing->save();
        }

        return; // ✅ 仍然去抖：不新增事件
    }
}

            // -----------------------------
            // B) share_generate / share_click dedupe
            // -----------------------------
            if (in_array($eventCode, ['share_generate', 'share_click'], true) && $anonId && $attemptId) {
                $meta          = $incoming;
                $shareStyle    = $meta['share_style'] ?? null;
                $pageSessionId = $meta['page_session_id'] ?? null;

                if ($shareStyle && $pageSessionId) {
                    $dup = Event::query()
                        ->where('event_code', $eventCode)
                        ->where('anon_id', $anonId)
                        ->where('attempt_id', $attemptId)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.share_style')) = ?", [$shareStyle])
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.page_session_id')) = ?", [$pageSessionId])
                        ->exists();

                    if ($dup) {
                        return;
                    }
                }
            }

            // -----------------------------
            // Final: create event
            // -----------------------------
            Event::create([
                'id'              => (string) Str::uuid(),
                'event_code'      => $eventCode,
                'user_id'         => null,
                'anon_id'         => $anonId,

                'scale_code'      => $extra['scale_code']    ?? null,
                'scale_version'   => $extra['scale_version'] ?? null,
                'attempt_id'      => $attemptId,

                // ✅ 列值：extra > header > input
                'channel'         => $extra['channel']
                    ?? ($hChannel !== '' ? $hChannel : $request->input('channel', null)),

                'region'          => $extra['region']         ?? $request->input('region', 'CN_MAINLAND'),
                'locale'          => $extra['locale']         ?? $request->input('locale', 'zh-CN'),

                'client_platform' => $extra['client_platform']
                    ?? ($hClientPlatform !== '' ? $hClientPlatform : $request->input('client_platform', null)),

                // ✅ client_version：extra > header(X-App-Version) > input
                'client_version'  => $extra['client_version']
                    ?? ($hAppVersion !== '' ? $hAppVersion : $request->input('client_version', null)),

                'occurred_at'     => now(),
                'meta_json'       => $incoming, // ✅ 用归一化 + header兜底后的 meta
            ]);
        } catch (\Throwable $e) {
            Log::warning('event_log_failed', [
                'event_code' => $eventCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}