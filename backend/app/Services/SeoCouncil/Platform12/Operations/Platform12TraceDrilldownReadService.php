<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class Platform12TraceDrilldownReadService
{
    public const PER_PAGE = 20;

    public const RETENTION_DAYS = 30;

    public const MAX_QUERY_ROWS = 200;

    public const CSV_FIELDS = [
        'mission', 'mode', 'role', 'status', 'stop_reason', 'cost_microusd',
        'latency_ms', 'catalog_version', 'catalog_hash', 'policy_hash',
        'binding_hash', 'evidence_hash', 'receipt_hash',
    ];

    /** @return array<string,mixed> */
    public function snapshot(int $page = 1): array
    {
        $page = max(1, min($page, (int) ceil(self::MAX_QUERY_ROWS / self::PER_PAGE)));
        try {
            $connection = (string) config('seo_council.connection', 'seo_intel');
            if (! \App\Support\SchemaBaseline::tableExists('seo_council_runs', $connection)) {
                return $this->envelope('unavailable', [], $page, null);
            }
            $query = DB::connection($connection)->table('seo_council_runs')
                ->where('created_at', '>=', CarbonImmutable::now('UTC')->subDays(self::RETENTION_DAYS)->format('Y-m-d H:i:s'))
                ->orderByDesc('created_at')
                ->orderBy('run_id');
            $total = (clone $query)->limit(self::MAX_QUERY_ROWS)->pluck('run_id')->count();
            $rows = $query->forPage($page, self::PER_PAGE)->limit(self::PER_PAGE)->get();
            $items = [];
            foreach ($rows as $row) {
                $items[] = $this->present($row);
            }

            return $this->envelope($total === 0 ? 'not_started' : 'available', $items, $page, $total);
        } catch (Throwable) {
            return $this->envelope('unavailable', [], $page, null);
        }
    }

    /** @param list<array<string,mixed>> $items */
    public function csv(array $items): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, self::CSV_FIELDS);
        foreach ($items as $item) {
            fputcsv($stream, array_map(
                fn (string $field): string => $this->spreadsheetSafe((string) ($item[$field] ?? '')),
                self::CSV_FIELDS,
            ));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return is_string($csv) ? $csv : '';
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $receipt = $this->decode((string) $row->receipt_json);
        $handoff = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'role_handoff');
        $modeOutput = collect($receipt['route_plan'] ?? [])->firstWhere('kind', 'mode_output');
        $created = CarbonImmutable::parse((string) $row->created_at, 'UTC');
        $updated = CarbonImmutable::parse((string) $row->updated_at, 'UTC');

        return [
            'mission' => $this->safeCode(data_get($handoff, 'scope.mission_type')),
            'mode' => $this->safeCode(data_get($modeOutput, 'mode_id')),
            'role' => $this->safeCode(data_get($handoff, 'target_role_id')),
            'status' => $this->safeCode($row->status),
            'stop_reason' => $this->safeCode($row->stop_reason),
            'cost_microusd' => is_int($receipt['cost_microusd'] ?? null) ? $receipt['cost_microusd'] : null,
            'latency_ms' => max(0, $created->diffInMilliseconds($updated)),
            'catalog_version' => $this->safeVersion(data_get($receipt, 'catalog_ref.version')),
            'catalog_hash' => $this->safeHash(data_get($receipt, 'catalog_ref.hash')),
            'policy_hash' => $this->safeHash($row->policy_hash),
            'binding_hash' => $this->safeHash($row->binding_hash),
            'evidence_hash' => $this->safeHash($row->evidence_hash),
            'receipt_hash' => $this->safeHash($row->receipt_hash),
        ];
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function envelope(string $state, array $items, int $page, ?int $total): array
    {
        return [
            'state' => $state,
            'items' => $items,
            'pagination' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total],
            'query_budget' => ['max_rows' => self::MAX_QUERY_ROWS, 'retention_days' => self::RETENTION_DAYS, 'max_queries' => 4],
            'export_fields' => self::CSV_FIELDS,
            'read_only' => true,
            'execution_allowed' => false,
            'permission_controls_allowed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function safeCode(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$/D', $value) === 1 ? $value : 'unavailable';
    }

    private function safeVersion(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,31}$/D', $value) === 1 ? $value : 'unavailable';
    }

    private function safeHash(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : 'unavailable';
    }

    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/^(?:\s*[=+\-@]|[\t\r\n])/u', $value) === 1 ? "'".$value : $value;
    }
}
