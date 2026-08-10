<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MeasurementCommerceTruthReadModel
{
    public const SCHEMA_VERSION = 'fermatmind.measurement-commerce-truth.v1';

    public const TASK = 'MEASUREMENT-COMMERCE-TRUTH-READMODEL-01';

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'orders',
        'payment_events',
        'benefit_grants',
        'report_snapshots',
        'attempts',
        'events',
    ];

    /** @var array<string, list<string>> */
    private const REQUIRED_COLUMNS = [
        'orders' => ['id', 'order_no', 'target_attempt_id', 'status', 'payment_state', 'channel', 'provider', 'currency', 'paid_at', 'refunded_at', 'created_at'],
        'payment_events' => ['id', 'order_id', 'order_no', 'event_type', 'status', 'handle_status', 'provider', 'processed_at', 'handled_at', 'received_at', 'created_at'],
        'benefit_grants' => ['id', 'attempt_id', 'status', 'benefit_type', 'source_order_id', 'order_no', 'revoked_at', 'expires_at', 'created_at'],
        'report_snapshots' => ['attempt_id', 'order_no', 'status', 'created_at', 'updated_at'],
        'attempts' => ['id', 'scale_code', 'locale', 'channel', 'client_platform', 'answers_summary_json'],
        'events' => ['id', 'event_code', 'event_name', 'attempt_id', 'occurred_at'],
    ];

    /** Mirrors the existing AnalyticsFunnelDailyBuilder ready-state authority without reading report payloads. */
    private const READY_SNAPSHOT_STATUSES = [
        'ready',
        'full',
        'completed',
        'complete',
        'done',
        'generated',
        'success',
        'succeeded',
    ];

    /** Mirrors the existing payment-event coverage semantics. Orders remain the business authority. */
    private const PAYMENT_SUCCESS_EVENT_TYPES = [
        'payment_succeeded',
        'payment_success',
        'subscription_payment_success',
        'invoice.payment_succeeded',
    ];

    private const PAYMENT_SUCCESS_STATUSES = ['paid', 'fulfilled', 'completed', 'complete', 'success', 'succeeded'];

    private const PAYMENT_SUCCESS_HANDLE_STATUSES = ['processed', 'handled', 'success', 'succeeded', 'ok', 'reprocessed'];

    private const NON_PAYMENT_EVENT_TYPES = ['order_created', 'checkout_start', 'checkout_started', 'payment_failed', 'refund', 'refund_succeeded', 'refund_failed'];

    /** @var list<string> */
    private const CHANNELS = ['web', 'wechat_miniapp', 'alipay_miniapp', 'other', 'unknown'];

    /** @var list<string> */
    private const PROVIDERS = ['stripe', 'wechatpay', 'alipay', 'billing', 'internal', 'mock', 'other', 'unknown'];

    /** @var list<string> */
    private const CURRENCIES = ['USD', 'CNY', 'EUR', 'GBP', 'JPY', 'HKD', 'SGD', 'AUD', 'CAD'];

    /** @var list<string> */
    private const METRICS = [
        'order_created_count',
        'payment_succeeded_count',
        'refund_count',
        'report_unlock_count',
        'report_ready_count',
        'active_entitlement_count',
    ];

    public function __construct(
        private readonly MeasurementAttributionDimensions $attributionDimensions,
    ) {}

    /**
     * @param  array<string, list<string>>  $requestedFilters
     * @return array<string, mixed>
     */
    public function report(string $from, string $to, array $requestedFilters = []): array
    {
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        $issues = [];

        if ($fromDate === null) {
            $issues[] = 'from_date_invalid';
        }
        if ($toDate === null) {
            $issues[] = 'to_date_invalid';
        }
        if ($fromDate !== null && $toDate !== null && $fromDate->greaterThan($toDate)) {
            $issues[] = 'date_window_invalid';
        }

        $issues = array_merge($issues, $this->schemaIssues());
        $filters = $this->filters($requestedFilters);
        if ($filters === false) {
            $issues[] = 'filter_invalid';
        }

        if ($issues !== [] || $fromDate === null || $toDate === null || $filters === false) {
            return $this->blocked($from, $to, $issues);
        }

        $fromAt = $fromDate->startOfDay();
        $toAt = $toDate->endOfDay();
        $paymentEvents = $this->paymentEvents($fromAt, $toAt);
        $analyticsEvents = $this->analyticsCoverageEvents($fromAt, $toAt);
        $grants = $this->grantRows($toAt);
        $snapshots = $this->snapshotRows($fromAt, $toAt, $analyticsEvents->pluck('attempt_id')->filter()->all());
        $orders = $this->orderRows($fromAt, $toAt, $grants, $snapshots, $paymentEvents);

        $attemptIds = $orders->pluck('target_attempt_id')
            ->merge($grants->pluck('attempt_id'))
            ->merge($snapshots->pluck('attempt_id'))
            ->merge($analyticsEvents->pluck('attempt_id'))
            ->filter(fn (mixed $value): bool => trim((string) $value) !== '')
            ->unique()
            ->values()
            ->all();
        $attemptDimensions = $this->attemptDimensions($attemptIds);
        $ordersById = $orders->keyBy(fn (object $row): string => trim((string) $row->id));
        $ordersByNumber = $orders->keyBy(fn (object $row): string => trim((string) $row->order_no));

        $facts = [];
        $paymentTruth = [];
        $unlockTruthByAttempt = [];
        $reportTruthByAttempt = [];

        foreach ($orders as $order) {
            $identity = $this->identity($order->id ?? null, $order->order_no ?? null);
            if ($identity === null) {
                $issues[] = 'order_identity_missing';

                continue;
            }

            $paymentState = Order::normalizePaymentState($order->payment_state ?? null, $order->status ?? null);
            $dimensions = $this->orderDimensions($order, $attemptDimensions, $paymentState);
            $createdAt = $this->dateTime($order->created_at ?? null);
            if ($createdAt !== null && $createdAt->betweenIncluded($fromAt, $toAt)) {
                $this->addFact($facts, 'order_created_count', $identity, $createdAt, $dimensions, (string) ($order->target_attempt_id ?? ''));
            }

            $paidAt = $this->dateTime($order->paid_at ?? null);
            if (in_array($paymentState, [Order::PAYMENT_STATE_PAID, Order::PAYMENT_STATE_REFUNDED], true)) {
                if ($paidAt === null) {
                    $issues[] = 'paid_order_timestamp_missing';
                } elseif ($paidAt->betweenIncluded($fromAt, $toAt)) {
                    $this->addFact($facts, 'payment_succeeded_count', $identity, $paidAt, $dimensions, (string) ($order->target_attempt_id ?? ''));
                    if ($this->matches($dimensions, $filters)) {
                        $paymentTruth[$identity] = true;
                    }
                }
            }

            $refundedAt = $this->dateTime($order->refunded_at ?? null);
            if ($paymentState === Order::PAYMENT_STATE_REFUNDED) {
                if ($refundedAt === null) {
                    $issues[] = 'refunded_order_timestamp_missing';
                } elseif ($refundedAt->betweenIncluded($fromAt, $toAt)) {
                    $this->addFact($facts, 'refund_count', $identity, $refundedAt, $dimensions, (string) ($order->target_attempt_id ?? ''));
                }
            }
        }

        foreach ($grants as $grant) {
            $identity = $this->identity($grant->id ?? null);
            $createdAt = $this->dateTime($grant->created_at ?? null);
            if ($identity === null || $createdAt === null || ! $this->isReportGrant($grant)) {
                continue;
            }

            $activeAsOf = $this->grantActiveAsOf($grant, $toAt, $issues);
            if (! $activeAsOf) {
                continue;
            }

            $order = $this->linkedOrder($grant, $ordersById, $ordersByNumber);
            $dimensions = $this->entitlementDimensions($grant, $order, $attemptDimensions);
            $attemptId = trim((string) ($grant->attempt_id ?? ''));
            if ($createdAt->betweenIncluded($fromAt, $toAt)) {
                $this->addFact($facts, 'report_unlock_count', $identity, $createdAt, $dimensions, $attemptId);
                if ($attemptId !== '' && $this->matches($dimensions, $filters)) {
                    $unlockTruthByAttempt[$attemptId] = true;
                }
            }
            if ($createdAt->lessThanOrEqualTo($toAt)) {
                $this->addFact($facts, 'active_entitlement_count', $identity, $toAt, $dimensions, $attemptId);
            }
        }

        foreach ($snapshots as $snapshot) {
            if (! $this->isReadySnapshot($snapshot)) {
                continue;
            }
            $attemptId = trim((string) ($snapshot->attempt_id ?? ''));
            $readyAt = $this->dateTime($snapshot->updated_at ?? null) ?? $this->dateTime($snapshot->created_at ?? null);
            if ($attemptId === '' || $readyAt === null || ! $readyAt->betweenIncluded($fromAt, $toAt)) {
                continue;
            }

            $order = $this->linkedOrder($snapshot, $ordersById, $ordersByNumber);
            $dimensions = $this->reportDimensions($snapshot, $order, $attemptDimensions);
            $this->addFact($facts, 'report_ready_count', $attemptId, $readyAt, $dimensions, $attemptId);
            if ($this->matches($dimensions, $filters)) {
                $reportTruthByAttempt[$attemptId] = true;
            }
        }

        $filteredFacts = array_values(array_filter(
            $facts,
            fn (array $fact): bool => $this->matches((array) $fact['dimensions'], $filters),
        ));
        $totals = array_fill_keys(self::METRICS, 0);
        $identitiesByMetric = [];
        foreach ($filteredFacts as $fact) {
            $identitiesByMetric[$fact['metric']][$fact['identity']] = true;
        }
        foreach (self::METRICS as $metric) {
            $totals[$metric] = count($identitiesByMetric[$metric] ?? []);
        }

        [$paymentCoverage, $paymentAnomalies] = $this->paymentCoverage(
            $paymentTruth,
            $paymentEvents,
            $ordersById,
            $ordersByNumber,
            $attemptDimensions,
            $filters,
        );
        [$entitlementCoverage, $entitlementAnomalies] = $this->eventCoverage(
            $unlockTruthByAttempt,
            $analyticsEvents->where('event_name', FunnelEventTaxonomy::REPORT_UNLOCK),
            $filters,
        );
        [$reportCoverage, $reportAnomalies] = $this->eventCoverage(
            $reportTruthByAttempt,
            $analyticsEvents->where('event_name', FunnelEventTaxonomy::REPORT_READY),
            $filters,
        );

        $anomalies = $paymentAnomalies + $entitlementAnomalies + $reportAnomalies;
        if ($paymentAnomalies > 0) {
            $issues[] = 'payment_event_duplicate_or_conflict';
        }
        if ($entitlementAnomalies > 0) {
            $issues[] = 'report_unlock_event_duplicate_or_conflict';
        }
        if ($reportAnomalies > 0) {
            $issues[] = 'report_ready_event_duplicate_or_conflict';
        }
        foreach ([
            'payment_event' => $paymentCoverage,
            'entitlement' => $entitlementCoverage,
            'report_ready' => $reportCoverage,
        ] as $name => $coverage) {
            if ($coverage === 'partial') {
                $issues[] = $name.'_coverage_partial';
            }
        }

        $rows = $this->aggregateRows($filteredFacts);
        $hasData = array_sum($totals) > 0 || $paymentEvents->isNotEmpty() || $analyticsEvents->isNotEmpty();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'ok' => true,
            'status' => ! $hasData ? 'empty' : ($issues === [] ? 'pass' : 'warning'),
            'issues' => array_values(array_unique($issues)),
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'active_entitlement_as_of' => $toAt->toIso8601String(),
            'filters' => $filters,
            'source_tables' => self::REQUIRED_TABLES,
            'totals' => array_merge($totals, [
                'payment_event_coverage_status' => $paymentCoverage,
                'entitlement_coverage_status' => $entitlementCoverage,
                'report_ready_coverage_status' => $reportCoverage,
                'duplicate_or_conflict_count' => $anomalies,
            ]),
            'row_count' => count($rows),
            'rows' => $rows,
            'read_only' => true,
        ];
    }

    /** @return list<string> */
    private function schemaIssues(): array
    {
        $issues = [];
        foreach (self::REQUIRED_TABLES as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    $issues[] = $table.'_missing';

                    continue;
                }
                foreach (self::REQUIRED_COLUMNS[$table] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $issues[] = $table.'.'.$column.'_missing';
                    }
                }
            } catch (Throwable) {
                $issues[] = $table.'_schema_check_failed';
            }
        }

        return $issues;
    }

    /** @return Collection<int, object> */
    private function grantRows(CarbonImmutable $toAt): Collection
    {
        return DB::table('benefit_grants')->select(self::REQUIRED_COLUMNS['benefit_grants'])
            ->where('created_at', '<=', $toAt)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, object> */
    private function paymentEvents(CarbonImmutable $fromAt, CarbonImmutable $toAt): Collection
    {
        return DB::table('payment_events')->select(self::REQUIRED_COLUMNS['payment_events'])
            ->where(function (QueryBuilder $query) use ($fromAt, $toAt): void {
                $query->whereBetween('processed_at', [$fromAt, $toAt])
                    ->orWhereBetween('handled_at', [$fromAt, $toAt])
                    ->orWhereBetween('received_at', [$fromAt, $toAt])
                    ->orWhereBetween('created_at', [$fromAt, $toAt]);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function analyticsCoverageEvents(CarbonImmutable $fromAt, CarbonImmutable $toAt): Collection
    {
        return DB::table('events')->select(self::REQUIRED_COLUMNS['events'])
            ->whereBetween('occurred_at', [$fromAt, $toAt])
            ->get()
            ->map(fn (object $event): array => [
                'event_id' => trim((string) $event->id),
                'event_name' => FunnelEventTaxonomy::canonicalize((string) ($event->event_code ?: $event->event_name)),
                'attempt_id' => trim((string) ($event->attempt_id ?? '')),
            ])
            ->filter(fn (array $event): bool => in_array($event['event_name'], [FunnelEventTaxonomy::REPORT_UNLOCK, FunnelEventTaxonomy::REPORT_READY], true))
            ->values();
    }

    /**
     * @param  list<string>  $eventAttemptIds
     * @return Collection<int, object>
     */
    private function snapshotRows(CarbonImmutable $fromAt, CarbonImmutable $toAt, array $eventAttemptIds): Collection
    {
        return DB::table('report_snapshots')->select(self::REQUIRED_COLUMNS['report_snapshots'])
            ->where(function (QueryBuilder $query) use ($fromAt, $toAt, $eventAttemptIds): void {
                $query->whereBetween('created_at', [$fromAt, $toAt])
                    ->orWhereBetween('updated_at', [$fromAt, $toAt]);
                if ($eventAttemptIds !== []) {
                    $query->orWhereIn('attempt_id', $eventAttemptIds);
                }
            })
            ->get();
    }

    /**
     * @param  Collection<int, object>  $grants
     * @param  Collection<int, object>  $snapshots
     * @param  Collection<int, object>  $paymentEvents
     * @return Collection<int, object>
     */
    private function orderRows(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        Collection $grants,
        Collection $snapshots,
        Collection $paymentEvents,
    ): Collection {
        $linkedIds = $grants->pluck('source_order_id')->merge($paymentEvents->pluck('order_id'))->filter()->unique()->values()->all();
        $linkedNumbers = $grants->pluck('order_no')
            ->merge($snapshots->pluck('order_no'))
            ->merge($paymentEvents->pluck('order_no'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return DB::table('orders')->select(self::REQUIRED_COLUMNS['orders'])
            ->where(function (QueryBuilder $query) use ($fromAt, $toAt, $linkedIds, $linkedNumbers): void {
                $query->whereBetween('created_at', [$fromAt, $toAt])
                    ->orWhereBetween('paid_at', [$fromAt, $toAt])
                    ->orWhereBetween('refunded_at', [$fromAt, $toAt]);
                if ($linkedIds !== []) {
                    $query->orWhereIn('id', $linkedIds);
                }
                if ($linkedNumbers !== []) {
                    $query->orWhereIn('order_no', $linkedNumbers);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $attemptIds
     * @return array<string, array<string, string>>
     */
    private function attemptDimensions(array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        $dimensions = [];
        foreach (array_chunk($attemptIds, 500) as $chunk) {
            foreach (DB::table('attempts')->select(self::REQUIRED_COLUMNS['attempts'])->whereIn('id', $chunk)->get() as $attempt) {
                $dimensions[(string) $attempt->id] = array_intersect_key(
                    $this->attributionDimensions->fromAttempt($attempt),
                    array_flip(['scale_code', 'form_code', 'locale']),
                );
            }
        }

        return $dimensions;
    }

    /** @param array<string, array<string, string>> $attemptDimensions */
    private function orderDimensions(object $order, array $attemptDimensions, string $paymentState): array
    {
        $attemptId = trim((string) ($order->target_attempt_id ?? ''));

        return array_merge($attemptDimensions[$attemptId] ?? $this->unknownAttemptDimensions(), [
            'commerce_channel' => $this->channel($order->channel ?? null),
            'provider_class' => $this->provider($order->provider ?? null),
            'currency' => $this->currency($order->currency ?? null),
            'payment_state' => $paymentState,
        ]);
    }

    /** @param array<string, array<string, string>> $attemptDimensions */
    private function entitlementDimensions(object $grant, ?object $order, array $attemptDimensions): array
    {
        $attemptId = trim((string) ($grant->attempt_id ?? ''));
        $commerce = $order === null ? [
            'commerce_channel' => 'unknown',
            'provider_class' => 'unknown',
            'currency' => 'unknown',
        ] : array_intersect_key(
            $this->orderDimensions($order, $attemptDimensions, Order::normalizePaymentState($order->payment_state ?? null, $order->status ?? null)),
            array_flip(['commerce_channel', 'provider_class', 'currency']),
        );

        return array_merge($attemptDimensions[$attemptId] ?? $this->unknownAttemptDimensions(), $commerce, [
            'entitlement_state' => 'active',
        ]);
    }

    /** @param array<string, array<string, string>> $attemptDimensions */
    private function reportDimensions(object $snapshot, ?object $order, array $attemptDimensions): array
    {
        $attemptId = trim((string) ($snapshot->attempt_id ?? ''));
        $commerce = $order === null ? [
            'commerce_channel' => 'unknown',
            'provider_class' => 'unknown',
            'currency' => 'unknown',
        ] : array_intersect_key(
            $this->orderDimensions($order, $attemptDimensions, Order::normalizePaymentState($order->payment_state ?? null, $order->status ?? null)),
            array_flip(['commerce_channel', 'provider_class', 'currency']),
        );

        return array_merge($attemptDimensions[$attemptId] ?? $this->unknownAttemptDimensions(), $commerce, [
            'report_state' => 'ready',
        ]);
    }

    /** @return array<string, string> */
    private function unknownAttemptDimensions(): array
    {
        return ['scale_code' => 'unknown', 'form_code' => 'unknown', 'locale' => 'unknown'];
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @param  array<string, mixed>  $facts
     */
    private function addFact(array &$facts, string $metric, string $identity, CarbonImmutable $at, array $dimensions, string $correlationKey): void
    {
        $facts[] = [
            'metric' => $metric,
            'identity' => $identity,
            'correlation_key' => $correlationKey,
            'date' => $at->toDateString(),
            'dimensions' => $dimensions,
        ];
    }

    /**
     * @param  array<string, bool>  $truth
     * @param  Collection<int, object>  $events
     * @param  Collection<string, object>  $ordersById
     * @param  Collection<string, object>  $ordersByNumber
     * @param  array<string, array<string, string>>  $attemptDimensions
     * @param  array<string, list<string>>  $filters
     * @return array{string,int}
     */
    private function paymentCoverage(
        array $truth,
        Collection $events,
        Collection $ordersById,
        Collection $ordersByNumber,
        array $attemptDimensions,
        array $filters,
    ): array {
        $observed = [];
        $conflicts = 0;
        $filtersActive = collect($filters)->contains(static fn (array $values): bool => $values !== []);
        foreach ($events as $event) {
            if (! $this->isPaymentSuccessEvent($event)) {
                continue;
            }
            $order = $this->linkedOrder($event, $ordersById, $ordersByNumber);
            if ($order === null) {
                if ($filtersActive) {
                    continue;
                }
                $conflicts++;

                continue;
            }
            $identity = $this->identity($order->id ?? null, $order->order_no ?? null);
            if ($identity === null) {
                $conflicts++;

                continue;
            }
            $state = Order::normalizePaymentState($order->payment_state ?? null, $order->status ?? null);
            if (! $this->matches($this->orderDimensions($order, $attemptDimensions, $state), $filters)) {
                continue;
            }
            $observed[$identity] = ($observed[$identity] ?? 0) + 1;
            if (! isset($truth[$identity])) {
                $conflicts++;
            }
        }

        return $this->coverage($truth, $observed, $conflicts);
    }

    /**
     * @param  array<string, bool>  $truth
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  array<string, list<string>>  $filters
     * @return array{string,int}
     */
    private function eventCoverage(array $truth, Collection $events, array $filters): array
    {
        $observed = [];
        $conflicts = 0;
        $filtersActive = collect($filters)->contains(static fn (array $values): bool => $values !== []);
        foreach ($events as $event) {
            $attemptId = trim((string) ($event['attempt_id'] ?? ''));
            if ($attemptId === '') {
                $conflicts++;

                continue;
            }
            if ($filtersActive && ! isset($truth[$attemptId])) {
                continue;
            }
            $observed[$attemptId] = ($observed[$attemptId] ?? 0) + 1;
            if (! isset($truth[$attemptId])) {
                $conflicts++;
            }
        }

        return $this->coverage($truth, $observed, $conflicts);
    }

    /**
     * @param  array<string, bool>  $truth
     * @param  array<string, int>  $observed
     * @return array{string,int}
     */
    private function coverage(array $truth, array $observed, int $conflicts): array
    {
        $missing = 0;
        foreach ($truth as $identity => $_) {
            if (($observed[$identity] ?? 0) === 0) {
                $missing++;
            }
        }
        $duplicates = array_sum(array_map(static fn (int $count): int => max(0, $count - 1), $observed));
        $anomalies = $duplicates + $conflicts;

        $status = match (true) {
            $truth === [] && $observed === [] => 'no_data',
            $missing > 0 || $conflicts > 0 => 'partial',
            $duplicates > 0 => 'duplicate',
            default => 'complete',
        };

        return [$status, $anomalies];
    }

    private function isPaymentSuccessEvent(object $event): bool
    {
        $type = strtolower(trim((string) ($event->event_type ?? '')));
        if (in_array($type, self::PAYMENT_SUCCESS_EVENT_TYPES, true)) {
            return true;
        }
        if (in_array($type, self::NON_PAYMENT_EVENT_TYPES, true)) {
            return false;
        }

        return in_array(strtolower(trim((string) ($event->status ?? ''))), self::PAYMENT_SUCCESS_STATUSES, true)
            || in_array(strtolower(trim((string) ($event->handle_status ?? ''))), self::PAYMENT_SUCCESS_HANDLE_STATUSES, true);
    }

    /** @param list<string> $issues */
    private function grantActiveAsOf(object $grant, CarbonImmutable $asOf, array &$issues): bool
    {
        $createdAt = $this->dateTime($grant->created_at ?? null);
        if ($createdAt === null || $createdAt->greaterThan($asOf)) {
            return false;
        }
        $revokedAt = $this->dateTime($grant->revoked_at ?? null);
        $expiresAt = $this->dateTime($grant->expires_at ?? null);
        $status = strtolower(trim((string) ($grant->status ?? '')));

        if ($revokedAt !== null && $revokedAt->lessThanOrEqualTo($asOf)) {
            return false;
        }
        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($asOf)) {
            return false;
        }
        if ($status === 'active') {
            return true;
        }
        if ($status === 'revoked' && $revokedAt !== null && $revokedAt->greaterThan($asOf)) {
            return true;
        }
        if ($status === 'expired' && $expiresAt !== null && $expiresAt->greaterThan($asOf)) {
            return true;
        }

        if (! in_array($status, ['revoked', 'expired', 'inactive'], true)) {
            $issues[] = 'entitlement_authority_state_invalid';
        }

        return false;
    }

    private function isReportGrant(object $grant): bool
    {
        return strtolower(trim((string) ($grant->benefit_type ?? ''))) === 'report';
    }

    private function isReadySnapshot(object $snapshot): bool
    {
        return in_array(strtolower(trim((string) ($snapshot->status ?? ''))), self::READY_SNAPSHOT_STATUSES, true);
    }

    /**
     * @param  Collection<string, object>  $ordersById
     * @param  Collection<string, object>  $ordersByNumber
     */
    private function linkedOrder(object $row, Collection $ordersById, Collection $ordersByNumber): ?object
    {
        $orderId = trim((string) ($row->source_order_id ?? $row->order_id ?? ''));
        if ($orderId !== '' && $ordersById->has($orderId)) {
            return $ordersById->get($orderId);
        }
        $orderNo = trim((string) ($row->order_no ?? ''));

        return $orderNo !== '' && $ordersByNumber->has($orderNo) ? $ordersByNumber->get($orderNo) : null;
    }

    private function identity(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = trim(is_scalar($value) ? (string) $value : '');
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(array $facts): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            $dimensions = (array) $fact['dimensions'];
            ksort($dimensions);
            $key = $fact['metric'].'|'.$fact['date'].'|'.json_encode($dimensions);
            $groups[$key] ??= [
                'metric' => $fact['metric'],
                'date' => $fact['date'],
                'dimensions' => $dimensions,
                'identities' => [],
            ];
            $groups[$key]['identities'][$fact['identity']] = true;
        }

        ksort($groups);

        return array_values(array_map(static fn (array $group): array => [
            'metric' => $group['metric'],
            'date' => $group['date'],
            'dimensions' => $group['dimensions'],
            'distinct_count' => count($group['identities']),
        ], $groups));
    }

    /**
     * @param  array<string, string>  $dimensions
     * @param  array<string, list<string>>  $filters
     */
    private function matches(array $dimensions, array $filters): bool
    {
        foreach ($filters as $field => $values) {
            if ($values !== [] && ! in_array($dimensions[$field] ?? 'unknown', $values, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $requested
     * @return array<string, list<string>>|false
     */
    private function filters(array $requested): array|false
    {
        $result = [];
        foreach (['scale_code', 'form_code', 'locale', 'commerce_channel', 'provider_class'] as $field) {
            $values = [];
            foreach ($requested[$field] ?? [] as $raw) {
                foreach (explode(',', $raw) as $value) {
                    $value = trim($value);
                    if ($value === '') {
                        continue;
                    }
                    $value = match ($field) {
                        'scale_code' => preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $value) === 1 ? strtoupper($value) : '',
                        'form_code' => preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $value) === 1 ? strtolower($value) : '',
                        'locale' => $this->locale($value),
                        'commerce_channel' => in_array(strtolower($value), self::CHANNELS, true) ? strtolower($value) : '',
                        'provider_class' => in_array(strtolower($value), self::PROVIDERS, true) ? strtolower($value) : '',
                    };
                    if ($value === '') {
                        return false;
                    }
                    $values[] = $value;
                }
            }
            $result[$field] = array_values(array_unique($values));
        }

        return $result;
    }

    private function channel(mixed $value): string
    {
        $normalized = Order::normalizeChannel(is_scalar($value) ? (string) $value : null);

        return $normalized ?? (trim(is_scalar($value) ? (string) $value : '') === '' ? 'unknown' : 'other');
    }

    private function provider(mixed $value): string
    {
        $normalized = strtolower(trim(is_scalar($value) ? (string) $value : ''));
        if ($normalized === '') {
            return 'unknown';
        }

        return in_array($normalized, array_diff(self::PROVIDERS, ['other', 'unknown']), true) ? $normalized : 'other';
    }

    private function currency(mixed $value): string
    {
        $normalized = strtoupper(trim(is_scalar($value) ? (string) $value : ''));
        if ($normalized === '') {
            return 'unknown';
        }

        return in_array($normalized, self::CURRENCIES, true) ? $normalized : 'other';
    }

    private function locale(string $value): string
    {
        $value = strtolower(str_replace('_', '-', trim($value)));

        return match (true) {
            $value === 'en', str_starts_with($value, 'en-') => 'en',
            $value === 'zh', str_starts_with($value, 'zh-') => 'zh-CN',
            $value === 'unknown' => 'unknown',
            default => '',
        };
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $issues
     * @return array<string, mixed>
     */
    private function blocked(string $from, string $to, array $issues): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => self::TASK,
            'ok' => false,
            'status' => 'blocked',
            'issues' => array_values(array_unique($issues)),
            'from' => $from,
            'to' => $to,
            'totals' => [],
            'rows' => [],
            'read_only' => true,
        ];
    }
}
