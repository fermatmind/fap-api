<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Operations;

use App\Services\SeoAgentEvidence\External\ExternalInjectionScanner;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayPrivacyGuard;
use InvalidArgumentException;

final readonly class Platform12SanitizedOperationsProjector
{
    private const KINDS = [
        'system_health', 'weekly_decision_cards', 'active_experiments_canary',
        'evidence_role_trace_drilldown',
    ];

    public function __construct(
        private SeoRegistryHasher $hasher,
        private PolicyGatewayPrivacyGuard $privacy,
        private ExternalInjectionScanner $injection,
    ) {}

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function systemHealth(array $source, int $retentionDays = 7): array
    {
        return $this->project('system_health', $source, 1, 50, $retentionDays);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function weeklyDecisionCards(array $source, int $page = 1, int $perPage = 20, int $retentionDays = 30): array
    {
        return $this->project('weekly_decision_cards', $source, $page, $perPage, $retentionDays);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function activeExperiments(array $source, int $page = 1, int $perPage = 20, int $retentionDays = 30): array
    {
        return $this->project('active_experiments_canary', $source, $page, $perPage, $retentionDays);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function traceDrilldown(array $source, int $page = 1, int $perPage = 20, int $retentionDays = 30): array
    {
        return $this->project('evidence_role_trace_drilldown', $source, $page, $perPage, $retentionDays);
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function project(string $kind, array $source, int $page, int $perPage, int $retentionDays): array
    {
        $this->validateBudget($kind, $page, $perPage, $retentionDays);
        $availability = $source['availability'] ?? null;
        $freshness = $source['freshness'] ?? null;
        $records = $source['records'] ?? null;
        if (! in_array($availability, ['AVAILABLE', 'MISSING', 'UNAVAILABLE'], true)
            || ! in_array($freshness, ['FRESH', 'STALE', 'UNKNOWN'], true)
            || ! is_array($records)
            || ! array_is_list($records)) {
            return $this->envelope($kind, 'UNAVAILABLE', [], $page, $perPage, $retentionDays, null, 0);
        }
        if ($availability !== 'AVAILABLE') {
            return $this->envelope($kind, $availability, [], $page, $perPage, $retentionDays, null, 0);
        }
        if ($freshness === 'UNKNOWN') {
            return $this->envelope($kind, 'UNAVAILABLE', [], $page, $perPage, $retentionDays, null, 0);
        }

        $safe = [];
        $rejected = 0;
        foreach ($records as $record) {
            $sanitized = is_array($record) ? $this->sanitizeRecord($kind, $record) : null;
            if ($sanitized === null) {
                $rejected++;
            } else {
                $safe[] = $sanitized;
            }
        }
        $total = count($safe);
        $items = array_slice($safe, ($page - 1) * $perPage, $perPage);
        $held = array_filter($safe, static fn (array $item): bool => in_array($item['state'], ['HOLD', 'HELD', 'FAILED'], true));
        $status = match (true) {
            $rejected > 0 => 'HOLD',
            $freshness === 'STALE' => 'STALE',
            $total === 0 => 'VALID_ZERO',
            $held !== [] => 'HOLD',
            default => 'READY',
        };

        return $this->envelope($kind, $status, $items, $page, $perPage, $retentionDays, $total, $rejected);
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function sanitizeRecord(string $kind, array $record): ?array
    {
        $fields = [
            'system_health' => ['component', 'reference_hash', 'summary_code', 'state', 'observed_at', 'expires_at', 'count'],
            'weekly_decision_cards' => ['reference_hash', 'summary_code', 'state', 'observed_at', 'expires_at', 'count'],
            'active_experiments_canary' => ['reference_hash', 'summary_code', 'state', 'observed_at', 'expires_at', 'count'],
            'evidence_role_trace_drilldown' => [
                'reference_hash', 'evidence_hash', 'role_hash', 'trace_hash',
                'summary_code', 'state', 'observed_at', 'expires_at', 'count',
            ],
        ][$kind];
        $safe = array_intersect_key($record, array_flip($fields));
        foreach (['reference_hash', 'evidence_hash', 'role_hash', 'trace_hash'] as $hash) {
            if (array_key_exists($hash, $safe) && (! is_string($safe[$hash]) || preg_match('/^[a-f0-9]{64}$/D', $safe[$hash]) !== 1)) {
                return null;
            }
        }
        foreach (['component', 'summary_code'] as $code) {
            if (array_key_exists($code, $safe) && (! is_string($safe[$code]) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $safe[$code]) !== 1)) {
                return null;
            }
        }
        if (! is_string($safe['state'] ?? null)
            || preg_match('/^[A-Z][A-Z0-9_]{1,31}$/D', $safe['state']) !== 1
            || ! is_int($safe['count'] ?? null)
            || $safe['count'] < 0
            || $safe['count'] > 1000000) {
            return null;
        }
        foreach (['observed_at', 'expires_at'] as $time) {
            if (array_key_exists($time, $safe)
                && $safe[$time] !== null
                && (! is_string($safe[$time]) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $safe[$time]) !== 1)) {
                return null;
            }
        }
        if ($this->privacy->containsPrivateData($safe) || $this->injection->scan($safe)['result'] !== 'pass') {
            return null;
        }

        return $safe;
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function envelope(
        string $kind,
        string $status,
        array $items,
        int $page,
        int $perPage,
        int $retentionDays,
        ?int $total,
        int $rejected,
    ): array {
        $projection = [
            'projection_id' => 'seo.platform12.'.$kind.'.v1',
            'status' => $status,
            'retention_days' => $retentionDays,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'query_budget' => ['max_rows' => 200, 'consumed_rows' => count($items), 'rejected_rows' => $rejected],
            'items' => $items,
            'authority' => false,
            'read_only' => true,
            'execution_allowed' => false,
            'write_allowed' => false,
        ];
        $projection['projection_hash'] = $this->hasher->hash($projection);

        return $projection;
    }

    private function validateBudget(string $kind, int $page, int $perPage, int $retentionDays): void
    {
        if (! in_array($kind, self::KINDS, true)
            || $page < 1
            || $perPage < 1
            || $perPage > 50
            || $retentionDays < 1
            || $retentionDays > 90) {
            throw new InvalidArgumentException('OPERATIONS_PROJECTION_BUDGET_INVALID');
        }
    }
}
