<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\AttemptReceipt;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class AttemptReceiptRecorder
{
    public function record(
        string $attemptId,
        string $receiptType,
        array $payload = [],
        array $meta = []
    ): ?AttemptReceipt {
        $attemptId = trim($attemptId);
        $receiptType = trim($receiptType);
        if ($attemptId === '' || $receiptType === '' || ! $this->isEnabled()) {
            return null;
        }

        if (! SchemaBaseline::hasTable('attempt_receipts')) {
            return null;
        }

        $payloadJson = $this->encodePayload($payload);
        $sourceSystem = $this->normalizeText($meta['source_system'] ?? 'backend', 'backend');
        $sourceRef = $this->normalizeNullableText($meta['source_ref'] ?? null);
        $actorType = $this->normalizeNullableText($meta['actor_type'] ?? null);
        $actorId = $this->normalizeNullableText($meta['actor_id'] ?? null);
        $occurredAt = $meta['occurred_at'] ?? null;
        $recordedAt = $meta['recorded_at'] ?? now();
        $idempotencyKey = $this->normalizeNullableText($meta['idempotency_key'] ?? null)
            ?? hash('sha256', implode('|', [
                $attemptId,
                $receiptType,
                $sourceSystem,
                $sourceRef ?? '',
                $payloadJson,
            ]));

        $work = function () use (
            $attemptId,
            $receiptType,
            $payload,
            $sourceSystem,
            $sourceRef,
            $actorType,
            $actorId,
            $occurredAt,
            $recordedAt,
            $idempotencyKey
        ): AttemptReceipt {
            if (SchemaBaseline::hasTable('attempts')) {
                DB::table('attempts')
                    ->where('id', $attemptId)
                    ->lockForUpdate()
                    ->first();
            }

            $existing = AttemptReceipt::query()
                ->where('attempt_id', $attemptId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof AttemptReceipt) {
                return $existing;
            }

            $nextSeq = (int) (
                AttemptReceipt::query()
                    ->where('attempt_id', $attemptId)
                    ->lockForUpdate()
                    ->max('seq')
                ?? 0
            ) + 1;

            $receipt = AttemptReceipt::query()->create([
                'attempt_id' => $attemptId,
                'seq' => $nextSeq,
                'receipt_type' => $receiptType,
                'source_system' => $sourceSystem,
                'source_ref' => $sourceRef,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'payload_json' => $payload,
                'occurred_at' => $occurredAt ?? $recordedAt,
                'recorded_at' => $recordedAt,
            ]);

            return $receipt->fresh() ?? $receipt;
        };

        if (DB::transactionLevel() > 0) {
            return $work();
        }

        return DB::transaction($work);
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.receipt_ledger_dual_write_enabled', false);
    }

    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            throw new \RuntimeException('failed to encode attempt receipt payload.');
        }

        return $json;
    }

    private function normalizeText(mixed $value, string $fallback = ''): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
