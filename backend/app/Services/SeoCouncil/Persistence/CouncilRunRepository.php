<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CouncilRunRepository
{
    public function enabled(): bool
    {
        return ! app()->environment('production')
            && (bool) config('seo_council.mission_persistence_enabled', false)
            && Schema::connection($this->connectionName())->hasTable('seo_council_runs');
    }

    /** @return null|array<string, mixed> */
    public function findByIdempotencyKey(string $key): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $row = $this->connection()->table('seo_council_runs')->where('idempotency_key', $key)->first();
        if (! is_object($row)) {
            return null;
        }
        $receipt = json_decode((string) $row->receipt_json, true);

        return is_array($receipt) ? $receipt : null;
    }

    public function resumeValid(string $receiptHash, string $stepHash): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $run = $this->connection()->table('seo_council_runs')
            ->where('receipt_hash', $receiptHash)
            ->where('status', 'RESUMABLE')
            ->first();
        if (! is_object($run)) {
            return false;
        }

        return $this->connection()->table('seo_council_run_steps')
            ->where('run_id', (string) $run->run_id)
            ->where('step_hash', $stepHash)
            ->exists();
    }

    /** @param array<string, mixed> $receipt */
    public function persist(array $receipt, string $idempotencyKey): void
    {
        if (! $this->enabled()) {
            return;
        }
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
                'receipt_json' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
                    'step_json' => json_encode($step, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
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
                    'conflict_json' => json_encode($conflict, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                ]);
            }
        });
    }

    private function connectionName(): string
    {
        return (string) config('seo_council.connection', 'seo_intel');
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }
}
