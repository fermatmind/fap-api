<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

use App\Support\SchemaBaseline;
use Illuminate\Support\Carbon;

final class BigFiveResultPageV2AuditFields
{
    public const STATUS_ATTACHED = 'attached';
    public const STATUS_FALLBACK = 'fallback';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_NOT_EVALUATED = 'not_evaluated';

    public const REASON_V2_ATTACHED = 'v2_attached';
    public const REASON_NOT_BIG5 = 'not_big5';
    public const REASON_PRODUCTION_RUNTIME_DISABLED = 'production_runtime_disabled';
    public const REASON_PRODUCTION_ROLLOUT_DENIED = 'production_rollout_denied';
    public const REASON_LOCKED_OR_FREE_PREVIEW = 'locked_or_free_preview';
    public const REASON_MISSING_SCORE_RESULT = 'missing_score_result';
    public const REASON_ROUTE_INPUT_INVALID = 'route_input_invalid';
    public const REASON_ROUTE_LOOKUP_FAILED = 'route_lookup_failed';
    public const REASON_COMPOSER_FAILED = 'composer_failed';
    public const REASON_PAYLOAD_VALIDATION_FAILED = 'payload_validation_failed';
    public const REASON_EXCEPTION = 'exception';
    public const REASON_LEGACY_ENGINE_ONLY = 'legacy_engine_only';

    /**
     * @return array<string,mixed>
     */
    public function fromRuntimeAudit(array $audit, string $scaleCode, ?Carbon $now = null): array
    {
        if (strtoupper(trim($scaleCode)) !== BigFiveResultPageV2Contract::SCALE_CODE) {
            return [];
        }

        $status = $this->normalizeStatus($audit['status'] ?? self::STATUS_NOT_EVALUATED);
        $reason = $this->normalizeReason($audit['fallback_reason'] ?? $audit['reason'] ?? null);
        $errorCount = max(0, min(65535, (int) ($audit['validation_error_count'] ?? 0)));

        return [
            'big5_result_page_v2_status' => $status,
            'big5_result_page_v2_fallback_reason' => $reason,
            'big5_result_page_v2_validation_error_count' => $errorCount,
            'big5_result_page_v2_audited_at' => $now ?? now(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function fromReportPayload(array $report, string $scaleCode, ?Carbon $now = null): array
    {
        if (strtoupper(trim($scaleCode)) !== BigFiveResultPageV2Contract::SCALE_CODE) {
            return [];
        }

        $payload = $report[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
        if (is_array($payload)) {
            return $this->fromRuntimeAudit([
                'status' => self::STATUS_ATTACHED,
                'fallback_reason' => self::REASON_V2_ATTACHED,
                'validation_error_count' => 0,
            ], $scaleCode, $now);
        }

        return $this->fromRuntimeAudit([
            'status' => self::STATUS_FALLBACK,
            'fallback_reason' => self::REASON_LEGACY_ENGINE_ONLY,
            'validation_error_count' => 0,
        ], $scaleCode, $now);
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    public function appendToSnapshotRow(array $row, array $fields): array
    {
        foreach ($fields as $column => $value) {
            if ($this->columnExists($column)) {
                $row[$column] = $value;
            }
        }

        return $row;
    }

    public function columnExists(string $column): bool
    {
        return SchemaBaseline::hasColumn('report_snapshots', $column);
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, [
            self::STATUS_ATTACHED,
            self::STATUS_FALLBACK,
            self::STATUS_INVALID,
            self::STATUS_DISABLED,
            self::STATUS_NOT_EVALUATED,
        ], true) ? $status : self::STATUS_NOT_EVALUATED;
    }

    private function normalizeReason(mixed $value): ?string
    {
        $reason = strtolower(trim((string) $value));
        if ($reason === '') {
            return null;
        }

        return substr(preg_replace('/[^a-z0-9_]/', '_', $reason) ?? $reason, 0, 64);
    }
}
