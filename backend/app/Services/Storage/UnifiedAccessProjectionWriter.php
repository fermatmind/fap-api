<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\UnifiedAccessProjection;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class UnifiedAccessProjectionWriter
{
    public function __construct(
        private readonly AttemptReceiptRecorder $receipts,
    ) {}

    /**
     * @param  array<string,mixed>  $patch
     * @param  array<string,mixed>  $meta
     */
    public function refreshAttemptProjection(string $attemptId, array $patch = [], array $meta = []): ?UnifiedAccessProjection
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! $this->isEnabled() || ! SchemaBaseline::hasTable('unified_access_projections')) {
            return null;
        }

        $now = now();
        return DB::transaction(function () use ($attemptId, $patch, $meta, $now): UnifiedAccessProjection {
            $existing = UnifiedAccessProjection::query()
                ->where('attempt_id', $attemptId)
                ->first();

            $projection = UnifiedAccessProjection::query()->updateOrCreate(
                ['attempt_id' => $attemptId],
                [
                    'access_state' => $this->textOrExisting($patch, 'access_state', $existing?->access_state ?? 'locked'),
                    'report_state' => $this->textOrExisting($patch, 'report_state', $existing?->report_state ?? 'pending'),
                    'pdf_state' => $this->textOrExisting($patch, 'pdf_state', $existing?->pdf_state ?? 'missing'),
                    'reason_code' => $this->nullableTextOrExisting($patch, 'reason_code', $existing?->reason_code),
                    'projection_version' => 1,
                    'actions_json' => $this->arrayOrExisting($patch, 'actions_json', $existing?->actions_json),
                    'payload_json' => $this->arrayOrExisting($patch, 'payload_json', $existing?->payload_json),
                    'produced_at' => $existing?->produced_at ?? ($patch['produced_at'] ?? $now),
                    'refreshed_at' => $now,
                ]
            );

            $this->receipts->record(
                $attemptId,
                'access_projection_refreshed',
                [
                    'access_state' => $projection->access_state,
                    'report_state' => $projection->report_state,
                    'pdf_state' => $projection->pdf_state,
                    'reason_code' => $projection->reason_code,
                    'projection_version' => (int) $projection->projection_version,
                    'actions_json' => $projection->actions_json,
                    'payload_json' => $projection->payload_json,
                ],
                [
                    'source_system' => $this->normalizeText($meta, 'source_system', 'access_projection') ?? 'access_projection',
                    'source_ref' => $this->nullableTextOrExisting($meta, 'source_ref', null),
                    'actor_type' => $this->nullableTextOrExisting($meta, 'actor_type', null),
                    'actor_id' => $this->nullableTextOrExisting($meta, 'actor_id', null),
                    'idempotency_key' => hash('sha256', implode('|', [
                        $attemptId,
                        (string) ($projection->access_state ?? ''),
                        (string) ($projection->report_state ?? ''),
                        (string) ($projection->pdf_state ?? ''),
                        (string) ($projection->reason_code ?? ''),
                        (string) data_get($meta, 'source_ref', ''),
                        (string) data_get($meta, 'source_system', 'access_projection'),
                    ])),
                    'occurred_at' => $now,
                    'recorded_at' => $now,
                ]
            );

            return $projection->fresh() ?? $projection;
        });
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.receipt_ledger_dual_write_enabled', false)
            && (bool) config('storage_rollout.access_projection_dual_write_enabled', false);
    }

    private function textOrExisting(array $patch, string $key, ?string $fallback): ?string
    {
        if (array_key_exists($key, $patch)) {
            $value = trim((string) $patch[$key]);

            return $value !== '' ? $value : $fallback;
        }

        return $fallback;
    }

    private function nullableTextOrExisting(array $patch, string $key, ?string $fallback): ?string
    {
        if (! array_key_exists($key, $patch)) {
            return $fallback;
        }

        if (! is_string($patch[$key]) && ! is_numeric($patch[$key])) {
            return null;
        }

        $value = trim((string) $patch[$key]);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>|null
     */
    private function arrayOrExisting(array $patch, string $key, mixed $fallback): ?array
    {
        if (! array_key_exists($key, $patch)) {
            return is_array($fallback) ? $fallback : null;
        }

        return is_array($patch[$key]) ? $patch[$key] : null;
    }

    private function normalizeText(array $meta, string $key, string $fallback): string
    {
        $value = trim((string) ($meta[$key] ?? $fallback));

        return $value !== '' ? $value : $fallback;
    }
}
