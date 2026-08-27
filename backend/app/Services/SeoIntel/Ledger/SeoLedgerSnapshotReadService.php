<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Ledger;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SeoLedgerSnapshotReadService
{
    public const CONTRACT_VERSION = 'seo.change_ledger.snapshot.v1';

    public function __construct(
        private readonly string $connection = 'seo_intel',
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        try {
            if (! Schema::connection($this->connection)->hasTable('seo_change_ledgers')) {
                return $this->emptySnapshot($page, $perPage);
            }

            $query = DB::connection($this->connection)
                ->table('seo_change_ledgers')
                ->orderByDesc('updated_at')
                ->orderBy('ledger_id');
            $total = (int) (clone $query)->count();
            $rows = $query->forPage($page, $perPage)->get();

            return [
                'state' => $total === 0 ? 'verified_zero' : 'production_proven',
                'items' => $rows->map(fn (object $row): array => $this->present($row))->values()->all(),
                'pagination' => $this->pagination($page, $perPage, $total),
                'empty' => $total === 0,
                'read_only' => true,
            ];
        } catch (Throwable) {
            return [
                'state' => 'unavailable',
                'items' => [],
                'pagination' => $this->pagination($page, $perPage, 0),
                'empty' => false,
                'read_only' => true,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'ledger_id' => (string) $row->ledger_id,
            'change_type' => (string) $row->change_type,
            'hypothesis' => (string) $row->hypothesis,
            'rationale' => (string) $row->rationale,
            'scope' => [
                'page_family' => $this->nullableString($row->page_family),
                'locale' => $this->nullableString($row->locale),
                'public_url_count' => count($this->jsonArray($row->public_url_cohort_json)),
            ],
            'baseline' => $this->safeEvidence($row->baseline_window_json),
            'primary_metric' => $this->safeEvidence($row->primary_metric_json),
            'guardrails' => $this->safeEvidence($row->guardrail_metrics_json),
            'observation_window' => $this->safeEvidence($row->observation_window_json),
            'evidence_readback' => [
                'public_runtime' => $this->safeEvidence($row->public_runtime_readback_json),
                'measurement' => $this->safeEvidence($row->gsc_funnel_evidence_state_json),
            ],
            'status' => (string) $row->current_state,
            'close_reason' => $this->nullableString($row->close_reason),
            'updated_at' => $this->nullableString($row->updated_at),
        ];
    }

    /** @return array<string, scalar|null> */
    private function safeEvidence(mixed $json): array
    {
        $source = $this->jsonArray($json);
        $allowed = [
            'name', 'metric', 'value', 'unit', 'status', 'state', 'ok',
            'start', 'end', 'window_days', 'checked_at', 'observed_at',
            'freshness_state', 'quality_state', 'sample_size',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            $value = $source[$key] ?? null;
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function jsonArray(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /** @return array<string, int> */
    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(int $page, int $perPage): array
    {
        return [
            'state' => 'verified_zero',
            'items' => [],
            'pagination' => $this->pagination($page, $perPage, 0),
            'empty' => true,
            'read_only' => true,
        ];
    }
}
