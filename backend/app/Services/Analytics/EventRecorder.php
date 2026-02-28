<?php

namespace App\Services\Analytics;

use App\Models\Event;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Scale\ScaleIdentityRuntimePolicy;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class EventRecorder
{
    private const EXPOSURE_CONTRACT_KEY = '__exposure_contract__';

    public function __construct(
        private ExperimentAssigner $experimentAssigner,
        private ?SensitiveDataRedactor $redactor = null,
        private ?Big5EventSchema $big5EventSchema = null,
        private ?ScaleIdentityRuntimePolicy $runtimePolicy = null,
    ) {}

    public function record(string $eventCode, ?int $userId, array $meta = [], array $context = []): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('events')) {
            return;
        }

        $meta = $this->validateBigFiveEventMeta($eventCode, $meta);
        if ($meta === null) {
            return;
        }

        $meta = $this->sanitizeMetaForStorage($eventCode, $meta);
        $scaleCode = $this->resolveScaleIdentityString([
            $meta['scale_code'] ?? null,
            $context['scale_code'] ?? null,
        ], true);
        $scaleCodeV2 = $this->resolveScaleIdentityString([
            $meta['scale_code_v2'] ?? null,
            $context['scale_code_v2'] ?? null,
        ], true);
        $scaleUid = $this->resolveScaleIdentityString([
            $meta['scale_uid'] ?? null,
            $context['scale_uid'] ?? null,
        ], false);

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
            'scale_code' => $scaleCode,
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
            $orgId = is_numeric($payload['org_id'] ?? null) ? (int) $payload['org_id'] : 0;
            $anonId = is_string($payload['anon_id'] ?? null) || is_numeric($payload['anon_id'] ?? null)
                ? trim((string) $payload['anon_id'])
                : '';
            $payload['experiments_json'] = $this->standardizeExperimentsPayload(
                $context['experiments_json'] ?? [],
                [],
                $orgId,
                $anonId !== '' ? $anonId : null,
                $userId,
                $now
            );
        }
        if ($this->shouldWriteScaleIdentityColumns()) {
            $payload['scale_code_v2'] = $scaleCodeV2;
            $payload['scale_uid'] = $scaleUid;
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
        $bootContract = $this->extractBootExposureContract($request);
        $assignments = $this->experimentAssigner->assignActive(
            $context['org_id'] ?? 0,
            $context['anon_id'] ?? null,
            $userId,
        );
        $mergedExperiments = $this->experimentAssigner->mergeExperiments($bootExperiments, $assignments);
        $context['experiments_json'] = $this->standardizeExperimentsPayload(
            $mergedExperiments,
            $bootContract,
            (int) ($context['org_id'] ?? 0),
            is_string($context['anon_id'] ?? null) ? (string) $context['anon_id'] : null,
            $userId,
            now()
        );
        if (in_array($eventCode, ['result_view', 'report_view'], true)) {
            $meta = $this->appendExposureContractToMeta($meta, $context['experiments_json']);
        }
        $this->record($eventCode, $userId, $meta, $context);
    }

    private function contextFromRequest(Request $request): array
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId === '') {
            $requestId = trim((string) ($request->header('X-Request-Id') ?? $request->header('X-Request-ID')));
        }
        $sessionId = trim((string) ($request->header('X-Session-Id') ?? $request->header('X-Session-ID')));
        $channel = trim((string) $request->header('X-Channel', ''));
        $attemptId = (string) $request->input('attempt_id', '');
        $orgId = $request->attributes->get('org_id');
        $orgId = is_numeric($orgId) ? (int) $orgId : 0;
        $anonId = $request->attributes->get('anon_id');
        if (! is_string($anonId) && ! is_numeric($anonId)) {
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

    private function extractBootExposureContract(Request $request): array
    {
        $payload = $request->input('experiments_json');
        $parsed = $this->extractExposureContract($payload);
        if ($parsed !== []) {
            return $parsed;
        }

        $payload = $request->input('boot_experiments');
        $parsed = $this->extractExposureContract($payload);
        if ($parsed !== []) {
            return $parsed;
        }

        $header = (string) $request->header('X-Experiments-Json', '');
        $parsed = $this->extractExposureContract($header);
        if ($parsed !== []) {
            return $parsed;
        }

        $header = (string) $request->header('X-Boot-Experiments', '');

        return $this->extractExposureContract($header);
    }

    private function normalizeExperiments($value): array
    {
        $decoded = $this->decodeJsonArray($value);
        if ($decoded === []) {
            return [];
        }

        $normalized = [];

        $ingestEntry = function (array $entry) use (&$normalized): void {
            $experimentKey = trim((string) ($entry['experiment_key'] ?? ''));
            if ($experimentKey === '' && isset($entry['key'])) {
                $experimentKey = trim((string) $entry['key']);
            }
            if ($experimentKey === '' && isset($entry['name'])) {
                $experimentKey = trim((string) $entry['name']);
            }

            $variant = trim((string) ($entry['variant'] ?? ''));
            if ($experimentKey === '' || $variant === '') {
                return;
            }

            $normalized[$experimentKey] = $variant;
        };

        if (array_is_list($decoded)) {
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $ingestEntry($entry);
                }
            }

            return $normalized;
        }

        $contract = $decoded[self::EXPOSURE_CONTRACT_KEY] ?? null;
        if (is_array($contract)) {
            foreach ($contract as $entry) {
                if (is_array($entry)) {
                    $ingestEntry($entry);
                }
            }
        }

        foreach ($decoded as $key => $variant) {
            $experimentKey = trim((string) $key);
            if ($experimentKey === '' || str_starts_with($experimentKey, '__')) {
                continue;
            }

            if (is_string($variant) || is_numeric($variant)) {
                $variantValue = trim((string) $variant);
                if ($variantValue !== '') {
                    $normalized[$experimentKey] = $variantValue;
                }
                continue;
            }

            if (! is_array($variant)) {
                continue;
            }

            $variantValue = trim((string) ($variant['variant'] ?? ''));
            if ($variantValue !== '') {
                $normalized[$experimentKey] = $variantValue;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @param  array<string,mixed>  $experimentsPayload
     * @return array<string,mixed>
     */
    private function appendExposureContractToMeta(array $meta, array $experimentsPayload): array
    {
        $contract = $this->exposureContractRows($experimentsPayload);
        if ($contract === []) {
            return $meta;
        }

        $meta['experiment_exposure'] = $contract;
        $primary = $contract[0];
        foreach (['experiment_key', 'variant', 'version', 'stage', 'assigned_at'] as $field) {
            $current = trim((string) ($meta[$field] ?? ''));
            if ($current !== '') {
                continue;
            }
            $value = trim((string) ($primary[$field] ?? ''));
            if ($value !== '') {
                $meta[$field] = $value;
            }
        }

        return $meta;
    }

    /**
     * @param  array<string,mixed>  $bootContract
     * @return array<string,mixed>
     */
    private function standardizeExperimentsPayload(
        mixed $experiments,
        array $bootContract,
        int $orgId,
        ?string $anonId,
        ?int $userId,
        Carbon $fallbackAssignedAt,
    ): array {
        $variantMap = $this->normalizeExperiments($experiments);
        if ($variantMap === []) {
            return [];
        }

        $resolvedContract = $this->extractExposureContract($experiments);
        foreach ($bootContract as $experimentKey => $entry) {
            if (! is_string($experimentKey) || ! is_array($entry)) {
                continue;
            }
            if (! isset($resolvedContract[$experimentKey]) || ! is_array($resolvedContract[$experimentKey])) {
                $resolvedContract[$experimentKey] = $entry;
                continue;
            }
            $resolvedContract[$experimentKey] = array_merge($entry, $resolvedContract[$experimentKey]);
        }

        $metadata = $this->resolveExposureMetadata($orgId, $anonId, $userId, array_keys($variantMap));
        ksort($variantMap);
        $entries = [];
        foreach ($variantMap as $experimentKey => $variant) {
            $contractEntry = is_array($resolvedContract[$experimentKey] ?? null) ? $resolvedContract[$experimentKey] : [];
            $meta = is_array($metadata[$experimentKey] ?? null) ? $metadata[$experimentKey] : [];
            $version = $this->firstNonEmptyString([
                $contractEntry['version'] ?? null,
                $meta['version'] ?? null,
                config('fap_experiments.experiments.' . $experimentKey . '.version'),
                'v1',
            ]);
            $stage = $this->firstNonEmptyString([
                $contractEntry['stage'] ?? null,
                $meta['stage'] ?? null,
                config('fap_experiments.experiments.' . $experimentKey . '.stage'),
                'prod',
            ]);
            $assignedAt = $this->normalizeIsoTimestamp($contractEntry['assigned_at'] ?? null)
                ?? $this->normalizeIsoTimestamp($meta['assigned_at'] ?? null)
                ?? $fallbackAssignedAt->toIso8601String();

            $entries[] = [
                'experiment_key' => $experimentKey,
                'variant' => $variant,
                'version' => $version ?? 'v1',
                'stage' => $stage ?? 'prod',
                'assigned_at' => $assignedAt,
            ];
        }

        $payload = $variantMap;
        $payload[self::EXPOSURE_CONTRACT_KEY] = $entries;

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,array{experiment_key:string,variant:string,version:string,stage:string,assigned_at:string}>
     */
    private function exposureContractRows(array $payload): array
    {
        $rows = $payload[self::EXPOSURE_CONTRACT_KEY] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $experimentKey = trim((string) ($row['experiment_key'] ?? ''));
            $variant = trim((string) ($row['variant'] ?? ''));
            $version = trim((string) ($row['version'] ?? ''));
            $stage = trim((string) ($row['stage'] ?? ''));
            $assignedAt = trim((string) ($row['assigned_at'] ?? ''));
            if (
                $experimentKey === ''
                || $variant === ''
                || $version === ''
                || $stage === ''
                || $assignedAt === ''
            ) {
                continue;
            }

            $normalized[] = [
                'experiment_key' => $experimentKey,
                'variant' => $variant,
                'version' => $version,
                'stage' => $stage,
                'assigned_at' => $assignedAt,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string,array{version?:string,stage?:string,assigned_at?:string}>
     */
    private function extractExposureContract(mixed $value): array
    {
        $decoded = $this->decodeJsonArray($value);
        if ($decoded === []) {
            return [];
        }

        $contracts = [];
        $ingestEntry = function (array $entry, ?string $fallbackKey = null) use (&$contracts): void {
            $experimentKey = trim((string) ($entry['experiment_key'] ?? $fallbackKey ?? ''));
            if ($experimentKey === '') {
                return;
            }

            $record = [];
            $version = trim((string) ($entry['version'] ?? ''));
            if ($version !== '') {
                $record['version'] = $version;
            }
            $stage = trim((string) ($entry['stage'] ?? ''));
            if ($stage !== '') {
                $record['stage'] = $stage;
            }
            $assignedAt = $this->normalizeIsoTimestamp($entry['assigned_at'] ?? null);
            if ($assignedAt !== null) {
                $record['assigned_at'] = $assignedAt;
            }

            if ($record !== []) {
                $contracts[$experimentKey] = array_merge($contracts[$experimentKey] ?? [], $record);
            }
        };

        if (array_is_list($decoded)) {
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $ingestEntry($entry);
                }
            }

            return $contracts;
        }

        $contractRows = $decoded[self::EXPOSURE_CONTRACT_KEY] ?? null;
        if (is_array($contractRows)) {
            foreach ($contractRows as $entry) {
                if (is_array($entry)) {
                    $ingestEntry($entry);
                }
            }
        }

        foreach ($decoded as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $experimentKey = trim((string) $key);
            if ($experimentKey === '' || str_starts_with($experimentKey, '__')) {
                continue;
            }
            $ingestEntry($entry, $experimentKey);
        }

        return $contracts;
    }

    /**
     * @param  array<int,string>  $experimentKeys
     * @return array<string,array{version?:string,stage?:string,assigned_at?:string}>
     */
    private function resolveExposureMetadata(int $orgId, ?string $anonId, ?int $userId, array $experimentKeys): array
    {
        $experimentKeys = array_values(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $experimentKeys), static function (string $value): bool {
            return $value !== '';
        }));
        if ($experimentKeys === []) {
            return [];
        }

        $metadata = [];

        if (\App\Support\SchemaBaseline::hasTable('experiment_assignments')) {
            $assignmentRows = DB::table('experiment_assignments')
                ->where('org_id', $orgId)
                ->whereIn('experiment_key', $experimentKeys)
                ->when(
                    $userId !== null || ($anonId !== null && $anonId !== ''),
                    function ($query) use ($userId, $anonId): void {
                        $query->where(function ($inner) use ($userId, $anonId): void {
                            if ($userId !== null) {
                                $inner->orWhere('user_id', $userId);
                            }
                            if ($anonId !== null && $anonId !== '') {
                                $inner->orWhere('anon_id', $anonId);
                            }
                        });
                    }
                )
                ->orderByDesc('assigned_at')
                ->orderByDesc('id')
                ->get(['experiment_key', 'user_id', 'anon_id', 'assigned_at']);

            foreach ($assignmentRows as $row) {
                $experimentKey = trim((string) ($row->experiment_key ?? ''));
                if ($experimentKey === '' || isset($metadata[$experimentKey]['assigned_at'])) {
                    continue;
                }

                $timestamp = $this->normalizeIsoTimestamp($row->assigned_at ?? null);
                if ($timestamp !== null) {
                    $metadata[$experimentKey]['assigned_at'] = $timestamp;
                }
            }
        }

        if (\App\Support\SchemaBaseline::hasTable('experiments_registry')) {
            $now = now();
            $registryRows = DB::table('experiments_registry')
                ->where('org_id', $orgId)
                ->where('is_active', true)
                ->whereIn('experiment_key', $experimentKeys)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('active_from')->orWhere('active_from', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('active_to')->orWhere('active_to', '>', $now);
                })
                ->orderByDesc('active_from')
                ->orderByDesc('id')
                ->get(['experiment_key', 'version', 'stage']);

            foreach ($registryRows as $row) {
                $experimentKey = trim((string) ($row->experiment_key ?? ''));
                if ($experimentKey === '' || isset($metadata[$experimentKey]['version'])) {
                    continue;
                }

                $version = trim((string) ($row->version ?? ''));
                if ($version !== '') {
                    $metadata[$experimentKey]['version'] = $version;
                }

                $stage = trim((string) ($row->stage ?? ''));
                if ($stage !== '') {
                    $metadata[$experimentKey]['stage'] = $stage;
                }
            }
        }

        return $metadata;
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function normalizeIsoTimestamp(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value) && ! $value instanceof \DateTimeInterface) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function sanitizeMetaForStorage(string $eventCode, array $meta): array
    {
        $normalizedCode = strtolower(trim($eventCode));
        $redactor = $this->redactor instanceof SensitiveDataRedactor
            ? $this->redactor
            : new SensitiveDataRedactor;
        $result = $redactor->redactWithMeta($meta);
        $sanitized = is_array($result['data'] ?? null) ? $result['data'] : $meta;
        $count = (int) ($result['count'] ?? 0);
        if ($count > 0) {
            $sanitized['_redaction'] = [
                'count' => $count,
                'version' => (string) ($result['version'] ?? 'v2'),
                'scope' => $this->redactionScopeForEvent($normalizedCode),
            ];
        }

        return $sanitized;
    }

    private function redactionScopeForEvent(string $normalizedCode): string
    {
        if (str_starts_with($normalizedCode, 'big5_')) {
            return 'big5_event_meta';
        }

        if (str_starts_with($normalizedCode, 'sds_')) {
            return 'sds_event_meta';
        }

        if (str_starts_with($normalizedCode, 'clinical_combo_68_')) {
            return 'clinical_event_meta';
        }

        return 'event_meta';
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        return $this->resolveRuntimePolicy()->shouldWriteScaleIdentityColumns();
    }

    private function resolveRuntimePolicy(): ScaleIdentityRuntimePolicy
    {
        if ($this->runtimePolicy instanceof ScaleIdentityRuntimePolicy) {
            return $this->runtimePolicy;
        }

        return app(ScaleIdentityRuntimePolicy::class);
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function resolveScaleIdentityString(array $values, bool $uppercase): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $normalized = trim((string) $value);
            if ($normalized === '') {
                continue;
            }

            return $uppercase ? strtoupper($normalized) : $normalized;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>|null
     */
    private function validateBigFiveEventMeta(string $eventCode, array $meta): ?array
    {
        $normalizedCode = strtolower(trim($eventCode));
        if (! str_starts_with($normalizedCode, 'big5_')) {
            return $meta;
        }

        $schema = $this->big5EventSchema instanceof Big5EventSchema
            ? $this->big5EventSchema
            : new Big5EventSchema;

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
