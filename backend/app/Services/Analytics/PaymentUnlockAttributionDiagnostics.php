<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Order;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PaymentUnlockAttributionDiagnostics
{
    private const BLOCKING_REJECT_CODES = [
        'ATTEMPT_OWNER_MISMATCH',
        'ATTEMPT_SCALE_MISMATCH',
    ];

    private const REQUIRED_TABLES = [
        'orders',
        'skus',
        'benefit_grants',
        'payment_events',
        'unified_access_projections',
    ];

    /**
     * @return array{
     *     read_only:bool,
     *     mutation_attempted:bool,
     *     from:string,
     *     to:string,
     *     org_id:int,
     *     inspected_orders:int,
     *     source_tables:list<string>,
     *     missing_tables:list<string>,
     *     categories:array<string,int>,
     *     samples:list<array<string,mixed>>,
     *     sidecars:list<string>,
     * }
     */
    public function summarize(\DateTimeInterface $from, \DateTimeInterface $to, int $orgId = 0, int $limit = 200): array
    {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $limit = max(1, min(500, $limit));
        $missingTables = $this->missingTables();

        $summary = [
            'read_only' => true,
            'mutation_attempted' => false,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
            'org_id' => max(0, $orgId),
            'inspected_orders' => 0,
            'source_tables' => self::REQUIRED_TABLES,
            'missing_tables' => $missingTables,
            'categories' => $this->emptyCategories(),
            'samples' => [],
            'sidecars' => [],
        ];

        if ($missingTables !== []) {
            $summary['sidecars'][] = 'diagnostics_source_table_missing';

            return $summary;
        }

        $orders = $this->reportUnlockOrders($fromAt, $toAt, max(0, $orgId), $limit);
        $summary['inspected_orders'] = count($orders);

        foreach ($orders as $order) {
            $classification = $this->classify($order);
            $category = $classification['category'];
            $summary['categories'][$category] = (int) ($summary['categories'][$category] ?? 0) + 1;

            if (count($summary['samples']) < 20) {
                $summary['samples'][] = [
                    'order_ref' => $this->redactedRef((string) ($order->order_no ?? '')),
                    'category' => $category,
                    'payment_state' => strtolower(trim((string) ($order->payment_state ?? ''))),
                    'grant_state' => strtolower(trim((string) ($order->grant_state ?? ''))),
                    'has_active_grant' => $classification['has_active_grant'],
                    'has_projection' => $classification['has_projection'],
                    'projection_ready' => $classification['projection_ready'],
                    'blocking_reject_code' => $classification['blocking_reject_code'],
                    'post_commit_failed' => $classification['post_commit_failed'],
                ];
            }
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function missingTables(): array
    {
        $missing = [];

        foreach (self::REQUIRED_TABLES as $table) {
            if (! SchemaBaseline::hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return array<string,int>
     */
    private function emptyCategories(): array
    {
        return [
            'paid_granted_projection_ready' => 0,
            'paid_granted_projection_missing' => 0,
            'paid_granted_projection_not_ready' => 0,
            'paid_no_grant_owner_or_scale_mismatch' => 0,
            'paid_no_grant_post_commit_failed' => 0,
            'paid_no_grant_repairable_candidate' => 0,
            'payment_pending_client_presented' => 0,
            'payment_not_paid_other' => 0,
        ];
    }

    /**
     * @return list<object>
     */
    private function reportUnlockOrders(CarbonImmutable $fromAt, CarbonImmutable $toAt, int $orgId, int $limit): array
    {
        return DB::table('orders')
            ->join('skus', function ($join): void {
                $join->on('skus.sku', '=', 'orders.sku')
                    ->whereRaw("lower(coalesce(skus.kind, '')) = 'report_unlock'")
                    ->where('skus.is_active', true);
            })
            ->where('orders.org_id', $orgId)
            ->where(function ($query) use ($fromAt, $toAt): void {
                $query->whereBetween('orders.created_at', [$fromAt, $toAt])
                    ->orWhereBetween('orders.updated_at', [$fromAt, $toAt]);

                if (SchemaBaseline::hasColumn('orders', 'paid_at')) {
                    $query->orWhereBetween('orders.paid_at', [$fromAt, $toAt]);
                }
            })
            ->orderBy('orders.created_at')
            ->limit($limit)
            ->get([
                'orders.id',
                'orders.order_no',
                'orders.org_id',
                'orders.status',
                'orders.payment_state',
                'orders.grant_state',
                'orders.target_attempt_id',
                'orders.external_trade_no',
                'orders.provider_trade_no',
                'orders.paid_at',
                'orders.updated_at',
                'skus.benefit_code',
            ])
            ->all();
    }

    /**
     * @return array{
     *     category:string,
     *     has_active_grant:bool,
     *     has_projection:bool,
     *     projection_ready:bool,
     *     blocking_reject_code:?string,
     *     post_commit_failed:bool,
     * }
     */
    private function classify(object $order): array
    {
        $paymentState = Order::normalizePaymentState(
            (string) ($order->payment_state ?? ''),
            (string) ($order->status ?? '')
        );
        $grant = $this->activeGrant($order);
        $projection = $this->projection($order);
        $blockingRejectCode = $this->blockingRejectCode($order);
        $postCommitFailed = $this->hasPostCommitFailedEvent($order);
        $projectionReady = $this->projectionReady($projection);

        $category = match (true) {
            $paymentState === Order::PAYMENT_STATE_PAID && $grant !== null && $projectionReady => 'paid_granted_projection_ready',
            $paymentState === Order::PAYMENT_STATE_PAID && $grant !== null && $projection === null => 'paid_granted_projection_missing',
            $paymentState === Order::PAYMENT_STATE_PAID && $grant !== null => 'paid_granted_projection_not_ready',
            $paymentState === Order::PAYMENT_STATE_PAID && $blockingRejectCode !== null => 'paid_no_grant_owner_or_scale_mismatch',
            $paymentState === Order::PAYMENT_STATE_PAID && $postCommitFailed => 'paid_no_grant_post_commit_failed',
            $paymentState === Order::PAYMENT_STATE_PAID => 'paid_no_grant_repairable_candidate',
            in_array($paymentState, [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING], true)
                && ! $this->hasPaymentEvent($order)
                && $this->missingProviderTradeEvidence($order) => 'payment_pending_client_presented',
            default => 'payment_not_paid_other',
        };

        return [
            'category' => $category,
            'has_active_grant' => $grant !== null,
            'has_projection' => $projection !== null,
            'projection_ready' => $projectionReady,
            'blocking_reject_code' => $blockingRejectCode,
            'post_commit_failed' => $postCommitFailed,
        ];
    }

    private function activeGrant(object $order): ?object
    {
        $orderNo = trim((string) ($order->order_no ?? ''));
        $attemptId = trim((string) ($order->target_attempt_id ?? ''));
        $benefitCode = strtoupper(trim((string) ($order->benefit_code ?? '')));

        if ($orderNo === '' && $attemptId === '') {
            return null;
        }

        $query = DB::table('benefit_grants')
            ->where('org_id', (int) ($order->org_id ?? 0))
            ->where('status', 'active')
            ->where(function ($nested) use ($orderNo, $attemptId): void {
                if ($orderNo !== '') {
                    $nested->where('order_no', $orderNo);
                }

                if ($attemptId !== '') {
                    $orderNo !== ''
                        ? $nested->orWhere('attempt_id', $attemptId)
                        : $nested->where('attempt_id', $attemptId);
                }
            });

        if ($benefitCode !== '') {
            $query->where('benefit_code', $benefitCode);
        }

        if (SchemaBaseline::hasColumn('benefit_grants', 'expires_at')) {
            $query->where(function ($nested): void {
                $nested->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        }

        return $query->first();
    }

    private function projection(object $order): ?object
    {
        $attemptId = trim((string) ($order->target_attempt_id ?? ''));
        if ($attemptId === '') {
            return null;
        }

        return DB::table('unified_access_projections')
            ->where('attempt_id', $attemptId)
            ->first();
    }

    private function projectionReady(?object $projection): bool
    {
        if ($projection === null) {
            return false;
        }

        return strtolower(trim((string) ($projection->access_state ?? ''))) === 'ready'
            && strtolower(trim((string) ($projection->report_state ?? ''))) === 'ready';
    }

    private function blockingRejectCode(object $order): ?string
    {
        $event = DB::table('payment_events')
            ->where('order_no', (string) ($order->order_no ?? ''))
            ->where('status', 'rejected')
            ->whereIn('last_error_code', self::BLOCKING_REJECT_CODES)
            ->orderByDesc('updated_at')
            ->first();

        return $event === null ? null : (string) ($event->last_error_code ?? '');
    }

    private function hasPostCommitFailedEvent(object $order): bool
    {
        return DB::table('payment_events')
            ->where('order_no', (string) ($order->order_no ?? ''))
            ->where('status', 'post_commit_failed')
            ->exists();
    }

    private function hasPaymentEvent(object $order): bool
    {
        return DB::table('payment_events')
            ->where('order_no', (string) ($order->order_no ?? ''))
            ->exists();
    }

    private function missingProviderTradeEvidence(object $order): bool
    {
        return trim((string) ($order->external_trade_no ?? '')) === ''
            && trim((string) ($order->provider_trade_no ?? '')) === '';
    }

    private function redactedRef(string $orderNo): string
    {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return 'missing';
        }

        return 'sha256:'.substr(hash('sha256', $orderNo), 0, 16);
    }
}
