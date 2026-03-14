<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\Support;

use App\Models\Order;
use App\Services\Commerce\OrderManager;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OrderLinkageSupport
{
    /**
     * @return Builder<Order>
     */
    public function query(): Builder
    {
        $query = Order::query()
            ->withoutGlobalScopes()
            ->select('orders.*');

        if (SchemaBaseline::hasTable('attempts')) {
            $query
                ->selectSub($this->attemptField('locale'), 'attempt_locale')
                ->selectSub($this->attemptField('region'), 'attempt_region')
                ->selectSub($this->attemptField('scale_code'), 'attempt_scale_code')
                ->selectSub($this->attemptField('channel'), 'attempt_channel');
        }

        if (SchemaBaseline::hasTable('results')) {
            $query->selectSub($this->resultField('type_code'), 'latest_result_type_code');
        }

        if (SchemaBaseline::hasTable('payment_events')) {
            $query
                ->selectSub($this->paymentField('id'), 'latest_payment_event_id')
                ->selectSub($this->paymentField('provider_event_id'), 'latest_provider_event_id')
                ->selectSub($this->paymentField('status'), 'latest_payment_status')
                ->selectSub($this->paymentField('handle_status'), 'latest_handle_status')
                ->selectSub($this->paymentField('signature_ok'), 'latest_signature_ok')
                ->selectSub($this->paymentField('reason'), 'latest_payment_reason')
                ->selectSub($this->paymentField('last_error_message'), 'latest_payment_error')
                ->selectSub($this->paymentField('requested_sku'), 'latest_requested_sku')
                ->selectSub($this->paymentField('effective_sku'), 'latest_effective_sku')
                ->selectSub($this->paymentField('processed_at'), 'latest_payment_processed_at')
                ->selectSub($this->paymentField('handled_at'), 'latest_payment_handled_at');
        }

        if (SchemaBaseline::hasTable('benefit_grants')) {
            $query
                ->selectSub($this->benefitField('id'), 'latest_benefit_grant_id')
                ->selectSub($this->benefitField('attempt_id'), 'latest_benefit_attempt_id')
                ->selectSub($this->benefitField('benefit_code'), 'latest_benefit_code')
                ->selectSub($this->benefitField('status'), 'latest_benefit_status')
                ->selectSub($this->benefitField('scope'), 'latest_benefit_scope')
                ->selectSub($this->benefitField('expires_at'), 'latest_benefit_expires_at')
                ->selectSub($this->activeBenefitExistsField(), 'has_active_benefit_grant');
        } else {
            $query->selectRaw('0 as has_active_benefit_grant');
        }

        if (SchemaBaseline::hasTable('report_snapshots')) {
            $query
                ->selectSub($this->snapshotField('attempt_id'), 'latest_snapshot_attempt_id')
                ->selectSub($this->snapshotField('status'), 'latest_snapshot_status')
                ->selectSub($this->snapshotField('last_error'), 'latest_snapshot_last_error')
                ->selectSub($this->snapshotField('updated_at'), 'latest_snapshot_updated_at')
                ->selectSub($this->snapshotExistsField(), 'has_report_snapshot');
        } else {
            $query->selectRaw('0 as has_report_snapshot');
        }

        if (SchemaBaseline::hasTable('report_jobs')) {
            $query
                ->selectSub($this->reportJobField('status'), 'latest_report_job_status')
                ->selectSub($this->reportJobField('last_error'), 'latest_report_job_error');
        }

        if (SchemaBaseline::hasTable('shares')) {
            $query->selectSub($this->shareField('id'), 'latest_share_id');
        }

        if (SchemaBaseline::hasTable('email_outbox')
            && SchemaBaseline::hasColumn('email_outbox', 'attempt_id')
            && SchemaBaseline::hasColumn('email_outbox', 'sent_at')) {
            $query->selectSub($this->deliveryEmailSentAtField(), 'latest_delivery_email_sent_at');
        }

        if (SchemaBaseline::hasColumn('orders', 'contact_email_hash')) {
            $query->selectRaw("case when trim(coalesce(orders.contact_email_hash, '')) <> '' then 1 else 0 end as contact_email_present");
        } else {
            $query->selectRaw('0 as contact_email_present');
        }

        return $query;
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applySearch(Builder $query, string $search): void
    {
        $needle = trim($search);
        if ($needle === '') {
            return;
        }

        $like = '%'.$needle.'%';
        $emailHash = $this->contactEmailHash($needle);

        $query->where(function (Builder $builder) use ($needle, $like, $emailHash): void {
            $builder
                ->where('orders.order_no', 'like', $like)
                ->orWhere('orders.target_attempt_id', 'like', $like);

            if ($emailHash !== null && SchemaBaseline::hasColumn('orders', 'contact_email_hash')) {
                $builder->orWhere('orders.contact_email_hash', $emailHash);
            }

            if (SchemaBaseline::hasTable('users') && SchemaBaseline::hasColumn('users', 'email')) {
                $builder->orWhereExists(function (QueryBuilder $userQuery) use ($needle, $like): void {
                    $userQuery
                        ->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.id', 'orders.user_id')
                        ->where(function (QueryBuilder $nested) use ($needle, $like): void {
                            $nested
                                ->where('users.email', 'like', $like)
                                ->orWhereRaw('lower(users.email) = ?', [mb_strtolower($needle, 'UTF-8')]);
                        });
                });
            }

            if (SchemaBaseline::hasTable('shares')) {
                $builder->orWhereExists(function (QueryBuilder $shareQuery) use ($like): void {
                    $shareQuery
                        ->selectRaw('1')
                        ->from('shares')
                        ->whereColumn('shares.attempt_id', 'orders.target_attempt_id')
                        ->where('shares.id', 'like', $like);
                });
            }

            if (SchemaBaseline::hasTable('benefit_grants')) {
                $builder->orWhereExists(function (QueryBuilder $grantQuery) use ($like): void {
                    $grantQuery
                        ->selectRaw('1')
                        ->from('benefit_grants')
                        ->where(function (QueryBuilder $nested): void {
                            $nested->whereColumn('benefit_grants.order_no', 'orders.order_no')
                                ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
                        })
                        ->where('benefit_grants.attempt_id', 'like', $like);
                });
            }

            if (SchemaBaseline::hasTable('report_snapshots')) {
                $builder->orWhereExists(function (QueryBuilder $snapshotQuery) use ($like): void {
                    $snapshotQuery
                        ->selectRaw('1')
                        ->from('report_snapshots')
                        ->where(function (QueryBuilder $nested): void {
                            $nested->whereColumn('report_snapshots.attempt_id', 'orders.target_attempt_id')
                                ->orWhereColumn('report_snapshots.order_no', 'orders.order_no');
                        })
                        ->where('report_snapshots.attempt_id', 'like', $like);
                });
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function distinctOrderOptions(string $column): array
    {
        if (! SchemaBaseline::hasColumn('orders', $column)) {
            return [];
        }

        return DB::table('orders')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->mapWithKeys(fn ($value): array => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function distinctAttemptOptions(string $column): array
    {
        if (! SchemaBaseline::hasTable('attempts') || ! SchemaBaseline::hasColumn('attempts', $column)) {
            return [];
        }

        return DB::table('attempts')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->mapWithKeys(fn ($value): array => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function distinctPaymentStatusOptions(): array
    {
        if (! SchemaBaseline::hasTable('payment_events') || ! SchemaBaseline::hasColumn('payment_events', 'status')) {
            return [];
        }

        return DB::table('payment_events')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status', 'status')
            ->mapWithKeys(fn ($value): array => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function distinctBenefitCodeOptions(): array
    {
        if (! SchemaBaseline::hasTable('benefit_grants') || ! SchemaBaseline::hasColumn('benefit_grants', 'benefit_code')) {
            return [];
        }

        return DB::table('benefit_grants')
            ->whereNotNull('benefit_code')
            ->where('benefit_code', '!=', '')
            ->distinct()
            ->orderBy('benefit_code')
            ->pluck('benefit_code', 'benefit_code')
            ->mapWithKeys(fn ($value): array => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function distinctSkuOptions(): array
    {
        $values = collect();

        foreach (['requested_sku', 'effective_sku', 'sku'] as $column) {
            if (! SchemaBaseline::hasColumn('orders', $column)) {
                continue;
            }

            $values = $values->merge(
                DB::table('orders')
                    ->whereNotNull($column)
                    ->where($column, '!=', '')
                    ->distinct()
                    ->pluck($column)
                    ->map(fn ($value): string => (string) $value)
            );
        }

        if (SchemaBaseline::hasTable('payment_events')) {
            foreach (['requested_sku', 'effective_sku'] as $column) {
                if (! SchemaBaseline::hasColumn('payment_events', $column)) {
                    continue;
                }

                $values = $values->merge(
                    DB::table('payment_events')
                        ->whereNotNull($column)
                        ->where($column, '!=', '')
                        ->distinct()
                        ->pluck($column)
                        ->map(fn ($value): string => (string) $value)
                );
            }
        }

        return $values
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applySkuFilter(Builder $query, ?string $value): void
    {
        $sku = trim((string) $value);
        if ($sku === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($sku): void {
            foreach (['requested_sku', 'effective_sku', 'sku'] as $column) {
                if (SchemaBaseline::hasColumn('orders', $column)) {
                    $builder->orWhere('orders.'.$column, $sku);
                }
            }

            if (SchemaBaseline::hasTable('payment_events')) {
                $builder->orWhereExists(function (QueryBuilder $paymentQuery) use ($sku): void {
                    $paymentQuery
                        ->selectRaw('1')
                        ->from('payment_events')
                        ->whereColumn('payment_events.order_no', 'orders.order_no')
                        ->where(function (QueryBuilder $nested) use ($sku): void {
                            $nested->where('payment_events.requested_sku', $sku)
                                ->orWhere('payment_events.effective_sku', $sku);
                        });
                });
            }
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyBenefitCodeFilter(Builder $query, ?string $value): void
    {
        $benefitCode = trim((string) $value);
        if ($benefitCode === '' || ! SchemaBaseline::hasTable('benefit_grants')) {
            return;
        }

        $query->whereExists(function (QueryBuilder $grantQuery) use ($benefitCode): void {
            $grantQuery
                ->selectRaw('1')
                ->from('benefit_grants')
                ->where('benefit_grants.benefit_code', $benefitCode)
                ->where(function (QueryBuilder $nested): void {
                    $nested->whereColumn('benefit_grants.order_no', 'orders.order_no')
                        ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
                });
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyLocaleFilter(Builder $query, ?string $value): void
    {
        $locale = trim((string) $value);
        if ($locale === '' || ! SchemaBaseline::hasTable('attempts')) {
            return;
        }

        $query->whereExists(function (QueryBuilder $attemptQuery) use ($locale): void {
            $attemptQuery
                ->selectRaw('1')
                ->from('attempts')
                ->whereColumn('attempts.id', 'orders.target_attempt_id')
                ->where('attempts.locale', $locale);
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyRegionFilter(Builder $query, ?string $value): void
    {
        $region = trim((string) $value);
        if ($region === '' || ! SchemaBaseline::hasTable('attempts')) {
            return;
        }

        $query->whereExists(function (QueryBuilder $attemptQuery) use ($region): void {
            $attemptQuery
                ->selectRaw('1')
                ->from('attempts')
                ->whereColumn('attempts.id', 'orders.target_attempt_id')
                ->where('attempts.region', $region);
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyPaymentStatusFilter(Builder $query, ?string $value): void
    {
        $paymentStatus = trim((string) $value);
        if ($paymentStatus === '' || ! SchemaBaseline::hasTable('payment_events')) {
            return;
        }

        $query->whereExists(function (QueryBuilder $paymentQuery) use ($paymentStatus): void {
            $paymentQuery
                ->selectRaw('1')
                ->from('payment_events')
                ->whereColumn('payment_events.order_no', 'orders.order_no')
                ->where('payment_events.status', $paymentStatus);
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyPaidSuccessFilter(Builder $query, ?bool $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value) {
            $query->where($this->paidLikeOrderClause());

            return;
        }

        $query->where($this->notPaidLikeOrderClause());
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyHasActiveBenefitGrantFilter(Builder $query, ?bool $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value) {
            $query->whereExists($this->activeBenefitExistsQuery());

            return;
        }

        $query->whereNotExists($this->activeBenefitExistsQuery());
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyHasReportSnapshotFilter(Builder $query, ?bool $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value) {
            $query->whereExists($this->snapshotExistsQuery());

            return;
        }

        $query->whereNotExists($this->snapshotExistsQuery());
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyPdfReadyFilter(Builder $query, ?bool $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value) {
            $query
                ->where($this->paidLikeOrderClause())
                ->whereExists($this->snapshotReadyExistsQuery());

            return;
        }

        $query->where(function (Builder $builder): void {
            $builder
                ->where($this->notPaidLikeOrderClause())
                ->orWhereNotExists($this->snapshotReadyExistsQuery());
        });
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyUnlockStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        match ($status) {
            'unlocked' => $query->whereExists($this->activeBenefitExistsQuery()),
            'paid_no_grant' => $query
                ->where($this->paidLikeOrderClause())
                ->whereNotExists($this->activeBenefitExistsQuery())
                ->where($this->notRefundedLikeOrderClause()),
            'refunded' => $query->where($this->refundedLikeOrderClause()),
            'pending' => $query
                ->whereNotExists($this->activeBenefitExistsQuery())
                ->where($this->notPaidLikeOrderClause())
                ->where($this->notRefundedLikeOrderClause()),
            default => null,
        };
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyDeliveryStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        match ($status) {
            'delivered' => $query->whereExists($this->deliveryEmailExistsQuery()),
            'ready' => $query
                ->where($this->paidLikeOrderClause())
                ->whereNotExists($this->deliveryEmailExistsQuery())
                ->whereExists($this->snapshotReadyExistsQuery()),
            'failed' => $query->where(function (Builder $builder): void {
                $builder->whereExists($this->snapshotFailedExistsQuery());

                if (SchemaBaseline::hasTable('report_jobs')) {
                    $builder->orWhereExists($this->reportJobFailedExistsQuery());
                }
            }),
            'pending' => $query
                ->whereNotExists($this->deliveryEmailExistsQuery())
                ->where(function (Builder $builder): void {
                    $builder->where($this->notPaidLikeOrderClause())
                        ->orWhereNotExists($this->snapshotReadyExistsQuery());
                }),
            default => null,
        };
    }

    /**
     * @return array{label:string,state:string}
     */
    public function orderStatus(object $order): array
    {
        $label = $this->stringOrDash($order->status ?? null);

        return ['label' => $label, 'state' => $this->statusState((string) ($order->status ?? ''))];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function paymentStatus(object $order): array
    {
        $status = trim((string) ($order->latest_payment_status ?? ''));
        if ($status === '') {
            return ['label' => 'missing', 'state' => 'gray'];
        }

        return ['label' => $status, 'state' => $this->statusState($status)];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function unlockStatus(object $order): array
    {
        if ($this->hasActiveBenefitGrant($order)) {
            return ['label' => 'unlocked', 'state' => 'success'];
        }

        if ($this->isRefundedLike((string) ($order->status ?? ''))) {
            return ['label' => 'refunded', 'state' => 'danger'];
        }

        if ($this->isPaidLike((string) ($order->status ?? '')) || $this->isPaidLike((string) ($order->latest_payment_status ?? ''))) {
            return ['label' => 'paid_no_grant', 'state' => 'warning'];
        }

        return ['label' => 'pending', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function snapshotStatus(object $order): array
    {
        $status = trim((string) ($order->latest_snapshot_status ?? ''));
        if ($status === '') {
            return ['label' => 'missing', 'state' => 'gray'];
        }

        return ['label' => $status, 'state' => $this->statusState($status)];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function pdfStatus(object $order): array
    {
        return $this->isPdfReady($order)
            ? ['label' => 'ready', 'state' => 'success']
            : ['label' => 'unavailable', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function deliveryStatus(object $order): array
    {
        $lastSentAt = trim((string) ($order->latest_delivery_email_sent_at ?? ''));
        $snapshot = strtolower(trim((string) ($order->latest_snapshot_status ?? '')));
        $jobStatus = strtolower(trim((string) ($order->latest_report_job_status ?? '')));

        if ($snapshot === 'failed' || $snapshot === 'error' || in_array($jobStatus, ['failed', 'error'], true)) {
            return ['label' => 'failed', 'state' => 'danger'];
        }

        if ($lastSentAt !== '') {
            return ['label' => 'delivered', 'state' => 'success'];
        }

        if ($this->isPdfReady($order)) {
            return ['label' => 'ready', 'state' => 'warning'];
        }

        return ['label' => 'pending', 'state' => 'gray'];
    }

    public function requestedSku(object $order): string
    {
        foreach ([
            $order->requested_sku ?? null,
            $order->latest_requested_sku ?? null,
            $order->sku ?? null,
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '-';
    }

    public function effectiveSku(object $order): string
    {
        foreach ([
            $order->effective_sku ?? null,
            $order->latest_effective_sku ?? null,
            $order->sku ?? null,
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '-';
    }

    public function contactEmailPresent(object $order): bool
    {
        return $this->truthy($order->contact_email_present ?? null)
            || trim((string) ($order->contact_email_hash ?? '')) !== '';
    }

    /**
     * @return array{
     *     headline: array<string, array{label:string,state:string}>,
     *     order_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     payment_events: list<array{
     *         provider_event_id:string,
     *         status:array{label:string,state:string},
     *         handle_status:array{label:string,state:string},
     *         signature:array{label:string,state:string},
     *         reason:string,
     *         processed_at:string,
     *         handled_at:string,
     *         error:string
     *     }>,
     *     benefit_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     report_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     attempt_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     exception_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     links: list<array{label:string,url:string,kind:string}>
     * }
     */
    public function buildDetail(Order $order): array
    {
        $orderRow = DB::table('orders')->where('id', (string) $order->getKey())->first() ?? $order;
        $resolvedAttemptId = $this->resolvedAttemptId($order);
        $paymentEvents = $this->paymentEvents((string) ($orderRow->order_no ?? ''));
        $grants = $this->benefitGrants((string) ($orderRow->order_no ?? ''), $resolvedAttemptId);
        $activeGrant = $this->activeGrant($grants);
        $snapshot = $this->reportSnapshot((string) ($orderRow->order_no ?? ''), $resolvedAttemptId);
        $reportPayload = $this->decodeJson($snapshot->report_json ?? null);
        $reportJob = $this->reportJob($resolvedAttemptId);
        $attempt = $this->attempt($resolvedAttemptId);
        $result = $this->result($resolvedAttemptId);
        $share = $this->share($resolvedAttemptId);
        $delivery = app(OrderManager::class)->presentOrderDelivery($orderRow);
        $deliveryState = is_array($delivery['delivery'] ?? null) ? $delivery['delivery'] : [];
        $attribution = app(OrderManager::class)->extractAttributionFromOrder($orderRow);
        $shareId = trim((string) (($share->id ?? null) ?: ($attribution['share_id'] ?? '')));
        $headline = [
            'order' => $this->orderStatus($order),
            'payment' => $this->paymentStatus($order),
            'unlock' => $this->unlockStatus($order),
            'snapshot' => $this->snapshotStatus($order),
            'pdf' => $this->pdfStatus($order),
        ];

        $orderSummary = [
            'fields' => [
                $this->field('order_no', $this->stringOrDash($orderRow->order_no ?? null)),
                $this->field('org_id', $this->stringOrDash($orderRow->org_id ?? null)),
                $this->field('amount', $this->formatMoney($orderRow), $this->stringOrDash($orderRow->amount_cents ?? null).' cents'),
                $this->field('provider', $this->stringOrDash($orderRow->provider ?? null)),
                $this->pillField('order_status', $headline['order']['label'], $headline['order']['state']),
                $this->pillField('payment_status', $headline['payment']['label'], $headline['payment']['state']),
                $this->field('created_at', $this->formatTimestamp($orderRow->created_at ?? null)),
                $this->field('paid_at', $this->formatTimestamp($orderRow->paid_at ?? null)),
                $this->field('updated_at', $this->formatTimestamp($orderRow->updated_at ?? null)),
                $this->field('target_attempt_id', $this->stringOrDash($orderRow->target_attempt_id ?? null), $resolvedAttemptId !== null && $resolvedAttemptId !== trim((string) ($orderRow->target_attempt_id ?? '')) ? 'resolved='.$resolvedAttemptId : null),
                $this->field('requested_sku', $this->requestedSku($order)),
                $this->field('effective_sku', $this->effectiveSku($order)),
                $this->field('benefit_code', $this->stringOrDash($activeGrant->benefit_code ?? ($order->latest_benefit_code ?? null))),
                $this->pillField(
                    'contact_email_present',
                    $this->truthy($deliveryState['contact_email_present'] ?? null) ? 'present' : 'missing',
                    $this->truthy($deliveryState['contact_email_present'] ?? null) ? 'success' : 'gray'
                ),
            ],
            'notes' => ['Frontend order drill-through is available in the action bar. Raw order meta stays hidden.'],
        ];

        $benefitNotes = ['Unlock truth is derived from benefit_grants, not only order status.'];
        if ($activeGrant === null) {
            $benefitNotes[] = 'No active grant found yet for this order/attempt pair.';
        }

        $benefitSummary = [
            'fields' => [
                $this->pillField('active_grant', $activeGrant !== null ? 'present' : 'missing', $activeGrant !== null ? 'success' : 'gray'),
                $this->pillField('unlock_status', $headline['unlock']['label'], $headline['unlock']['state']),
                $this->field('benefit_grant_id', $this->stringOrDash($activeGrant->id ?? ($order->latest_benefit_grant_id ?? null))),
                $this->field('benefit_code', $this->stringOrDash($activeGrant->benefit_code ?? ($order->latest_benefit_code ?? null))),
                $this->field('scope', $this->stringOrDash($activeGrant->scope ?? ($order->latest_benefit_scope ?? null))),
                $this->field('expires_at', $this->formatTimestamp($activeGrant->expires_at ?? ($order->latest_benefit_expires_at ?? null))),
                $this->field('source_order_id', $this->stringOrDash($activeGrant->source_order_id ?? null)),
                $this->field('source_event_id', $this->stringOrDash($activeGrant->source_event_id ?? null)),
            ],
            'notes' => $benefitNotes,
        ];

        $reportNotes = ['Report JSON and full snapshot payload stay hidden on this page.'];
        if (filled($snapshot->last_error ?? null)) {
            $reportNotes[] = 'Snapshot error is summarized below without expanding report payload JSON.';
        }

        $deliveryLabel = $this->deliveryStatus($order);
        $reportSummary = [
            'fields' => [
                $this->pillField('snapshot', $snapshot !== null ? 'present' : 'missing', $snapshot !== null ? 'success' : 'gray'),
                $this->pillField('snapshot_status', $headline['snapshot']['label'], $headline['snapshot']['state']),
                $this->field('snapshot_last_error', $this->shortText($snapshot->last_error ?? null)),
                $this->field('variant', $this->stringOrDash($reportPayload['variant'] ?? null)),
                $this->field('access_level', $this->stringOrDash($reportPayload['access_level'] ?? null)),
                $this->pillField(
                    'locked',
                    $this->truthy($reportPayload['locked'] ?? null) ? 'locked' : 'unlocked',
                    $this->truthy($reportPayload['locked'] ?? null) ? 'warning' : 'success'
                ),
                $this->pillField('pdf_ready', $headline['pdf']['label'], $headline['pdf']['state']),
                $this->pillField('delivery_status', $deliveryLabel['label'], $deliveryLabel['state']),
                $this->field('last_delivery_email_sent_at', $this->stringOrDash($deliveryState['last_delivery_email_sent_at'] ?? null)),
                $this->pillField('claim_email', $this->truthy($deliveryState['can_request_claim_email'] ?? null) ? 'available' : 'not_available', $this->truthy($deliveryState['can_request_claim_email'] ?? null) ? 'warning' : 'gray'),
                $this->pillField('resend_delivery', $this->truthy($deliveryState['can_resend'] ?? null) ? 'eligible' : 'not_eligible', $this->truthy($deliveryState['can_resend'] ?? null) ? 'warning' : 'gray'),
                $this->field(
                    'report_job',
                    $this->stringOrDash($reportJob->status ?? null),
                    $this->shortText($reportJob->last_error ?? null)
                ),
            ],
            'notes' => $reportNotes,
        ];

        $attemptNotes = ['Attempt linkage shows result, locale, report, and share breadcrumbs without exposing raw result payload JSON.'];
        $utmSummary = $this->utmSummary($attribution['utm'] ?? null);
        if ($utmSummary !== '-') {
            $attemptNotes[] = 'UTM summary is reduced to compact marketing breadcrumbs only.';
        }

        $attemptSummary = [
            'fields' => [
                $this->field('attempt_id', $resolvedAttemptId ?? '-'),
                $this->field('result_type', $this->stringOrDash($result->type_code ?? null)),
                $this->field('scale_code', $this->stringOrDash($attempt->scale_code ?? ($order->attempt_scale_code ?? null))),
                $this->field('locale', $this->stringOrDash($attempt->locale ?? ($order->attempt_locale ?? null))),
                $this->field('region', $this->stringOrDash($attempt->region ?? ($order->attempt_region ?? null))),
                $this->field('share_id', $shareId !== '' ? $shareId : '-'),
                $this->field('share_click_id', $this->stringOrDash($attribution['share_click_id'] ?? null)),
                $this->field('entrypoint', $this->stringOrDash($attribution['entrypoint'] ?? null)),
                $this->field('utm', $utmSummary),
                $this->field('channel', $this->stringOrDash($attempt->channel ?? ($order->attempt_channel ?? null))),
            ],
            'notes' => $attemptNotes,
        ];

        $paymentSummary = array_map(function (object $event): array {
            return [
                'provider_event_id' => $this->stringOrDash($event->provider_event_id ?? null),
                'status' => [
                    'label' => $this->stringOrDash($event->status ?? null),
                    'state' => $this->statusState((string) ($event->status ?? '')),
                ],
                'handle_status' => [
                    'label' => $this->stringOrDash($event->handle_status ?? null),
                    'state' => $this->statusState((string) ($event->handle_status ?? '')),
                ],
                'signature' => [
                    'label' => $this->truthy($event->signature_ok ?? null) ? 'valid' : 'invalid',
                    'state' => $this->truthy($event->signature_ok ?? null) ? 'success' : 'danger',
                ],
                'reason' => $this->stringOrDash($event->reason ?? null),
                'processed_at' => $this->formatTimestamp($event->processed_at ?? null),
                'handled_at' => $this->formatTimestamp($event->handled_at ?? null),
                'error' => $this->shortText($event->last_error_message ?? null),
            ];
        }, $paymentEvents);

        $exceptionSummary = [
            'fields' => $this->exceptionFields($orderRow, $paymentEvents, $activeGrant, $snapshot, $reportJob, $deliveryState, $resolvedAttemptId),
            'notes' => ['These rules are explicit v1 diagnostics, not a full rule engine or BI funnel.'],
        ];

        return [
            'headline' => $headline,
            'order_summary' => $orderSummary,
            'payment_events' => $paymentSummary,
            'benefit_summary' => $benefitSummary,
            'report_summary' => $reportSummary,
            'attempt_summary' => $attemptSummary,
            'exception_summary' => $exceptionSummary,
            'links' => $this->links($orderRow, $resolvedAttemptId, $shareId !== '' ? $shareId : null, $attempt),
        ];
    }

    private function attemptField(string $column): QueryBuilder
    {
        return DB::table('attempts')
            ->select($column)
            ->whereColumn('attempts.id', 'orders.target_attempt_id')
            ->limit(1);
    }

    private function resultField(string $column): QueryBuilder
    {
        return DB::table('results')
            ->select($column)
            ->whereColumn('results.attempt_id', 'orders.target_attempt_id')
            ->orderByRaw('coalesce(computed_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function paymentField(string $column): QueryBuilder
    {
        return DB::table('payment_events')
            ->select($column)
            ->whereColumn('payment_events.order_no', 'orders.order_no')
            ->orderByRaw('coalesce(processed_at, handled_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function benefitField(string $column): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->select($column)
            ->where(function (QueryBuilder $builder): void {
                $builder->whereColumn('benefit_grants.order_no', 'orders.order_no')
                    ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
            })
            ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function activeBenefitExistsField(): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->selectRaw('1')
            ->where('benefit_grants.status', 'active')
            ->where(function (QueryBuilder $builder): void {
                $builder->whereColumn('benefit_grants.order_no', 'orders.order_no')
                    ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
            })
            ->limit(1);
    }

    private function snapshotField(string $column): QueryBuilder
    {
        return DB::table('report_snapshots')
            ->select($column)
            ->where(function (QueryBuilder $builder): void {
                $builder->whereColumn('report_snapshots.attempt_id', 'orders.target_attempt_id')
                    ->orWhereColumn('report_snapshots.order_no', 'orders.order_no');
            })
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function snapshotExistsField(): QueryBuilder
    {
        return DB::table('report_snapshots')
            ->selectRaw('1')
            ->where(function (QueryBuilder $builder): void {
                $builder->whereColumn('report_snapshots.attempt_id', 'orders.target_attempt_id')
                    ->orWhereColumn('report_snapshots.order_no', 'orders.order_no');
            })
            ->limit(1);
    }

    private function reportJobField(string $column): QueryBuilder
    {
        return DB::table('report_jobs')
            ->select($column)
            ->whereColumn('report_jobs.attempt_id', 'orders.target_attempt_id')
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function shareField(string $column): QueryBuilder
    {
        return DB::table('shares')
            ->select($column)
            ->whereColumn('shares.attempt_id', 'orders.target_attempt_id')
            ->orderByDesc('created_at')
            ->limit(1);
    }

    private function deliveryEmailSentAtField(): QueryBuilder
    {
        return DB::table('email_outbox')
            ->select('sent_at')
            ->whereColumn('email_outbox.attempt_id', 'orders.target_attempt_id')
            ->whereIn('template', ['payment_success', 'report_claim'])
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->limit(1);
    }

    private function activeBenefitExistsQuery(): \Closure
    {
        return function (QueryBuilder $benefitQuery): void {
            $benefitQuery
                ->selectRaw('1')
                ->from('benefit_grants')
                ->where('benefit_grants.status', 'active')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereColumn('benefit_grants.order_no', 'orders.order_no')
                        ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
                });
        };
    }

    private function snapshotReadyExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereColumn('report_snapshots.attempt_id', 'orders.target_attempt_id')
                        ->orWhereColumn('report_snapshots.order_no', 'orders.order_no');
                })
                ->whereRaw("lower(coalesce(report_snapshots.status, '')) in (?, ?, ?)", ['ready', 'full', 'completed']);
        };
    }

    private function snapshotExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereColumn('report_snapshots.attempt_id', 'orders.target_attempt_id')
                        ->orWhereColumn('report_snapshots.order_no', 'orders.order_no');
                });
        };
    }

    private function snapshotFailedExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereColumn('report_snapshots.attempt_id', 'orders.target_attempt_id')
                        ->orWhereColumn('report_snapshots.order_no', 'orders.order_no');
                })
                ->where(function (QueryBuilder $builder): void {
                    $builder
                        ->whereRaw("lower(coalesce(report_snapshots.status, '')) in (?, ?)", ['failed', 'error'])
                        ->orWhereNotNull('report_snapshots.last_error');
                });
        };
    }

    private function reportJobFailedExistsQuery(): \Closure
    {
        return function (QueryBuilder $jobQuery): void {
            $jobQuery
                ->selectRaw('1')
                ->from('report_jobs')
                ->whereColumn('report_jobs.attempt_id', 'orders.target_attempt_id')
                ->whereRaw("lower(coalesce(report_jobs.status, '')) in (?, ?)", ['failed', 'error']);
        };
    }

    private function deliveryEmailExistsQuery(): \Closure
    {
        return function (QueryBuilder $deliveryQuery): void {
            $deliveryQuery
                ->selectRaw('1')
                ->from('email_outbox')
                ->whereColumn('email_outbox.attempt_id', 'orders.target_attempt_id')
                ->whereIn('template', ['payment_success', 'report_claim'])
                ->whereNotNull('sent_at');
        };
    }

    private function paidLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder->where(function (Builder|QueryBuilder $nested): void {
                $nested
                    ->whereRaw("lower(coalesce(orders.status, '')) in (?, ?, ?, ?)", ['paid', 'fulfilled', 'complete', 'completed'])
                    ->orWhereNotNull('orders.paid_at');
            });
        };
    }

    private function refundedLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder->where(function (Builder|QueryBuilder $nested): void {
                $nested
                    ->whereRaw("lower(coalesce(orders.status, '')) like ?", ['%refund%']);

                if (SchemaBaseline::hasColumn('orders', 'refunded_at')) {
                    $nested->orWhereNotNull('orders.refunded_at');
                }
            });
        };
    }

    private function notPaidLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder
                ->whereRaw("lower(coalesce(orders.status, '')) not in (?, ?, ?, ?)", ['paid', 'fulfilled', 'complete', 'completed'])
                ->whereNull('orders.paid_at');
        };
    }

    private function notRefundedLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder->whereRaw("lower(coalesce(orders.status, '')) not like ?", ['%refund%']);

            if (SchemaBaseline::hasColumn('orders', 'refunded_at')) {
                $builder->whereNull('orders.refunded_at');
            }
        };
    }

    /**
     * @return list<object>
     */
    private function paymentEvents(string $orderNo): array
    {
        if ($orderNo === '' || ! SchemaBaseline::hasTable('payment_events')) {
            return [];
        }

        return DB::table('payment_events')
            ->select([
                'provider_event_id',
                'status',
                'handle_status',
                'signature_ok',
                'reason',
                'processed_at',
                'handled_at',
                'last_error_message',
            ])
            ->where('order_no', $orderNo)
            ->orderByRaw('coalesce(processed_at, handled_at, updated_at, created_at) desc')
            ->limit(10)
            ->get()
            ->all();
    }

    /**
     * @return list<object>
     */
    private function benefitGrants(string $orderNo, ?string $attemptId): array
    {
        if (! SchemaBaseline::hasTable('benefit_grants')) {
            return [];
        }

        return DB::table('benefit_grants')
            ->select([
                'id',
                'benefit_code',
                'scope',
                'status',
                'expires_at',
                'source_order_id',
                'source_event_id',
                'meta_json',
            ])
            ->where(function (QueryBuilder $builder) use ($orderNo, $attemptId): void {
                if ($orderNo !== '') {
                    $builder->where('order_no', $orderNo);
                }

                if ($attemptId !== null) {
                    $method = $orderNo !== '' ? 'orWhere' : 'where';
                    $builder->{$method}('attempt_id', $attemptId);
                }
            })
            ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(10)
            ->get()
            ->all();
    }

    private function activeGrant(array $grants): ?object
    {
        foreach ($grants as $grant) {
            if (strtolower(trim((string) ($grant->status ?? ''))) === 'active') {
                return $grant;
            }
        }

        return $grants[0] ?? null;
    }

    private function reportSnapshot(string $orderNo, ?string $attemptId): ?object
    {
        if (! SchemaBaseline::hasTable('report_snapshots')) {
            return null;
        }

        return DB::table('report_snapshots')
            ->where(function (QueryBuilder $builder) use ($orderNo, $attemptId): void {
                if ($attemptId !== null) {
                    $builder->where('attempt_id', $attemptId);
                }

                if ($orderNo !== '') {
                    $method = $attemptId !== null ? 'orWhere' : 'where';
                    $builder->{$method}('order_no', $orderNo);
                }
            })
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->first();
    }

    private function reportJob(?string $attemptId): ?object
    {
        if ($attemptId === null || ! SchemaBaseline::hasTable('report_jobs')) {
            return null;
        }

        return DB::table('report_jobs')
            ->where('attempt_id', $attemptId)
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->first();
    }

    private function attempt(?string $attemptId): ?object
    {
        if ($attemptId === null || ! SchemaBaseline::hasTable('attempts')) {
            return null;
        }

        return DB::table('attempts')->where('id', $attemptId)->first();
    }

    private function result(?string $attemptId): ?object
    {
        if ($attemptId === null || ! SchemaBaseline::hasTable('results')) {
            return null;
        }

        return DB::table('results')
            ->where('attempt_id', $attemptId)
            ->orderByRaw('coalesce(computed_at, updated_at, created_at) desc')
            ->first();
    }

    private function share(?string $attemptId): ?object
    {
        if ($attemptId === null || ! SchemaBaseline::hasTable('shares')) {
            return null;
        }

        return DB::table('shares')
            ->where('attempt_id', $attemptId)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return list<array{label:string,value:string,hint:?string,kind:string,state:?string}>
     */
    private function exceptionFields(
        object $order,
        array $paymentEvents,
        ?object $activeGrant,
        ?object $snapshot,
        ?object $reportJob,
        array $deliveryState,
        ?string $resolvedAttemptId,
    ): array {
        $paymentHandled = collect($paymentEvents)->contains(function (object $event): bool {
            return filled($event->handled_at ?? null)
                || in_array(strtolower(trim((string) ($event->handle_status ?? ''))), ['processed', 'handled', 'succeeded'], true);
        });

        $paid = $this->isPaidLike((string) ($order->status ?? '')) || filled($order->paid_at ?? null);
        $snapshotStatus = strtolower(trim((string) ($snapshot->status ?? '')));
        $reportJobStatus = strtolower(trim((string) ($reportJob->status ?? '')));
        $pdfReady = (bool) ($deliveryState['can_download_pdf'] ?? false);

        return [
            $this->pillField(
                'paid_but_no_grant',
                $paid && $activeGrant === null ? 'triggered' : 'clear',
                $paid && $activeGrant === null ? 'danger' : 'success',
                $paid && $activeGrant === null ? 'Payment succeeded but no active benefit_grant exists.' : null
            ),
            $this->pillField(
                'grant_exists_but_snapshot_missing',
                $activeGrant !== null && $snapshot === null ? 'triggered' : 'clear',
                $activeGrant !== null && $snapshot === null ? 'danger' : 'success',
                $activeGrant !== null && $snapshot === null ? 'Unlock fact exists without a report_snapshot.' : null
            ),
            $this->pillField(
                'snapshot_failed',
                in_array($snapshotStatus, ['failed', 'error'], true) || in_array($reportJobStatus, ['failed', 'error'], true) ? 'triggered' : 'clear',
                in_array($snapshotStatus, ['failed', 'error'], true) || in_array($reportJobStatus, ['failed', 'error'], true) ? 'danger' : 'success',
                $this->shortText(($snapshot->last_error ?? null) ?: ($reportJob->last_error ?? null))
            ),
            $this->pillField(
                'pdf_unavailable',
                $resolvedAttemptId !== null && ! $pdfReady ? 'triggered' : 'clear',
                $resolvedAttemptId !== null && ! $pdfReady ? 'warning' : 'success',
                $resolvedAttemptId !== null && ! $pdfReady ? 'Attempt is linked but PDF is not marked ready.' : null
            ),
            $this->pillField(
                'order_exists_but_no_payment_handled',
                ! $paymentHandled ? 'triggered' : 'clear',
                ! $paymentHandled ? 'warning' : 'success',
                ! $paymentHandled ? 'No handled payment event summary is available yet.' : null
            ),
        ];
    }

    /**
     * @return list<array{label:string,url:string,kind:string}>
     */
    private function links(object $order, ?string $attemptId, ?string $shareId, ?object $attempt): array
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $localeSegment = $this->localeSegment((string) ($attempt->locale ?? ''));
        $links = [];

        if ($base !== '') {
            $orderNo = trim((string) ($order->order_no ?? ''));
            if ($orderNo !== '') {
                $links[] = [
                    'label' => 'Order page',
                    'url' => $base.'/'.$localeSegment.'/orders/'.urlencode($orderNo),
                    'kind' => 'frontend',
                ];
            }

            if ($attemptId !== null) {
                $links[] = [
                    'label' => 'Result page',
                    'url' => $base.'/'.$localeSegment.'/result/'.urlencode($attemptId),
                    'kind' => 'frontend',
                ];
                $links[] = [
                    'label' => 'Report page',
                    'url' => $base.'/'.$localeSegment.'/attempts/'.urlencode($attemptId).'/report',
                    'kind' => 'frontend',
                ];
            }

            if ($shareId !== null && $shareId !== '') {
                $links[] = [
                    'label' => 'Share page',
                    'url' => $base.'/'.$localeSegment.'/share/'.urlencode($shareId),
                    'kind' => 'frontend',
                ];
            }
        }

        if ($attemptId !== null) {
            $links[] = [
                'label' => 'Attempt Explorer',
                'url' => '/ops/attempts/'.urlencode($attemptId),
                'kind' => 'ops',
            ];
        }

        $links[] = [
            'label' => 'Order Lookup',
            'url' => '/ops/order-lookup',
            'kind' => 'ops',
        ];

        return $links;
    }

    private function resolvedAttemptId(object $order): ?string
    {
        foreach ([
            $order->target_attempt_id ?? null,
            $order->latest_snapshot_attempt_id ?? null,
            $order->latest_benefit_attempt_id ?? null,
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isPdfReady(object $order): bool
    {
        $snapshotStatus = strtolower(trim((string) ($order->latest_snapshot_status ?? '')));

        return $this->resolvedAttemptId($order) !== null
            && ($this->isPaidLike((string) ($order->status ?? '')) || $this->isPaidLike((string) ($order->latest_payment_status ?? '')))
            && in_array($snapshotStatus, ['ready', 'full', 'completed'], true);
    }

    private function hasActiveBenefitGrant(object $order): bool
    {
        return $this->truthy($order->has_active_benefit_grant ?? null)
            || strtolower(trim((string) ($order->latest_benefit_status ?? ''))) === 'active';
    }

    private function statusState(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match (true) {
            $status === '' => 'gray',
            in_array($status, ['paid', 'fulfilled', 'active', 'ready', 'full', 'completed', 'processed', 'handled', 'succeeded'], true) => 'success',
            in_array($status, ['pending', 'queued', 'running', 'processing'], true) => 'warning',
            in_array($status, ['failed', 'error', 'revoked', 'expired', 'invalid', 'refunded'], true) => 'danger',
            default => 'gray',
        };
    }

    private function isPaidLike(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['paid', 'fulfilled', 'complete', 'completed', 'succeeded'], true);
    }

    private function isRefundedLike(?string $status): bool
    {
        return str_contains(strtolower(trim((string) $status)), 'refund');
    }

    private function contactEmailHash(string $candidate): ?string
    {
        $email = mb_strtolower(trim($candidate), 'UTF-8');
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return hash('sha256', $email);
    }

    private function localeSegment(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatMoney(object $order): string
    {
        $amountCents = (int) ($order->amount_cents ?? 0);
        $currency = strtoupper(trim((string) ($order->currency ?? 'USD')));

        return number_format($amountCents / 100, 2, '.', '').' '.$currency;
    }

    private function utmSummary(mixed $value): string
    {
        if (! is_array($value)) {
            return '-';
        }

        $parts = [];
        foreach (['source', 'medium', 'campaign'] as $key) {
            $segment = trim((string) ($value[$key] ?? ''));
            if ($segment !== '') {
                $parts[] = $key.'='.$segment;
            }
        }

        return $parts === [] ? '-' : implode(' | ', $parts);
    }

    private function shortText(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '-';
        }

        return mb_strlen($text, 'UTF-8') > 140
            ? mb_substr($text, 0, 137, 'UTF-8').'...'
            : $text;
    }

    /**
     * @return array{label:string,value:string,hint:?string,kind:string,state:?string}
     */
    private function field(string $label, mixed $value, ?string $hint = null): array
    {
        return [
            'label' => $label,
            'value' => $this->stringOrDash($value),
            'hint' => $hint,
            'kind' => 'text',
            'state' => null,
        ];
    }

    /**
     * @return array{label:string,value:string,hint:?string,kind:string,state:?string}
     */
    private function pillField(string $label, string $value, string $state, ?string $hint = null): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'hint' => $hint,
            'kind' => 'pill',
            'state' => $state,
        ];
    }

    private function stringOrDash(mixed $value): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : '-';
    }
}
