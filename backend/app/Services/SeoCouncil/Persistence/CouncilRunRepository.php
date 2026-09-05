<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CouncilRunRepository
{
    public function enabled(): bool
    {
        return $this->gatesOpen() && $this->storageReady();
    }

    /** @return null|array<string, mixed> */
    public function findByIdempotencyKey(string $key): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->findStoredReceipt($key);
    }

    /**
     * @param  array<string, mixed>  $resume
     * @param  array<string, string>  $currentBindings
     */
    public function resumeValid(array $resume, array $currentBindings): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        foreach (['catalog_hash', 'policy_hash', 'binding_hash', 'evidence_hash', 'capability_hash'] as $field) {
            if (! isset($resume[$field], $currentBindings[$field])
                || ! is_string($resume[$field])
                || ! is_string($currentBindings[$field])
                || ! hash_equals($resume[$field], $currentBindings[$field])) {
                return false;
            }
        }

        $run = $this->connection()->table('seo_council_runs')
            ->where('receipt_hash', (string) ($resume['receipt_hash'] ?? ''))
            ->where('status', 'RESUMABLE')
            ->first();
        if (! is_object($run)) {
            return false;
        }
        $receipt = $this->decode((string) $run->receipt_json);
        $receiptRow = $this->connection()->table('seo_council_run_receipts')
            ->where('run_id', (string) $run->run_id)
            ->where('receipt_hash', (string) ($resume['receipt_hash'] ?? ''))
            ->first();
        if (! is_array($receipt)
            || ! is_object($receiptRow)
            || ! hash_equals((string) ($receipt['catalog_ref']['hash'] ?? ''), $resume['catalog_hash'])
            || ! hash_equals((string) ($receipt['policy_ref']['hash'] ?? ''), $resume['policy_hash'])
            || ! hash_equals((string) ($receipt['binding_ref']['hash'] ?? ''), $resume['binding_hash'])
            || ! hash_equals((string) ($receipt['evidence_hash'] ?? ''), $resume['evidence_hash'])
            || ! hash_equals((string) ($receipt['capability_hash'] ?? ''), $resume['capability_hash'])) {
            return false;
        }

        $step = $this->connection()->table('seo_council_run_steps')
            ->where('run_id', (string) $run->run_id)
            ->where('step_hash', (string) ($resume['step_hash'] ?? ''))
            ->first();
        if (! is_object($step)) {
            return false;
        }
        $stepPayload = $this->decode((string) $step->step_json);

        return is_array($stepPayload)
            && hash_equals((string) ($stepPayload['catalog_hash'] ?? ''), $resume['catalog_hash'])
            && hash_equals((string) ($stepPayload['policy_revision']['hash'] ?? ''), $resume['policy_hash'])
            && hash_equals((string) ($stepPayload['binding_hash'] ?? ''), $resume['binding_hash'])
            && hash_equals((string) ($stepPayload['evidence_hash'] ?? ''), $resume['evidence_hash'])
            && hash_equals((string) ($stepPayload['capability_hash'] ?? ''), $resume['capability_hash']);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{decision:string, receipt:array<string, mixed>}
     */
    public function persist(array $receipt, string $idempotencyKey): array
    {
        if (! $this->gatesOpen()) {
            return ['decision' => 'DISABLED', 'receipt' => $receipt];
        }
        if (! $this->storageReady()) {
            return ['decision' => 'PERSISTENCE_HOLD', 'receipt' => $receipt];
        }

        try {
            $this->connection()->transaction(function () use ($receipt, $idempotencyKey): void {
                $now = now();
                $this->connection()->table('seo_council_runs')->insert([
                    'run_id' => $receipt['run_id'],
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $receipt['request_hash'],
                    'registry_hash' => $receipt['registry_ref']['hash'],
                    'binding_hash' => $receipt['binding_ref']['hash'],
                    'evidence_hash' => $receipt['evidence_hash'],
                    'policy_version' => $receipt['policy_ref']['version'],
                    'policy_hash' => $receipt['policy_ref']['hash'],
                    'status' => $receipt['status'],
                    'stop_reason' => $receipt['stop_reason'],
                    'receipt_version' => $receipt['receipt_version'],
                    'receipt_hash' => $receipt['receipt_hash'],
                    'supersedes_receipt_hash' => $receipt['supersedes_receipt_hash'],
                    'receipt_json' => $this->encode($receipt),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                foreach ($receipt['steps'] as $step) {
                    $this->connection()->table('seo_council_run_steps')->insert([
                        'step_id' => $step['step_id'],
                        'run_id' => $receipt['run_id'],
                        'sequence' => $step['sequence'],
                        'step_type' => $step['step_type'],
                        'status' => $step['status'],
                        'stop_reason' => $step['stop_reason'],
                        'step_hash' => $step['step_hash'],
                        'step_json' => $this->encode($step),
                        'created_at' => $now,
                    ]);
                }
                foreach ($receipt['conflicts'] as $conflict) {
                    $this->connection()->table('seo_council_conflicts')->insert([
                        'conflict_id' => $conflict['conflict_id'],
                        'run_id' => $receipt['run_id'],
                        'status' => $conflict['status'],
                        'human_decision_required' => $conflict['human_decision_required'],
                        'conflict_hash' => $conflict['conflict_hash'],
                        'conflict_json' => $this->encode($conflict),
                        'created_at' => $now,
                    ]);
                }
                $this->connection()->table('seo_council_run_receipts')->insert([
                    'receipt_id' => $receipt['receipt_id'],
                    'receipt_hash' => $receipt['receipt_hash'],
                    'run_id' => $receipt['run_id'],
                    'receipt_version' => $receipt['receipt_version'],
                    'request_hash' => $receipt['request_hash'],
                    'catalog_hash' => $receipt['catalog_ref']['hash'],
                    'policy_hash' => $receipt['policy_ref']['hash'],
                    'binding_hash' => $receipt['binding_ref']['hash'],
                    'evidence_hash' => $receipt['evidence_hash'],
                    'capability_hash' => $receipt['capability_hash'],
                    'supersedes_receipt_hash' => $receipt['supersedes_receipt_hash'],
                    'receipt_json' => $this->encode($receipt),
                    'created_at' => $now,
                ]);
            });

            return ['decision' => 'PERSISTED', 'receipt' => $receipt];
        } catch (Throwable) {
            $existing = $this->findStoredReceipt($idempotencyKey);
            if (! is_array($existing)) {
                return ['decision' => 'PERSISTENCE_HOLD', 'receipt' => $receipt];
            }

            return [
                'decision' => hash_equals((string) ($existing['request_hash'] ?? ''), (string) $receipt['request_hash'])
                    ? 'REPLAY'
                    : 'IDEMPOTENCY_CONFLICT',
                'receipt' => $existing,
            ];
        }
    }

    /** @return null|array<string, mixed> */
    private function findStoredReceipt(string $idempotencyKey): ?array
    {
        try {
            $row = $this->connection()->table('seo_council_runs')
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (! is_object($row)) {
                return null;
            }
            $receiptRow = $this->connection()->table('seo_council_run_receipts')
                ->where('run_id', (string) $row->run_id)
                ->where('receipt_hash', (string) $row->receipt_hash)
                ->first();
            if (! is_object($receiptRow)) {
                return null;
            }
            $runReceipt = $this->decode((string) $row->receipt_json);
            $immutableReceipt = $this->decode((string) $receiptRow->receipt_json);
        } catch (Throwable) {
            return null;
        }

        return is_array($runReceipt) && is_array($immutableReceipt) && $runReceipt === $immutableReceipt
            ? $runReceipt
            : null;
    }

    /** @return null|array<string, mixed> */
    private function decode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function connectionName(): string
    {
        return (string) config('seo_council.connection', 'seo_intel');
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    private function gatesOpen(): bool
    {
        return (bool) config('seo_council.mission_persistence_enabled', false)
            && (string) config('seo_council.mission_persistence_runtime_state', 'DISABLED') === 'ACTIVE';
    }

    private function storageReady(): bool
    {
        try {
            return \App\Support\SchemaBaseline::tableExists('seo_council_runs', $this->connectionName())
                && \App\Support\SchemaBaseline::tableExists('seo_council_run_receipts', $this->connectionName());
        } catch (Throwable) {
            return false;
        }
    }
}
