<?php

namespace App\Services\Analytics;

use App\Models\Event;
use App\Services\Experiments\ExperimentAssigner;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EventRecorder
{
    public function __construct(
        private ExperimentAssigner $experimentAssigner,
        private ?SensitiveDataRedactor $redactor = null,
        private ?Big5EventSchema $big5EventSchema = null,
    ) {
    }

    public function record(string $eventCode, ?int $userId, array $meta = [], array $context = []): void
    {
        if (!\App\Support\SchemaBaseline::hasTable('events')) {
            return;
        }

        $meta = $this->validateBigFiveEventMeta($eventCode, $meta);
        if ($meta === null) {
            return;
        }

        $meta = $this->sanitizeMetaForStorage($eventCode, $meta);

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

        if (\App\Support\SchemaBaseline::hasColumn('events', 'experiments_json')) {
            $experiments = $context['experiments_json'] ?? [];
            $payload['experiments_json'] = is_array($experiments) ? $experiments : [];
        }

        try {
            Event::create($payload);
        } catch (\Throwable $e) {
            Log::warning('EVENT_RECORDER_WRITE_FAILED', [
                'event_code' => $eventCode,
                'org_id' => (int) ($payload['org_id'] ?? 0),
                'request_id' => $payload['request_id'] ?? null,
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }

    public function recordFromRequest(Request $request, string $eventCode, ?int $userId, array $meta = []): void
    {
        $context = $this->contextFromRequest($request);
        $bootExperiments = $this->extractBootExperiments($request);
        $assignments = $this->experimentAssigner->assignActive(
            $context['org_id'] ?? 0,
            $context['anon_id'] ?? null,
            $userId,
        );
        $context['experiments_json'] = $this->experimentAssigner->mergeExperiments($bootExperiments, $assignments);
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
        $anonId = $request->attributes->get('anon_id');
        if (!is_string($anonId) && !is_numeric($anonId)) {
            $anonId = $request->input('anon_id', $request->header('X-Anon-Id'));
        }
        $anonId = is_string($anonId) || is_numeric($anonId) ? trim((string) $anonId) : '';

        return [
            'org_id' => $orgId,
            'request_id' => $requestId !== '' ? $requestId : null,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'anon_id' => $anonId !== '' ? $anonId : null,
            'channel' => $channel !== '' ? $channel : null,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
        ];
    }

    private function extractBootExperiments(Request $request): array
    {
        $payload = $request->input('experiments_json');
        $parsed = $this->normalizeExperiments($payload);
        if ($parsed !== []) {
            return $parsed;
        }

        $payload = $request->input('boot_experiments');
        $parsed = $this->normalizeExperiments($payload);
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

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function sanitizeMetaForStorage(string $eventCode, array $meta): array
    {
        $normalizedCode = strtolower(trim($eventCode));
        $needsRedaction = str_starts_with($normalizedCode, 'big5_')
            || str_starts_with($normalizedCode, 'clinical_combo_68_');
        if (!$needsRedaction) {
            return $meta;
        }

        $redactor = $this->redactor instanceof SensitiveDataRedactor
            ? $this->redactor
            : new SensitiveDataRedactor();
        $result = $redactor->redactWithMeta($meta);
        $sanitized = is_array($result['data'] ?? null) ? $result['data'] : $meta;
        $count = (int) ($result['count'] ?? 0);
        if ($count > 0) {
            $sanitized['_redaction'] = [
                'count' => $count,
                'version' => (string) ($result['version'] ?? 'v2'),
                'scope' => str_starts_with($normalizedCode, 'big5_')
                    ? 'big5_event_meta'
                    : 'clinical_event_meta',
            ];
        }

        return $sanitized;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>|null
     */
    private function validateBigFiveEventMeta(string $eventCode, array $meta): ?array
    {
        $normalizedCode = strtolower(trim($eventCode));
        if (!str_starts_with($normalizedCode, 'big5_')) {
            return $meta;
        }

        $schema = $this->big5EventSchema instanceof Big5EventSchema
            ? $this->big5EventSchema
            : new Big5EventSchema();

        try {
            return $schema->validate($eventCode, $meta);
        } catch (\InvalidArgumentException $e) {
            Log::warning('BIG5_EVENT_SCHEMA_INVALID', [
                'event_code' => $eventCode,
                'message' => $e->getMessage(),
                'meta_keys' => array_values(array_map('strval', array_keys($meta))),
            ]);

            return null;
        }
    }
}
