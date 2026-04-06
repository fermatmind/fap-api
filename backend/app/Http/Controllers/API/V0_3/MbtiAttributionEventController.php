<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\SchemaBaseline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class MbtiAttributionEventController extends Controller
{
    /**
     * @var list<string>
     */
    private const ALLOWED_EVENT_NAMES = [
        'landing_view',
        'start_click',
        'start_attempt',
        'view_result',
        'click_unlock',
        'create_order',
        'payment_confirmed',
        'purchase_success',
        'unlock_success',
        'invite_create_start',
        'invite_create_success',
        'invite_create_failed',
        'invite_share_or_copy',
        'invite_progress_advanced',
    ];

    public function store(Request $request): JsonResponse
    {
        $this->authorizeIngest($request);

        $data = $request->validate([
            'eventName' => ['required', 'string', 'max:64'],
            'payload' => ['nullable', 'array'],
            'anonymousId' => ['nullable', 'string', 'max:128'],
            'path' => ['nullable', 'string', 'max:2048'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $eventName = strtolower(trim((string) ($data['eventName'] ?? '')));
        if (! in_array($eventName, self::ALLOWED_EVENT_NAMES, true)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_EVENT_NAME',
                'message' => 'eventName is not supported by mbti attribution ingest.',
            ], 422);
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $path = $this->normalizeOptionalString($data['path'] ?? null, 2048) ?? '/';
        $anonymousId = $this->normalizeOptionalString($data['anonymousId'] ?? null, 128);
        $occurredAt = Carbon::parse((string) ($data['timestamp'] ?? now()->toISOString()));

        $attemptId = $this->normalizeOptionalString(
            $payload['attempt_id']
                ?? $payload['target_attempt_id']
                ?? null,
            64
        );

        $meta = [
            'entry_surface' => $this->normalizeOptionalString($payload['entry_surface'] ?? null, 128) ?? 'unknown',
            'source_page_type' => $this->normalizeOptionalString($payload['source_page_type'] ?? null, 64) ?? 'unknown',
            'target_action' => $this->normalizeOptionalString($payload['target_action'] ?? null, 128),
            'test_slug' => $this->normalizeOptionalString($payload['test_slug'] ?? null, 128),
            'form_code' => $this->normalizeOptionalString($payload['form_code'] ?? null, 64),
            'landing_path' => $this->normalizeOptionalString($payload['landing_path'] ?? null, 2048) ?? $path,
            'path' => $path,
            'anonymous_id' => $anonymousId,
            'target_attempt_id' => $this->normalizeOptionalString($payload['target_attempt_id'] ?? null, 64),
            'attempt_id' => $attemptId,
            'raw_payload' => $payload,
        ];

        $locale = $this->normalizeOptionalString($payload['locale'] ?? null, 16)
            ?? ($path !== '' && str_starts_with($path, '/zh') ? 'zh' : 'en');

        $orgId = $this->resolveOrgId($request);

        $attributes = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventName,
            'event_name' => $eventName,
            'org_id' => $orgId,
            'anon_id' => $anonymousId,
            'attempt_id' => $attemptId,
            'meta_json' => $meta,
            'occurred_at' => $occurredAt,
            'scale_code' => 'MBTI',
            'channel' => 'web',
            'locale' => $locale,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (SchemaBaseline::hasColumn('events', 'scale_code_v2')) {
            $attributes['scale_code_v2'] = 'MBTI';
        }

        Event::query()->create($attributes);

        return response()->json([
            'ok' => true,
            'event_code' => $eventName,
            'org_id' => $orgId,
        ], 202);
    }

    private function authorizeIngest(Request $request): void
    {
        $configuredToken = trim((string) config('fap.events.ingest_token', ''));
        if ($configuredToken === '') {
            return;
        }

        $provided = trim((string) ($request->bearerToken() ?? ''));
        if ($provided === '') {
            $provided = trim((string) $request->header('X-Track-Ingest-Token', ''));
        }

        if ($provided === '' || ! hash_equals($configuredToken, $provided)) {
            abort(response()->json([
                'ok' => false,
                'error_code' => 'unauthorized',
                'message' => 'Invalid ingest token.',
            ], 401));
        }
    }

    private function resolveOrgId(Request $request): int
    {
        $attr = $request->attributes->get('org_id');
        if (is_numeric($attr)) {
            return max(0, (int) $attr);
        }

        $header = trim((string) ($request->header('X-Org-Id') ?? ''));
        if ($header !== '' && preg_match('/^\d+$/', $header) === 1) {
            return (int) $header;
        }

        return 0;
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }
}
