<?php

declare(strict_types=1);

namespace App\Services\Commerce\Compensation;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\OrderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PendingOrderCompensationService
{
    public function __construct(
        private readonly PaymentLifecycleGatewayRegistry $gateways,
        private readonly OrderManager $orders,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array{
     *     dry_run:bool,
     *     candidate_count:int,
     *     processed_count:int,
     *     queried_count:int,
     *     paid_count:int,
     *     failed_count:int,
     *     canceled_count:int,
     *     expired_count:int,
     *     unresolved_count:int,
     *     unsupported_count:int,
     *     close_attempted_count:int,
     *     close_success_count:int,
     *     results:array<int,array<string,mixed>>
     * }
     */
    public function compensate(array $options = []): array
    {
        $normalized = $this->normalizeOptions($options);
        $candidates = $this->collectCandidates($normalized);

        $summary = [
            'dry_run' => $normalized['dry_run'],
            'candidate_count' => count($candidates),
            'processed_count' => 0,
            'queried_count' => 0,
            'paid_count' => 0,
            'failed_count' => 0,
            'canceled_count' => 0,
            'expired_count' => 0,
            'unresolved_count' => 0,
            'unsupported_count' => 0,
            'close_attempted_count' => 0,
            'close_success_count' => 0,
            'results' => [],
        ];

        foreach ($candidates as $candidate) {
            $summary['processed_count']++;
            $result = $this->processCandidate($candidate, $normalized);
            $summary['results'][] = $result;

            foreach ([
                'queried_count',
                'paid_count',
                'failed_count',
                'canceled_count',
                'expired_count',
                'unresolved_count',
                'unsupported_count',
                'close_attempted_count',
                'close_success_count',
            ] as $counter) {
                $summary[$counter] += (int) ($result[$counter] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function processCandidate(array $candidate, array $options): array
    {
        $order = $candidate['order'];
        $attempt = $candidate['attempt'];
        $provider = strtolower(trim((string) ($candidate['provider'] ?? '')));
        $gateway = $this->gateways->for($provider);
        $reconciledAt = now()->toIso8601String();

        $result = [
            'order_no' => (string) ($order->order_no ?? ''),
            'attempt_id' => is_object($attempt) ? (string) ($attempt->id ?? '') : null,
            'provider' => $provider,
            'queried_count' => 0,
            'paid_count' => 0,
            'failed_count' => 0,
            'canceled_count' => 0,
            'expired_count' => 0,
            'unresolved_count' => 0,
            'unsupported_count' => 0,
            'close_attempted_count' => 0,
            'close_success_count' => 0,
            'status' => 'pending',
            'reason' => null,
        ];

        if ($gateway === null) {
            $result['status'] = 'unsupported';
            $result['reason'] = 'provider lifecycle gateway not registered.';
            $result['unsupported_count'] = 1;
            $result['unresolved_count'] = 1;
            if (! $options['dry_run']) {
                $this->touchReconciled($order, $attempt, $reconciledAt, null, 'provider_not_supported', 'provider lifecycle gateway not registered.');
            }

            return $result;
        }

        $queryContext = $this->buildLifecycleContext($order, $attempt);
        $query = $gateway->queryPaymentStatus($queryContext);
        $result['queried_count'] = 1;
        $result['query'] = $query;

        if ($options['dry_run']) {
            return $this->classifyDryRunResult($result, $query, (bool) $options['close_expired']);
        }

        if (($query['supported'] ?? false) !== true) {
            $result['status'] = 'unsupported';
            $result['reason'] = (string) ($query['reason'] ?? 'provider query unsupported.');
            $result['unsupported_count'] = 1;
            $result['unresolved_count'] = 1;
            $this->touchReconciled($order, $attempt, $reconciledAt, [
                'last_query' => $query,
            ], 'query_unsupported', $result['reason']);

            return $result;
        }

        $queryStatus = strtolower(trim((string) ($query['status'] ?? 'unknown')));
        $providerTradeNo = $this->trimOrNull($query['provider_trade_no'] ?? null);
        $paidAt = $this->trimOrNull($query['paid_at'] ?? null);

        if ($queryStatus === 'paid') {
            $transition = $this->orders->transitionToPaidAtomic(
                (string) ($order->order_no ?? ''),
                (int) ($order->org_id ?? 0),
                $providerTradeNo,
                $paidAt
            );

            if (! ($transition['ok'] ?? false)) {
                $result['status'] = 'unresolved';
                $result['reason'] = (string) ($transition['message'] ?? 'paid transition failed.');
                $result['unresolved_count'] = 1;
                $this->touchReconciled($order, $attempt, $reconciledAt, [
                    'last_query' => $query,
                ], 'transition_failed', $result['reason']);

                return $result;
            }

            $this->touchReconciled($order, $attempt, $reconciledAt, [
                'last_query' => $query,
            ], null, null, [
                'state' => PaymentAttempt::STATE_PAID,
                'provider_trade_no' => $providerTradeNo,
                'verified_at' => $paidAt ?? $reconciledAt,
                'finalized_at' => $paidAt ?? $reconciledAt,
            ]);

            $result['status'] = 'paid';
            $result['paid_count'] = 1;

            return $result;
        }

        if (in_array($queryStatus, ['failed', 'canceled'], true)) {
            $legacyStatus = $queryStatus === 'failed'
                ? Order::STATUS_FAILED
                : Order::STATUS_CANCELED;
            $paymentState = $queryStatus === 'failed'
                ? Order::PAYMENT_STATE_FAILED
                : Order::PAYMENT_STATE_CANCELED;

            $transition = $this->orders->transition((string) ($order->order_no ?? ''), $legacyStatus, (int) ($order->org_id ?? 0), [
                'payment_state' => $paymentState,
                'provider_trade_no' => $providerTradeNo,
                'last_payment_event_at' => $reconciledAt,
                'transitioned_at' => $reconciledAt,
                'closed_at' => $queryStatus === 'canceled' ? $reconciledAt : null,
            ]);

            if (! ($transition['ok'] ?? false)) {
                $result['status'] = 'unresolved';
                $result['reason'] = (string) ($transition['message'] ?? 'terminal transition failed.');
                $result['unresolved_count'] = 1;
                $this->touchReconciled($order, $attempt, $reconciledAt, [
                    'last_query' => $query,
                ], 'transition_failed', $result['reason']);

                return $result;
            }

            $this->touchReconciled($order, $attempt, $reconciledAt, [
                'last_query' => $query,
            ], null, null, [
                'state' => $queryStatus === 'failed'
                    ? PaymentAttempt::STATE_FAILED
                    : PaymentAttempt::STATE_CANCELED,
                'provider_trade_no' => $providerTradeNo,
                'verified_at' => $reconciledAt,
                'finalized_at' => $reconciledAt,
            ]);

            $result['status'] = $queryStatus;
            $result[$queryStatus === 'failed' ? 'failed_count' : 'canceled_count'] = 1;

            return $result;
        }

        if ($queryStatus === 'pending' && $options['close_expired'] === true) {
            $close = $gateway->closePayment($queryContext);
            $result['close'] = $close;
            $result['close_attempted_count'] = 1;

            if (($close['ok'] ?? false) === true && ($close['supported'] ?? false) === true) {
                $closedAt = $this->trimOrNull($close['closed_at'] ?? null) ?? $reconciledAt;
                $closeTradeNo = $this->trimOrNull($close['provider_trade_no'] ?? null) ?? $providerTradeNo;

                $transition = $this->orders->transition((string) ($order->order_no ?? ''), 'expired', (int) ($order->org_id ?? 0), [
                    'payment_state' => Order::PAYMENT_STATE_EXPIRED,
                    'provider_trade_no' => $closeTradeNo,
                    'last_payment_event_at' => $closedAt,
                    'expired_at' => $closedAt,
                    'closed_at' => $closedAt,
                    'transitioned_at' => $closedAt,
                ]);

                if (! ($transition['ok'] ?? false)) {
                    $result['status'] = 'unresolved';
                    $result['reason'] = (string) ($transition['message'] ?? 'expire transition failed.');
                    $result['unresolved_count'] = 1;
                    $this->touchReconciled($order, $attempt, $reconciledAt, [
                        'last_query' => $query,
                        'last_close' => $close,
                    ], 'transition_failed', $result['reason']);

                    return $result;
                }

                $this->touchReconciled($order, $attempt, $reconciledAt, [
                    'last_query' => $query,
                    'last_close' => $close,
                ], null, null, [
                    'state' => PaymentAttempt::STATE_EXPIRED,
                    'provider_trade_no' => $closeTradeNo,
                    'verified_at' => $closedAt,
                    'finalized_at' => $closedAt,
                ]);

                $result['status'] = 'expired';
                $result['expired_count'] = 1;
                $result['close_success_count'] = 1;

                return $result;
            }

            $result['status'] = 'unresolved';
            $result['reason'] = (string) ($close['reason'] ?? 'close payment unresolved.');
            $result['unresolved_count'] = 1;
            $this->touchReconciled($order, $attempt, $reconciledAt, [
                'last_query' => $query,
                'last_close' => $close,
            ], 'close_failed', $result['reason']);

            return $result;
        }

        $result['status'] = 'unresolved';
        $result['reason'] = (string) ($query['reason'] ?? 'provider payment state unresolved.');
        $result['unresolved_count'] = 1;
        $this->touchReconciled($order, $attempt, $reconciledAt, [
            'last_query' => $query,
        ], $queryStatus === 'unknown' ? 'query_unknown' : null, $result['reason']);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function classifyDryRunResult(array $result, array $query, bool $closeExpired): array
    {
        if (($query['supported'] ?? false) !== true) {
            $result['status'] = 'unsupported';
            $result['unsupported_count'] = 1;
            $result['unresolved_count'] = 1;

            return $result;
        }

        $queryStatus = strtolower(trim((string) ($query['status'] ?? 'unknown')));
        $result['status'] = $queryStatus;

        return match ($queryStatus) {
            'paid' => array_replace($result, ['paid_count' => 1]),
            'failed' => array_replace($result, ['failed_count' => 1]),
            'canceled' => array_replace($result, ['canceled_count' => 1]),
            'pending' => $closeExpired
                ? array_replace($result, ['close_attempted_count' => 1])
                : array_replace($result, ['unresolved_count' => 1]),
            default => array_replace($result, ['unresolved_count' => 1]),
        };
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,mixed>>
     */
    private function collectCandidates(array $options): array
    {
        if ($options['attempt'] !== null) {
            $attempt = $this->orders->findPaymentAttemptById($options['attempt']);
            if (! $attempt) {
                return [];
            }

            $order = $this->orders->findOrderByOrderNo((string) ($attempt->order_no ?? ''), (int) ($attempt->org_id ?? 0));
            if (! $order || ! $this->matchesProvider($order, $attempt, $options['provider'])) {
                return [];
            }

            return $this->passesCandidateGuards($order, $attempt, $options, true)
                ? [$this->makeCandidate($order, $attempt)]
                : [];
        }

        if ($options['order'] !== null) {
            $orderQuery = DB::table('orders')
                ->where('order_no', $options['order']);
            if ($options['provider'] !== null) {
                $orderQuery->where('provider', $options['provider']);
            }
            $order = $orderQuery->orderByDesc('created_at')->first();
            if (! $order) {
                return [];
            }

            $attempt = $this->orders->latestPaymentAttemptForOrder(
                (string) ($order->order_no ?? ''),
                (int) ($order->org_id ?? 0)
            );

            return $this->passesCandidateGuards($order, $attempt, $options, true)
                ? [$this->makeCandidate($order, $attempt)]
                : [];
        }

        $paymentStates = [Order::PAYMENT_STATE_PENDING];
        if ($options['include_created']) {
            $paymentStates[] = Order::PAYMENT_STATE_CREATED;
        }

        $orders = DB::table('orders')
            ->whereIn('payment_state', $paymentStates)
            ->when($options['provider'] !== null, fn ($query) => $query->where('provider', $options['provider']))
            ->orderBy('updated_at')
            ->orderBy('created_at')
            ->limit(max($options['limit'] * 5, $options['limit']))
            ->get();

        $candidates = [];
        foreach ($orders as $order) {
            $attempt = $this->orders->latestPaymentAttemptForOrder(
                (string) ($order->order_no ?? ''),
                (int) ($order->org_id ?? 0)
            );
            if (! $this->passesCandidateGuards($order, $attempt, $options, false)) {
                continue;
            }

            $candidates[] = $this->makeCandidate($order, $attempt);
            if (count($candidates) >= $options['limit']) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function normalizeOptions(array $options): array
    {
        $limit = (int) ($options['limit'] ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }

        $olderThanMinutes = (int) ($options['older_than_minutes'] ?? 30);
        if ($olderThanMinutes < 0) {
            $olderThanMinutes = 30;
        }

        return [
            'provider' => $this->trimOrNull($options['provider'] ?? null),
            'order' => $this->trimOrNull($options['order'] ?? null),
            'attempt' => $this->trimOrNull($options['attempt'] ?? null),
            'limit' => $limit,
            'older_than_minutes' => $olderThanMinutes,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
            'close_expired' => (bool) ($options['close_expired'] ?? false),
            'only_stale' => (bool) ($options['only_stale'] ?? false),
            'include_created' => (bool) ($options['include_created'] ?? false),
        ];
    }

    private function passesCandidateGuards(object $order, ?object $attempt, array $options, bool $targeted): bool
    {
        $paymentState = Order::normalizePaymentState((string) ($order->payment_state ?? ''), (string) ($order->status ?? ''));
        $grantState = Order::normalizeGrantState((string) ($order->grant_state ?? ''), (string) ($order->status ?? ''));

        if (! in_array($paymentState, array_filter([
            Order::PAYMENT_STATE_PENDING,
            $options['include_created'] ? Order::PAYMENT_STATE_CREATED : null,
        ]), true)) {
            return false;
        }

        if ($grantState === Order::GRANT_STATE_GRANTED) {
            return false;
        }

        if ($attempt !== null) {
            $attemptState = PaymentAttempt::normalizedState((string) ($attempt->state ?? ''));
            if (PaymentAttempt::isFinalState($attemptState)) {
                return false;
            }
        } elseif (! $targeted) {
            return false;
        }

        if (! $targeted || $options['only_stale']) {
            return $this->isStale($order, $attempt, $options['older_than_minutes']);
        }

        return true;
    }

    private function isStale(object $order, ?object $attempt, int $olderThanMinutes): bool
    {
        $lastActivity = $this->resolveLastActivityAt($order, $attempt);
        if (! $lastActivity) {
            return false;
        }

        return $lastActivity->lte(now()->subMinutes($olderThanMinutes));
    }

    private function resolveLastActivityAt(object $order, ?object $attempt): ?Carbon
    {
        $timestamps = array_filter([
            $attempt?->finalized_at ?? null,
            $attempt?->verified_at ?? null,
            $attempt?->callback_received_at ?? null,
            $attempt?->client_presented_at ?? null,
            $attempt?->provider_created_at ?? null,
            $attempt?->initiated_at ?? null,
            $order->last_payment_event_at ?? null,
            $order->updated_at ?? null,
            $order->created_at ?? null,
        ]);

        $latest = null;
        foreach ($timestamps as $timestamp) {
            try {
                $candidate = $timestamp instanceof Carbon ? $timestamp : Carbon::parse((string) $timestamp);
            } catch (\Throwable) {
                continue;
            }

            if ($latest === null || $candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLifecycleContext(object $order, ?object $attempt): array
    {
        return [
            'order_id' => $order->id ?? null,
            'order_no' => $order->order_no ?? null,
            'provider' => $attempt->provider ?? $order->provider ?? null,
            'channel' => $attempt->channel ?? $order->channel ?? null,
            'provider_app' => $attempt->provider_app ?? $order->provider_app ?? null,
            'provider_trade_no' => $attempt->provider_trade_no ?? $order->provider_trade_no ?? null,
            'external_trade_no' => $attempt->external_trade_no ?? $order->external_trade_no ?? null,
            'provider_session_ref' => $attempt->provider_session_ref ?? null,
            'pay_scene' => $attempt->pay_scene ?? null,
            'amount_expected' => $attempt->amount_expected ?? $order->amount_cents ?? $order->amount_total ?? null,
            'currency' => $attempt->currency ?? $order->currency ?? null,
        ];
    }

    private function touchReconciled(
        object $order,
        ?object $attempt,
        string $reconciledAt,
        ?array $meta,
        ?string $errorCode,
        ?string $errorMessage,
        ?array $attemptUpdates = null
    ): void {
        $this->orders->touchReconciledLedger(
            (string) ($order->order_no ?? ''),
            (int) ($order->org_id ?? 0),
            $reconciledAt
        );

        if ($attempt === null) {
            return;
        }

        $updates = $attemptUpdates ?? [];
        if ($meta !== null && $meta !== []) {
            $updates['meta_json'] = [
                'compensation' => $meta,
            ];
        }
        if ($errorCode !== null) {
            $updates['last_error_code'] = $errorCode;
        }
        if ($errorMessage !== null) {
            $updates['last_error_message'] = mb_substr($errorMessage, 0, 255);
        }

        $this->orders->advancePaymentAttempt((string) ($attempt->id ?? ''), $updates);
    }

    private function matchesProvider(object $order, ?object $attempt, ?string $provider): bool
    {
        if ($provider === null) {
            return true;
        }

        $candidateProvider = strtolower(trim((string) ($attempt->provider ?? $order->provider ?? '')));

        return $candidateProvider === $provider;
    }

    /**
     * @return array<string,mixed>
     */
    private function makeCandidate(object $order, ?object $attempt): array
    {
        return [
            'order' => $order,
            'attempt' => $attempt,
            'provider' => strtolower(trim((string) ($attempt->provider ?? $order->provider ?? ''))),
        ];
    }

    private function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
