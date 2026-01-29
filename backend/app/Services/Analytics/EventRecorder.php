<?php

namespace App\Services\Analytics;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class EventRecorder
{
    public function record(string $eventCode, ?int $userId, array $meta = [], array $context = []): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        $now = now();
        $payload = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventCode,
            'event_name' => $eventCode,
            'org_id' => $context['org_id'] ?? 0,
            'user_id' => $userId,
            'anon_id' => $context['anon_id'] ?? null,
            'session_id' => $context['session_id'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'attempt_id' => $context['attempt_id'] ?? null,
            'channel' => $context['channel'] ?? null,
            'pack_id' => $context['pack_id'] ?? null,
            'dir_version' => $context['dir_version'] ?? null,
            'pack_semver' => $context['pack_semver'] ?? null,
            'meta_json' => $meta,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            Event::create($payload);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public function recordFromRequest(Request $request, string $eventCode, ?int $userId, array $meta = []): void
    {
        $context = $this->contextFromRequest($request);
        $this->record($eventCode, $userId, $meta, $context);
    }

    private function contextFromRequest(Request $request): array
    {
        $requestId = trim((string) ($request->header('X-Request-Id') ?? $request->header('X-Request-ID')));
        $sessionId = trim((string) ($request->header('X-Session-Id') ?? $request->header('X-Session-ID')));
        $channel = trim((string) $request->header('X-Channel', ''));
        $attemptId = (string) $request->input('attempt_id', '');
        $orgId = $request->attributes->get('org_id');
        $orgId = is_numeric($orgId) ? (int) $orgId : 0;

        return [
            'org_id' => $orgId,
            'request_id' => $requestId !== '' ? $requestId : null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'anon_id' => $request->attributes->get('anon_id'),
            'channel' => $channel !== '' ? $channel : null,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
        ];
    }
}
