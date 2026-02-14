<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Models\Event;
use App\Services\Analytics\EventPayloadLimiter;
use App\Services\Auth\FmTokenService;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Analytics\EventNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventController extends Controller
{
    use RespondsWithNotFound;

    /**
     * @return array{auth_mode:string,user_id:?int,anon_id:?string,org_id:int,role:string}
     */
    private function requireFmToken(Request $request): array
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

            return [
                'auth_mode' => 'ingest_token',
                'user_id' => null,
                'anon_id' => null,
                'org_id' => 0,
                'role' => 'system',
            ];
        }

        $validated = app(FmTokenService::class)->validateToken($token);
        if (!($validated['ok'] ?? false)) {
            abort(response()->json([
                'ok' => false,
                'error_code' => 'unauthorized',
                'message' => 'Token not found.',
            ], 401));
        }

        $userId = null;
        $rawUserId = trim((string) ($validated['user_id'] ?? ''));
        if ($rawUserId !== '' && preg_match('/^\d+$/', $rawUserId) === 1) {
            $userId = (int) $rawUserId;
            $request->attributes->set('fm_user_id', $rawUserId);
            $request->attributes->set('user_id', $rawUserId);
        }

        $anonId = trim((string) ($validated['anon_id'] ?? ''));
        if ($anonId !== '') {
            $request->attributes->set('anon_id', $anonId);
            $request->attributes->set('fm_anon_id', $anonId);
        } else {
            $anonId = null;
        }

        $orgId = is_numeric($validated['org_id'] ?? null) ? (int) $validated['org_id'] : 0;
        if ($orgId > 0) {
            $request->attributes->set('fm_org_id', $orgId);
            $request->attributes->set('org_id', $orgId);
        }

        $role = trim((string) ($validated['role'] ?? 'public'));
        if ($role === '') {
            $role = 'public';
        }
        $request->attributes->set('org_role', $role);

        return [
            'auth_mode' => 'fm_token',
            'user_id' => $userId,
            'anon_id' => $anonId,
            'org_id' => $orgId,
            'role' => $role,
        ];
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
        $auth = $this->requireFmToken($request);

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

        if (!$this->canWriteAttemptEvent($payload['attempt_id'], $auth, $request)) {
            return $this->notFoundResponse('attempt not found.');
        }

        $context = [
            'request_id' => $request->header('X-Request-Id') ?? $request->header('X-Request-ID'),
            'session_id' => $request->header('X-Session-Id') ?? $request->header('X-Session-ID'),
            'anon_id' => $auth['anon_id'],
            'user_id' => $auth['user_id'],
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
        if (($auth['auth_mode'] ?? '') === 'fm_token') {
            $columns['anon_id'] = $auth['anon_id'];
        } else {
            $columns['anon_id'] = $columns['anon_id'] ?? ($data['anon_id'] ?? null);
        }
        $columns['share_id'] = $columns['share_id'] ?? $shareId;
        $columns['meta_json'] = $normalized['props'];
        $columns['user_id'] = $auth['user_id'];
        $columns['occurred_at'] = $columns['occurred_at'] ?? (!empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now());

        $orgId = $this->resolveOrgId($request, $auth);
        $columns['org_id'] = $orgId;
        $anonId = $columns['anon_id'] ?? $data['anon_id'] ?? null;
        $anonId = is_string($anonId) || is_numeric($anonId) ? trim((string) $anonId) : null;
        $userId = $auth['user_id'];

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

    private function resolveOrgId(Request $request, array $auth): int
    {
        $authMode = (string) ($auth['auth_mode'] ?? '');
        $tokenOrgId = is_numeric($auth['org_id'] ?? null) ? (int) $auth['org_id'] : 0;
        if ($authMode === 'fm_token' && $tokenOrgId > 0) {
            return $tokenOrgId;
        }

        $attrOrgId = $request->attributes->get('org_id');
        if (is_numeric($attrOrgId) && (int) $attrOrgId >= 0) {
            return (int) $attrOrgId;
        }

        $raw = trim((string) ($request->header('X-Org-Id') ?? ''));
        if ($raw !== '' && preg_match('/^\\d+$/', $raw)) {
            return (int) $raw;
        }

        return 0;
    }

    private function canWriteAttemptEvent(string $attemptId, array $auth, Request $request): bool
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || !\App\Support\SchemaBaseline::hasTable('attempts')) {
            return true;
        }

        if (!DB::table('attempts')->where('id', $attemptId)->exists()) {
            return true;
        }

        $query = DB::table('attempts')->where('id', $attemptId);

        if (\App\Support\SchemaBaseline::hasColumn('attempts', 'org_id')) {
            $query->where('org_id', $this->resolveOrgId($request, $auth));
        }

        $authMode = (string) ($auth['auth_mode'] ?? '');
        $userId = is_numeric($auth['user_id'] ?? null) ? (string) (int) $auth['user_id'] : '';
        $anonId = is_string($auth['anon_id'] ?? null) ? trim((string) $auth['anon_id']) : '';
        if ($authMode === 'fm_token') {
            $hasIdentityConstraint = false;

            if ($userId !== '' && \App\Support\SchemaBaseline::hasColumn('attempts', 'user_id')) {
                $query->where('user_id', $userId);
                $hasIdentityConstraint = true;
            } elseif ($anonId !== '' && \App\Support\SchemaBaseline::hasColumn('attempts', 'anon_id')) {
                $query->where('anon_id', $anonId);
                $hasIdentityConstraint = true;
            }

            if (!$hasIdentityConstraint) {
                $query->whereRaw('1=0');
            }
        }

        return $query->exists();
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
