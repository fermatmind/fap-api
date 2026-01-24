<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\Event;

trait WritesEvents
{
    /**
     * Write into events table.
     *
     * - result_view / report_view / share_view: 10s debounce (anon_id + attempt_id + event_code)
     *   - allow backfill funnel meta (share_id/experiment/version/channel/...)
     *   - backfill events.share_id + events.user_id + channel/client_platform/client_version columns
     * - share_generate/share_click: light dedupe (anon_id + attempt_id + share_style + page_session_id)
     */
    protected function logEvent(string $eventCode, Request $request, array $extra = []): void
    {
        try {
            $attemptId = $extra['attempt_id'] ?? null;

            // -----------------------------
            // user_id from middleware
            // supports: fm_user_id / user_id
            // only accept numeric id
            // -----------------------------
            $uidRaw = trim((string) $request->attributes->get('fm_user_id', ''));
            if ($uidRaw === '') {
                $uidRaw = trim((string) $request->attributes->get('user_id', ''));
            }
            $fmUserId = (ctype_digit($uidRaw) && (int) $uidRaw > 0) ? (int) $uidRaw : null;

            // -----------------------------
            // headers (funnel)
            // -----------------------------
            $hExperiment     = trim((string) $request->header('X-Experiment', ''));
            $hAppVersion     = trim((string) $request->header('X-App-Version', ''));
            $hChannel        = trim((string) $request->header('X-Channel', ''));
            $hClientPlatform = trim((string) $request->header('X-Client-Platform', ''));
            $hEntryPage      = trim((string) $request->header('X-Entry-Page', ''));
            $hShareId        = trim((string) $request->header('X-Share-Id', ''));

            // -----------------------------
            // anon_id source: extra > request attr > input
            // -----------------------------
            $anonId = $extra['anon_id']
                ?? $request->attributes->get('anon_id')
                ?? $request->input('anon_id');

            // sanitize anon_id
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
            // incoming meta (array)
            // -----------------------------
            $incoming = $extra['meta_json'] ?? [];
            if (!is_array($incoming)) {
                $incoming = json_decode((string) $incoming, true) ?: [];
            }
            if (!is_array($incoming)) $incoming = [];

            // -----------------------------
            // share_id unify
            // -----------------------------
            $qShareId = trim((string) ($request->query('share_id') ?? ''));

            $resolvedShareId = null;
            if (!empty($extra['share_id'])) {
                $resolvedShareId = trim((string) $extra['share_id']);
            } elseif (!empty($incoming['share_id'])) {
                $resolvedShareId = trim((string) $incoming['share_id']);
            } elseif ($qShareId !== '') {
                $resolvedShareId = $qShareId;
            } elseif ($hShareId !== '') {
                $resolvedShareId = $hShareId;
            }

            if (
                (!isset($incoming['share_id']) || $incoming['share_id'] === null || $incoming['share_id'] === '')
                && is_string($resolvedShareId) && $resolvedShareId !== ''
            ) {
                $incoming['share_id'] = $resolvedShareId;
            }

            // fill meta from headers (only if empty)
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
            // normalize columns (avoid NULL columns while meta has values)
            // Priority: extra > attr > incoming > header > input
            // -----------------------------
            $colChannel = (string) ($extra['channel'] ?? '');
            if ($colChannel === '') $colChannel = trim((string) $request->attributes->get('channel', ''));
            if ($colChannel === '') $colChannel = (string) ($incoming['channel'] ?? '');
            if ($colChannel === '') $colChannel = $hChannel;
            if ($colChannel === '') $colChannel = trim((string) $request->input('channel', ''));

            $colPlatform = (string) ($extra['client_platform'] ?? '');
            if ($colPlatform === '') $colPlatform = trim((string) $request->attributes->get('client_platform', ''));
            if ($colPlatform === '') $colPlatform = (string) ($incoming['client_platform'] ?? '');
            if ($colPlatform === '') $colPlatform = $hClientPlatform;
            if ($colPlatform === '') $colPlatform = trim((string) $request->input('client_platform', ''));

            $colVersion = (string) ($extra['client_version'] ?? '');
            if ($colVersion === '') $colVersion = trim((string) $request->attributes->get('client_version', ''));
            if ($colVersion === '') $colVersion = (string) ($incoming['version'] ?? '');
            if ($colVersion === '') $colVersion = $hAppVersion;
            if ($colVersion === '') $colVersion = trim((string) $request->input('client_version', ''));

            // -----------------------------
            // A) debounce + backfill (view events)
            // -----------------------------
            if (in_array($eventCode, ['result_view', 'report_view', 'share_view'], true) && $anonId && $attemptId) {
                $existing = Event::query()
                    ->where('event_code', $eventCode)
                    ->where('anon_id', $anonId)
                    ->where('attempt_id', $attemptId)
                    ->where('occurred_at', '>=', now()->subSeconds(10))
                    ->orderByDesc('occurred_at')
                    ->first();

                if ($existing) {
                    $old = $existing->meta_json;
                    if (!is_array($old)) {
                        $old = json_decode((string) $old, true) ?: [];
                    }
                    if (!is_array($old)) $old = [];

                    $changed = false;

                    // overwrite keys when incoming non-empty
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
                        $newVal = $incoming[$k] ?? null;
                        if ($newVal !== null && $newVal !== '') {
                            if (!isset($old[$k]) || $old[$k] !== $newVal) {
                                $old[$k] = $newVal;
                                $changed = true;
                            }
                        }
                    }

                    // one-shot fill
                    $oneShot = ['type_code', 'engine_version', 'engine', 'content_package_version'];
                    foreach ($oneShot as $k) {
                        $oldEmpty = !isset($old[$k]) || $old[$k] === null || $old[$k] === '';
                        $newVal   = $incoming[$k] ?? null;
                        if ($oldEmpty && $newVal !== null && $newVal !== '') {
                            $old[$k] = $newVal;
                            $changed = true;
                        }
                    }

                    $colChanged = false;

                    // backfill user_id
                    if ($fmUserId !== null && (int) ($existing->user_id ?? 0) !== $fmUserId) {
                        $existing->user_id = $fmUserId;
                        $colChanged = true;
                    }

                    // backfill columns
                    if ($colChannel !== '' && (string) ($existing->channel ?? '') !== $colChannel) {
                        $existing->channel = $colChannel;
                        $colChanged = true;
                    }
                    if ($colPlatform !== '' && (string) ($existing->client_platform ?? '') !== $colPlatform) {
                        $existing->client_platform = $colPlatform;
                        $colChanged = true;
                    }
                    if ($colVersion !== '' && (string) ($existing->client_version ?? '') !== $colVersion) {
                        $existing->client_version = $colVersion;
                        $colChanged = true;
                    }

                    // backfill share_id column
                    $inShareId = (string) ($incoming['share_id'] ?? '');
                    if ($inShareId !== '' && (string) ($existing->share_id ?? '') !== $inShareId) {
                        $existing->share_id = $inShareId;
                        $colChanged = true;
                    }

                    if ($changed) {
                        $existing->meta_json = $old;
                    }
                    if ($changed || $colChanged) {
                        $existing->save();
                    }

                    return;
                }
            }

            // -----------------------------
            // B) share_generate / share_click dedupe
            // -----------------------------
            if (in_array($eventCode, ['share_generate', 'share_click'], true) && $anonId && $attemptId) {
                $shareStyle    = $incoming['share_style'] ?? null;
                $pageSessionId = $incoming['page_session_id'] ?? null;

                if ($shareStyle && $pageSessionId) {
                    $dup = Event::query()
                        ->where('event_code', $eventCode)
                        ->where('anon_id', $anonId)
                        ->where('attempt_id', $attemptId)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.share_style')) = ?", [$shareStyle])
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.page_session_id')) = ?", [$pageSessionId])
                        ->exists();

                    if ($dup) return;
                }
            }

            // -----------------------------
            // Final: create event
            // -----------------------------
            Event::create([
                'id'              => (string) Str::uuid(),
                'event_code'      => $eventCode,
                'user_id'         => $fmUserId,
                'anon_id'         => $anonId,

                'scale_code'      => $extra['scale_code']    ?? null,
                'scale_version'   => $extra['scale_version'] ?? null,
                'attempt_id'      => $attemptId,

                'share_id'        => (is_string($resolvedShareId) && $resolvedShareId !== '') ? $resolvedShareId : null,

                'channel'         => $colChannel !== '' ? $colChannel : null,
                'region'          => $extra['region'] ?? $request->input('region', 'CN_MAINLAND'),
                'locale'          => $extra['locale'] ?? $request->input('locale', 'zh-CN'),

                'client_platform' => $colPlatform !== '' ? $colPlatform : null,
                'client_version'  => $colVersion !== '' ? $colVersion : null,

                'occurred_at'     => now(),
                'meta_json'       => $incoming,
            ]);
        } catch (\Throwable $e) {
            Log::warning('event_log_failed', [
                'event_code' => $eventCode,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}