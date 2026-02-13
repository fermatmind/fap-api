<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Analytics\EventPayloadLimiter;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Analytics\EventNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventController extends Controller
{
    private function requireFmToken(Request $request): string
    {
        $token = trim((string) $request->bearerToken());

        if ($token === '') {
            abort(response()->json([
                'ok' => false,
                'error_code' => 'unauthorized',
                'message' => 'Missing Authorization Bearer token.',
            ], 401));
        }

        $ingestToken = trim((string) config('fap.events.ingest_token', ''));

        if ($ingestToken !== '') {
            if (!hash_equals($ingestToken, $token)) {
                abort(response()->json([
                    'ok' => false,
                    'error_code' => 'unauthorized',
                    'message' => 'Invalid ingest token.',
                ], 401));
            }

            return $token;
        }

        $exists = DB::table('fm_tokens')->where('token', $token)->exists();
        if (!$exists) {
            abort(response()->json([
                'ok' => false,
                'error_code' => 'unauthorized',
                'message' => 'Token not found.',
            ], 401));
        }

        return $token;
    }

    public function store(Request $request)
    {
        $maxPayloadBytes = max(0, (int) config('fap.events.max_payload_bytes', 131072));
        if ($maxPayloadBytes > 0) {
            $contentLengthHeader = $request->server('CONTENT_LENGTH');
            $contentLength = is_numeric($contentLengthHeader) ? (int) $contentLengthHeader : null;
            $rawContent = (string) $request->getContent();
            $rawBytes = strlen($rawContent);

            if (($contentLength !== null && $contentLength > $maxPayloadBytes) || $rawBytes > $maxPayloadBytes) {
                Log::warning('EVENT_INGEST_PAYLOAD_TOO_LARGE', [
                    'path' => $request->path(),
                    'content_length' => $contentLength,
                    'raw_bytes' => $rawBytes,
                    'max_payload_bytes' => $maxPayloadBytes,
                ]);

                return response()->json([
                    'ok' => false,
                    'error_code' => 'payload_too_large',
                ], 413);
            }
        }

        // ✅ 先鉴权（没有 token 直接 401）
        $this->requireFmToken($request);

        $maxTopKeys = max(0, (int) config('fap.events.max_top_keys', 200));
        $data = $request->validate([
            'event_name'  => ['nullable', 'string', 'max:64'],
            'event_code'  => ['required', 'string', 'max:64'],
            'anon_id'     => ['nullable', 'string', 'max:128'],
            'attempt_id'  => ['required', 'uuid'],
            'occurred_at' => ['nullable', 'date'],

            // ✅ 关键：顶层 share_id（验收脚本 F 会按 events.share_id 查）
            'share_id'    => ['nullable', 'uuid'],

            // ✅ 兼容两种入参：props / meta_json
            'props'       => ['nullable', 'array', "max:{$maxTopKeys}"],
            'props_json'  => ['nullable', 'array', "max:{$maxTopKeys}"],
            'meta_json'   => ['nullable', 'array', "max:{$maxTopKeys}"],
            'experiments_json' => ['nullable'],
        ]);

        // ✅ meta_json：props + meta_json 合并（meta_json 覆盖同名字段）
        $props = $data['props'] ?? $data['props_json'] ?? [];
        $meta  = $data['meta_json'] ?? [];
        $limiter = app(EventPayloadLimiter::class);
        $props = $limiter->limit($props);
        $meta = $limiter->limit($meta);
        $mergedMeta = array_merge($props, $meta);
        if (empty($mergedMeta)) {
            $mergedMeta = null;
        } else {
            if (is_array($mergedMeta)) {
                $mergedMeta['_auth'] = $mergedMeta['_auth'] ?? 'bearer';
            }
        }

        // ✅ 关键：share_id 兼容两种写法
        // 1) 顶层 share_id
        // 2) meta_json.share_id / props.share_id（合并后在 mergedMeta 里）
        $shareId = null;

        if (!empty($data['share_id'])) {
            $shareId = (string) $data['share_id'];
        } elseif (is_array($mergedMeta) && !empty($mergedMeta['share_id'])) {
            $shareId = (string) $mergedMeta['share_id'];
        }

        // 基本 sanity（避免垃圾）
        if ($shareId !== null && strlen($shareId) > 64) {
            $shareId = null;
        }

        $payload = [
            'event_name' => $data['event_name'] ?? null,
            'event_code' => $data['event_code'],
            'anon_id' => $data['anon_id'] ?? null,
            'attempt_id' => $data['attempt_id'],
            'occurred_at' => $data['occurred_at'] ?? null,
            'share_id' => $shareId,
            'props' => $props,
            'meta_json' => $meta,
        ];

        $context = [
            'request_id' => $request->header('X-Request-Id') ?? $request->header('X-Request-ID'),
            'session_id' => $request->header('X-Session-Id') ?? $request->header('X-Session-ID'),
            'anon_id' => $request->attributes->get('anon_id'),
            'user_id' => $request->attributes->get('fm_user_id'),
        ];

        $normalized = EventNormalizer::normalize($payload, $context);
        $columns = $normalized['columns'];
        $columns['id'] = method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::uuid();
        $columns['event_code'] = $data['event_code'];
        if (!isset($columns['event_name']) || $columns['event_name'] === null || $columns['event_name'] === '') {
            $columns['event_name'] = $data['event_name'] ?? $data['event_code'];
        }
        $columns['attempt_id'] = $columns['attempt_id'] ?? $data['attempt_id'];
        $columns['anon_id'] = $columns['anon_id'] ?? ($data['anon_id'] ?? null);
        $columns['share_id'] = $columns['share_id'] ?? $shareId;
        $columns['meta_json'] = $normalized['props'];
        $columns['occurred_at'] = $columns['occurred_at'] ?? (!empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now());

        $orgId = $this->resolveOrgId($request);
        $anonId = $columns['anon_id'] ?? $data['anon_id'] ?? null;
        $anonId = is_string($anonId) || is_numeric($anonId) ? trim((string) $anonId) : null;
        $userId = $request->attributes->get('fm_user_id');
        if (!is_numeric($userId)) {
            $userId = $request->attributes->get('user_id');
        }
        $userId = is_numeric($userId) ? (int) $userId : null;

        $bootExperiments = $this->extractBootExperiments($request, $data);
        $assigner = app(ExperimentAssigner::class);
        $assignments = $assigner->assignActive($orgId, $anonId, $userId);
        $columns['experiments_json'] = $assigner->mergeExperiments($bootExperiments, $assignments);

        $event = Event::create($columns);

        return response()->json([
            'ok' => true,
            'id' => $event->id,
        ], 201);
    }

    private function resolveOrgId(Request $request): int
    {
        $raw = trim((string) ($request->header('X-Org-Id') ?? ''));
        if ($raw !== '' && preg_match('/^\\d+$/', $raw)) {
            return (int) $raw;
        }

        return 0;
    }

    private function extractBootExperiments(Request $request, array $data): array
    {
        $parsed = $this->normalizeExperiments($data['experiments_json'] ?? null);
        if ($parsed !== []) {
            return $parsed;
        }

        $header = (string) $request->header('X-Experiments-Json', '');
        $parsed = $this->normalizeExperiments($header);
        if ($parsed !== []) {
            return $parsed;
        }

        $header = (string) $request->header('X-Boot-Experiments', '');
        return $this->normalizeExperiments($header);
    }

    private function normalizeExperiments($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
