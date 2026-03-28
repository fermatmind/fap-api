<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\Support;

use App\Filament\Ops\Resources\BenefitGrantResource;
use App\Filament\Ops\Resources\PaymentAttemptResource;
use App\Filament\Ops\Resources\PaymentEventResource;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\OrderManager;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OrderLinkageSupport
{
    private const EXCEPTION_LABELS = [
        'pending_unpaid' => 'pending_unpaid',
        'provider_created_not_paid' => 'provider_created_not_paid',
        'callback_missing' => 'callback_missing',
        'paid_no_grant' => 'paid_no_grant',
        'grant_without_paid' => 'grant_without_paid',
        'webhook_error' => 'webhook_error',
        'compensation_touched' => 'compensation_touched',
        'refund_revoked' => 'refund_revoked',
        'late_callback_corrected' => 'late_callback_corrected',
    ];

    private const PRIMARY_EXCEPTION_ORDER = [
        'webhook_error',
        'callback_missing',
        'late_callback_corrected',
        'paid_no_grant',
        'grant_without_paid',
        'refund_revoked',
        'provider_created_not_paid',
        'pending_unpaid',
        'compensation_touched',
    ];

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

        if (SchemaBaseline::hasTable('payment_attempts')) {
            $query
                ->selectSub($this->paymentAttemptCountField(), 'payment_attempts_count')
                ->selectSub($this->paymentAttemptField('id'), 'latest_payment_attempt_id')
                ->selectSub($this->paymentAttemptField('state'), 'latest_payment_attempt_state')
                ->selectSub($this->paymentAttemptField('provider'), 'latest_payment_attempt_provider')
                ->selectSub($this->paymentAttemptField('provider_trade_no'), 'latest_payment_attempt_provider_trade_no')
                ->selectSub($this->paymentAttemptField('external_trade_no'), 'latest_payment_attempt_external_trade_no')
                ->selectSub($this->paymentAttemptField('callback_received_at'), 'latest_payment_attempt_callback_received_at')
                ->selectSub($this->paymentAttemptField('verified_at'), 'latest_payment_attempt_verified_at')
                ->selectSub($this->paymentAttemptField('finalized_at'), 'latest_payment_attempt_finalized_at')
                ->selectSub($this->paymentAttemptField('last_error_code'), 'latest_payment_attempt_last_error_code')
                ->selectSub($this->paymentAttemptField('last_error_message'), 'latest_payment_attempt_last_error_message');
        } else {
            $query->selectRaw('0 as payment_attempts_count');
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

        if (SchemaBaseline::hasTable('unified_access_projections')) {
            $query
                ->selectSub($this->accessProjectionField('access_state'), 'latest_access_state')
                ->selectSub($this->accessProjectionField('report_state'), 'latest_access_report_state')
                ->selectSub($this->accessProjectionField('pdf_state'), 'latest_access_pdf_state')
                ->selectSub($this->accessProjectionField('reason_code'), 'latest_access_reason_code')
                ->selectSub($this->accessProjectionField('projection_version'), 'latest_access_projection_version')
                ->selectSub($this->accessProjectionField('refreshed_at'), 'latest_access_refreshed_at');
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
        if (! SchemaBaseline::hasColumn('orders', 'payment_state')) {
            return [];
        }

        return collect([
            Order::PAYMENT_STATE_CREATED,
            Order::PAYMENT_STATE_PENDING,
            Order::PAYMENT_STATE_PAID,
            Order::PAYMENT_STATE_FAILED,
            Order::PAYMENT_STATE_CANCELED,
            Order::PAYMENT_STATE_EXPIRED,
            Order::PAYMENT_STATE_REFUNDED,
        ])
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function distinctWebhookStatusOptions(): array
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
     * @return array<string, string>
     */
    public function exceptionOptions(): array
    {
        return self::EXCEPTION_LABELS;
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyExceptionFilter(Builder $query, ?string $value): void
    {
        $exception = strtolower(trim((string) $value));
        if ($exception === '' || ! array_key_exists($exception, self::EXCEPTION_LABELS)) {
            return;
        }

        match ($exception) {
            'pending_unpaid' => $query
                ->whereIn('orders.payment_state', [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING])
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereNotExists($this->paymentAttemptExistsQuery())
                        ->orWhereExists($this->paymentAttemptStateExistsQuery([PaymentAttempt::STATE_INITIATED]));
                }),
            'provider_created_not_paid' => $query
                ->whereIn('orders.payment_state', [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING])
                ->whereExists($this->paymentAttemptStateExistsQuery([
                    PaymentAttempt::STATE_PROVIDER_CREATED,
                    PaymentAttempt::STATE_CLIENT_PRESENTED,
                ])),
            'callback_missing' => $query
                ->whereIn('orders.payment_state', [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING])
                ->whereExists($this->paymentAttemptStateExistsQuery([
                    PaymentAttempt::STATE_CALLBACK_RECEIVED,
                    PaymentAttempt::STATE_VERIFIED,
                ])),
            'paid_no_grant' => $query
                ->where('orders.payment_state', Order::PAYMENT_STATE_PAID)
                ->where('orders.grant_state', '!=', Order::GRANT_STATE_GRANTED)
                ->whereNotExists($this->activeBenefitExistsQuery())
                ->where($this->notRefundedLikeOrderClause()),
            'grant_without_paid' => $query
                ->whereExists($this->activeBenefitExistsQuery())
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereNotIn('orders.payment_state', [Order::PAYMENT_STATE_PAID, Order::PAYMENT_STATE_REFUNDED])
                        ->orWhereNull('orders.payment_state');
                }),
            'webhook_error' => $query->whereExists($this->webhookErrorExistsQuery()),
            'compensation_touched' => $query->whereNotNull('orders.last_reconciled_at'),
            'refund_revoked' => $query
                ->where('orders.payment_state', Order::PAYMENT_STATE_REFUNDED)
                ->whereExists($this->revokedBenefitExistsQuery()),
            'late_callback_corrected' => $query
                ->where('orders.payment_state', Order::PAYMENT_STATE_PAID)
                ->whereNotNull('orders.last_reconciled_at')
                ->whereNotNull('orders.paid_at')
                ->whereColumn('orders.paid_at', '>', 'orders.last_reconciled_at'),
            default => null,
        };
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
        $paymentState = strtolower(trim((string) $value));
        if ($paymentState === '' || ! SchemaBaseline::hasColumn('orders', 'payment_state')) {
            return;
        }

        $query->where('orders.payment_state', $paymentState);
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function applyWebhookStatusFilter(Builder $query, ?string $value): void
    {
        $webhookStatus = trim((string) $value);
        if ($webhookStatus === '' || ! SchemaBaseline::hasTable('payment_events')) {
            return;
        }

        $query->whereExists(function (QueryBuilder $paymentQuery) use ($webhookStatus): void {
            $paymentQuery
                ->selectRaw('1')
                ->from('payment_events')
                ->whereColumn('payment_events.order_no', 'orders.order_no')
                ->where('payment_events.status', $webhookStatus);
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
                ->whereExists($this->activeBenefitExistsQuery())
                ->whereExists($this->snapshotReadyExistsQuery());

            return;
        }

        $query->where(function (Builder $builder): void {
            $builder
                ->where($this->notPaidLikeOrderClause())
                ->orWhereNotExists($this->activeBenefitExistsQuery())
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
                ->whereExists($this->activeBenefitExistsQuery())
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
                        ->orWhereNotExists($this->activeBenefitExistsQuery())
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
        $status = $this->resolvedPaymentStateValue($order);
        if ($status === '') {
            return ['label' => 'missing', 'state' => 'gray'];
        }

        return ['label' => $status, 'state' => $this->statusState($status)];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function webhookStatus(object $order): array
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
    public function primaryException(object $order): array
    {
        foreach (self::PRIMARY_EXCEPTION_ORDER as $key) {
            if ($this->matchesException($key, $order)) {
                return ['label' => $key, 'state' => $this->exceptionState($key)];
            }
        }

        return ['label' => 'clear', 'state' => 'success'];
    }

    public function exceptionCount(object $order): int
    {
        $count = 0;

        foreach (array_keys(self::EXCEPTION_LABELS) as $key) {
            if ($this->matchesException($key, $order)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{label:string,state:string}
     */
    public function compensationStatus(object $order): array
    {
        if (! filled($order->last_reconciled_at ?? null)) {
            return ['label' => 'untouched', 'state' => 'gray'];
        }

        if ($this->matchesException('late_callback_corrected', $order)) {
            return ['label' => 'corrected_after_compensation', 'state' => 'success'];
        }

        $paymentState = $this->resolvedPaymentStateValue($order);

        return match ($paymentState) {
            Order::PAYMENT_STATE_PAID => ['label' => 'paid_after_compensation', 'state' => 'success'],
            Order::PAYMENT_STATE_FAILED,
            Order::PAYMENT_STATE_CANCELED,
            Order::PAYMENT_STATE_EXPIRED => ['label' => 'closed_after_compensation', 'state' => 'warning'],
            default => ['label' => 'touched', 'state' => 'warning'],
        };
    }

    /**
     * @return array{label:string,state:string}
     */
    public function unlockStatus(object $order): array
    {
        $paymentState = $this->resolvedPaymentStateValue($order);

        if ($this->hasActiveBenefitGrant($order)) {
            return ['label' => 'unlocked', 'state' => 'success'];
        }

        if ($paymentState === Order::PAYMENT_STATE_REFUNDED) {
            return ['label' => 'refunded', 'state' => 'danger'];
        }

        if ($paymentState === Order::PAYMENT_STATE_PAID) {
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
     *     payment_attempts: list<array<string, mixed>>,
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
     *     access_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     compensation_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     exception_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     links: list<array{label:string,url:string,kind:string}>
     * }
     */
    public function buildDetail(Order $order): array
    {
        $orderRow = DB::table('orders')->where('id', (string) $order->getKey())->first() ?? $order;
        $resolvedAttemptId = $this->resolvedAttemptId($order);
        $orderNo = (string) ($orderRow->order_no ?? '');
        $paymentEvents = $this->paymentEvents((string) ($orderRow->order_no ?? ''));
        $paymentAttempts = $this->paymentAttempts($orderNo);
        $latestPaymentAttempt = $paymentAttempts[0] ?? null;
        $grants = $this->benefitGrants((string) ($orderRow->order_no ?? ''), $resolvedAttemptId);
        $activeGrant = $this->activeGrant($grants);
        $snapshot = $this->reportSnapshot((string) ($orderRow->order_no ?? ''), $resolvedAttemptId);
        $reportPayload = $this->decodeJson($snapshot->report_json ?? null);
        $reportJob = $this->reportJob($resolvedAttemptId);
        $attempt = $this->attempt($resolvedAttemptId);
        $accessProjection = $this->accessProjection($resolvedAttemptId);
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
                $this->field('payment_attempts', $this->stringOrDash($order->payment_attempts_count ?? count($paymentAttempts))),
                $this->field('last_reconciled_at', $this->formatTimestamp($orderRow->last_reconciled_at ?? null)),
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
                'id' => $this->stringOrDash($event->id ?? null),
                'provider_event_id' => $this->stringOrDash($event->provider_event_id ?? null),
                'payment_attempt_id' => $this->stringOrDash($event->payment_attempt_id ?? null),
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

        $paymentAttemptSummary = array_map(function (object $paymentAttempt): array {
            return [
                'id' => $this->stringOrDash($paymentAttempt->id ?? null),
                'attempt_no' => $this->stringOrDash($paymentAttempt->attempt_no ?? null),
                'provider' => $this->stringOrDash($paymentAttempt->provider ?? null),
                'state' => [
                    'label' => $this->stringOrDash($paymentAttempt->state ?? null),
                    'state' => $this->statusState((string) ($paymentAttempt->state ?? '')),
                ],
                'provider_trade_no' => $this->stringOrDash($paymentAttempt->provider_trade_no ?? null),
                'external_trade_no' => $this->stringOrDash($paymentAttempt->external_trade_no ?? null),
                'provider_session_ref' => $this->stringOrDash($paymentAttempt->provider_session_ref ?? null),
                'callback_received_at' => $this->formatTimestamp($paymentAttempt->callback_received_at ?? null),
                'verified_at' => $this->formatTimestamp($paymentAttempt->verified_at ?? null),
                'finalized_at' => $this->formatTimestamp($paymentAttempt->finalized_at ?? null),
                'last_error_code' => $this->stringOrDash($paymentAttempt->last_error_code ?? null),
                'last_error_message' => $this->shortText($paymentAttempt->last_error_message ?? null),
            ];
        }, $paymentAttempts);

        $accessSummary = [
            'fields' => [
                $this->pillField('access_state', $this->stringOrDash($accessProjection->access_state ?? ($order->latest_access_state ?? null)), $this->statusState((string) ($accessProjection->access_state ?? ($order->latest_access_state ?? '')))),
                $this->pillField('report_state', $this->stringOrDash($accessProjection->report_state ?? null), $this->statusState((string) ($accessProjection->report_state ?? ''))),
                $this->pillField('pdf_state', $this->stringOrDash($accessProjection->pdf_state ?? null), $this->statusState((string) ($accessProjection->pdf_state ?? ''))),
                $this->field('reason_code', $this->stringOrDash($accessProjection->reason_code ?? ($order->latest_access_reason_code ?? null))),
                $this->field('projection_version', $this->stringOrDash($accessProjection->projection_version ?? ($order->latest_access_projection_version ?? null))),
                $this->field('produced_at', $this->formatTimestamp($accessProjection->produced_at ?? null)),
                $this->field('refreshed_at', $this->formatTimestamp($accessProjection->refreshed_at ?? ($order->latest_access_refreshed_at ?? null))),
            ],
            'notes' => [
                'Unified access projection stays read-only here. This page only surfaces the latest access truth alongside the order.',
            ],
        ];

        $compensationState = $this->compensationStatus($order);
        $compensationSummary = [
            'fields' => [
                $this->pillField('compensation_status', $compensationState['label'], $compensationState['state']),
                $this->field('last_reconciled_at', $this->formatTimestamp($orderRow->last_reconciled_at ?? null)),
                $this->field('latest_attempt_state', $this->stringOrDash($latestPaymentAttempt->state ?? ($order->latest_payment_attempt_state ?? null))),
                $this->field('latest_attempt_provider', $this->stringOrDash($latestPaymentAttempt->provider ?? ($order->latest_payment_attempt_provider ?? null))),
                $this->field('latest_attempt_ref', $this->stringOrDash($latestPaymentAttempt->provider_trade_no ?? ($order->latest_payment_attempt_provider_trade_no ?? null))),
                $this->field(
                    'suggested_command',
                    $orderNo !== '' ? 'php artisan commerce:compensate-pending-orders --order='.$orderNo.' --dry-run' : '-',
                    'Command hint only. OP-4 keeps compensation execution in CLI.'
                ),
            ],
            'notes' => [
                'Compensation remains a command-driven workflow. This page only tells ops whether compensation has already touched the order.',
            ],
        ];

        $exceptionSummary = [
            'fields' => $this->exceptionFields($order, $paymentEvents, $activeGrant, $snapshot, $reportJob, $deliveryState, $resolvedAttemptId),
            'notes' => ['These rules are explicit v1 diagnostics, not a full rule engine or BI funnel.'],
        ];

        return [
            'headline' => $headline,
            'order_summary' => $orderSummary,
            'payment_attempts' => $paymentAttemptSummary,
            'payment_events' => $paymentSummary,
            'benefit_summary' => $benefitSummary,
            'report_summary' => $reportSummary,
            'attempt_summary' => $attemptSummary,
            'access_summary' => $accessSummary,
            'compensation_summary' => $compensationSummary,
            'exception_summary' => $exceptionSummary,
            'links' => $this->links($order, $orderRow, $resolvedAttemptId, $shareId !== '' ? $shareId : null, $attempt, $latestPaymentAttempt, $paymentEvents[0] ?? null, $activeGrant),
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

    private function paymentAttemptField(string $column): QueryBuilder
    {
        return DB::table('payment_attempts')
            ->select($column)
            ->whereColumn('payment_attempts.order_no', 'orders.order_no')
            ->orderByDesc('attempt_no')
            ->limit(1);
    }

    private function paymentAttemptCountField(): QueryBuilder
    {
        return DB::table('payment_attempts')
            ->selectRaw('count(*)')
            ->whereColumn('payment_attempts.order_no', 'orders.order_no');
    }

    private function accessProjectionField(string $column): QueryBuilder
    {
        return DB::table('unified_access_projections')
            ->select($column)
            ->whereColumn('unified_access_projections.attempt_id', 'orders.target_attempt_id')
            ->orderByRaw('coalesce(refreshed_at, produced_at) desc')
            ->limit(1);
    }

    private function benefitField(string $column): QueryBuilder
    {
        $query = DB::table('benefit_grants')
            ->select($column)
            ->where(function (QueryBuilder $builder): void {
                $builder->whereColumn('benefit_grants.order_no', 'orders.order_no')
                    ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
            })
            ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);

        $this->applyExpectedBenefitFilter($query);

        return $query;
    }

    private function activeBenefitExistsField(): QueryBuilder
    {
        $query = DB::table('benefit_grants')
            ->selectRaw('1')
            ->where('benefit_grants.status', 'active')
            ->where(function (QueryBuilder $builder): void {
                $builder->whereColumn('benefit_grants.order_no', 'orders.order_no')
                    ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
            })
            ->limit(1);

        $this->applyExpectedBenefitFilter($query);

        return $query;
    }

    private function expectedBenefitCodeField(): ?QueryBuilder
    {
        if (! SchemaBaseline::hasTable('skus')) {
            return null;
        }

        return DB::table('skus')
            ->selectRaw('upper(coalesce(benefit_code, \'\'))')
            ->where('is_active', true)
            ->whereRaw("upper(coalesce(skus.sku, '')) = upper(coalesce(nullif(orders.item_sku, ''), orders.sku, ''))")
            ->limit(1);
    }

    private function applyExpectedBenefitFilter(QueryBuilder $query): void
    {
        $expectedA = $this->expectedBenefitCodeField();
        $expectedB = $this->expectedBenefitCodeField();

        if ($expectedA === null || $expectedB === null) {
            return;
        }

        $query->where(function (QueryBuilder $builder) use ($expectedA, $expectedB): void {
            $builder->whereRaw(
                'coalesce(('.$expectedA->toSql()."), '') = ''",
                $expectedA->getBindings()
            )->orWhereRaw(
                "upper(coalesce(benefit_grants.benefit_code, '')) = (".$expectedB->toSql().')',
                $expectedB->getBindings()
            );
        });
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

    private function revokedBenefitExistsQuery(): \Closure
    {
        return function (QueryBuilder $benefitQuery): void {
            $benefitQuery
                ->selectRaw('1')
                ->from('benefit_grants')
                ->whereRaw("lower(coalesce(benefit_grants.status, '')) in (?, ?)", ['revoked', 'expired'])
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereColumn('benefit_grants.order_no', 'orders.order_no')
                        ->orWhereColumn('benefit_grants.attempt_id', 'orders.target_attempt_id');
                });
        };
    }

    private function paymentAttemptExistsQuery(): \Closure
    {
        return function (QueryBuilder $attemptQuery): void {
            $attemptQuery
                ->selectRaw('1')
                ->from('payment_attempts')
                ->whereColumn('payment_attempts.order_no', 'orders.order_no');
        };
    }

    /**
     * @param  list<string>  $states
     */
    private function paymentAttemptStateExistsQuery(array $states): \Closure
    {
        return function (QueryBuilder $attemptQuery) use ($states): void {
            $attemptQuery
                ->selectRaw('1')
                ->from('payment_attempts')
                ->whereColumn('payment_attempts.order_no', 'orders.order_no')
                ->whereIn('payment_attempts.state', $states);
        };
    }

    private function webhookErrorExistsQuery(): \Closure
    {
        return function (QueryBuilder $eventQuery): void {
            $eventQuery
                ->selectRaw('1')
                ->from('payment_events')
                ->whereColumn('payment_events.order_no', 'orders.order_no')
                ->where(function (QueryBuilder $builder): void {
                    $builder->where('signature_ok', 0)
                        ->orWhereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                        ->orWhereIn('handle_status', ['failed', 'reprocess_failed'])
                        ->orWhereNotNull('last_error_message');
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
            $builder->where('orders.payment_state', Order::PAYMENT_STATE_PAID);
        };
    }

    private function refundedLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder->where('orders.payment_state', Order::PAYMENT_STATE_REFUNDED);
        };
    }

    private function notPaidLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder->where(function (Builder|QueryBuilder $nested): void {
                $nested
                    ->whereNull('orders.payment_state')
                    ->orWhere('orders.payment_state', '!=', Order::PAYMENT_STATE_PAID);
            });
        };
    }

    private function notRefundedLikeOrderClause(): \Closure
    {
        return function (Builder|QueryBuilder $builder): void {
            $builder->where(function (Builder|QueryBuilder $nested): void {
                $nested
                    ->whereNull('orders.payment_state')
                    ->orWhere('orders.payment_state', '!=', Order::PAYMENT_STATE_REFUNDED);
            });
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
                'id',
                'provider_event_id',
                'payment_attempt_id',
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
    private function paymentAttempts(string $orderNo): array
    {
        if ($orderNo === '' || ! SchemaBaseline::hasTable('payment_attempts')) {
            return [];
        }

        return DB::table('payment_attempts')
            ->select([
                'id',
                'attempt_no',
                'provider',
                'state',
                'provider_trade_no',
                'external_trade_no',
                'provider_session_ref',
                'callback_received_at',
                'verified_at',
                'finalized_at',
                'last_error_code',
                'last_error_message',
            ])
            ->where('order_no', $orderNo)
            ->orderByDesc('attempt_no')
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

    private function accessProjection(?string $attemptId): ?object
    {
        if ($attemptId === null || ! SchemaBaseline::hasTable('unified_access_projections')) {
            return null;
        }

        return DB::table('unified_access_projections')
            ->where('attempt_id', $attemptId)
            ->orderByRaw('coalesce(refreshed_at, produced_at) desc')
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
        $snapshotStatus = strtolower(trim((string) ($snapshot->status ?? '')));
        $reportJobStatus = strtolower(trim((string) ($reportJob->status ?? '')));
        $pdfReady = (bool) ($deliveryState['can_download_pdf'] ?? false);
        $fields = [];

        foreach (array_keys(self::EXCEPTION_LABELS) as $key) {
            $triggered = $this->matchesException($key, $order);
            $fields[] = $this->pillField(
                $key,
                $triggered ? 'triggered' : 'clear',
                $triggered ? $this->exceptionState($key) : 'success',
                $triggered ? $this->exceptionHint($key, $order) : null
            );
        }

        $fields[] = $this->pillField(
            'grant_exists_but_snapshot_missing',
            $activeGrant !== null && $snapshot === null ? 'triggered' : 'clear',
            $activeGrant !== null && $snapshot === null ? 'danger' : 'success',
            $activeGrant !== null && $snapshot === null ? 'Unlock fact exists without a report_snapshot.' : null
        );
        $fields[] = $this->pillField(
            'snapshot_failed',
            in_array($snapshotStatus, ['failed', 'error'], true) || in_array($reportJobStatus, ['failed', 'error'], true) ? 'triggered' : 'clear',
            in_array($snapshotStatus, ['failed', 'error'], true) || in_array($reportJobStatus, ['failed', 'error'], true) ? 'danger' : 'success',
            $this->shortText(($snapshot->last_error ?? null) ?: ($reportJob->last_error ?? null))
        );
        $fields[] = $this->pillField(
            'pdf_unavailable',
            $resolvedAttemptId !== null && ! $pdfReady ? 'triggered' : 'clear',
            $resolvedAttemptId !== null && ! $pdfReady ? 'warning' : 'success',
            $resolvedAttemptId !== null && ! $pdfReady ? 'Attempt is linked but PDF is not marked ready.' : null
        );

        return $fields;
    }

    /**
     * @return list<array{label:string,url:string,kind:string}>
     */
    private function links(
        object $orderRecord,
        object $order,
        ?string $attemptId,
        ?string $shareId,
        ?object $attempt,
        ?object $latestPaymentAttempt,
        ?object $latestPaymentEvent,
        ?object $activeGrant,
    ): array {
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

        if (($latestPaymentAttempt->id ?? null) !== null) {
            $links[] = [
                'label' => 'Latest payment attempt',
                'url' => PaymentAttemptResource::getUrl('view', ['record' => (string) $latestPaymentAttempt->id]),
                'kind' => 'ops',
            ];
        }

        if (($latestPaymentEvent->id ?? null) !== null) {
            $links[] = [
                'label' => 'Latest payment event',
                'url' => PaymentEventResource::getUrl('view', ['record' => (string) $latestPaymentEvent->id]),
                'kind' => 'ops',
            ];
        }

        if (($activeGrant->id ?? null) !== null) {
            $links[] = [
                'label' => 'Latest benefit grant',
                'url' => BenefitGrantResource::getUrl('view', ['record' => (string) $activeGrant->id]),
                'kind' => 'ops',
            ];
        }

        $links[] = [
            'label' => 'Order Lookup',
            'url' => '/ops/order-lookup',
            'kind' => 'ops',
        ];

        if (filled($orderRecord->last_reconciled_at ?? null) && trim((string) ($order->order_no ?? '')) !== '') {
            $links[] = [
                'label' => 'Compensation hint',
                'url' => '#compensation-summary',
                'kind' => 'ops',
            ];
        }

        return $links;
    }

    private function resolvedPaymentStateValue(object $order): string
    {
        $state = strtolower(trim((string) ($order->payment_state ?? '')));

        return in_array($state, [
            Order::PAYMENT_STATE_CREATED,
            Order::PAYMENT_STATE_PENDING,
            Order::PAYMENT_STATE_PAID,
            Order::PAYMENT_STATE_FAILED,
            Order::PAYMENT_STATE_CANCELED,
            Order::PAYMENT_STATE_EXPIRED,
            Order::PAYMENT_STATE_REFUNDED,
        ], true) ? $state : '';
    }

    private function latestPaymentAttemptState(object $order): string
    {
        return PaymentAttempt::normalizedState((string) ($order->latest_payment_attempt_state ?? ''));
    }

    private function matchesException(string $key, object $order): bool
    {
        $paymentState = $this->resolvedPaymentStateValue($order);
        $attemptState = $this->latestPaymentAttemptState($order);
        $hasAttempt = (int) ($order->payment_attempts_count ?? 0) > 0;
        $hasActiveGrant = $this->hasActiveBenefitGrant($order);
        $latestHandleStatus = strtolower(trim((string) ($order->latest_handle_status ?? '')));
        $latestPaymentStatus = strtolower(trim((string) ($order->latest_payment_status ?? '')));
        $latestPaymentError = trim((string) ($order->latest_payment_error ?? ''));
        $latestSignatureOk = $order->latest_signature_ok ?? null;

        return match ($key) {
            'pending_unpaid' => in_array($paymentState, [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING], true)
                && (! $hasAttempt || $attemptState === PaymentAttempt::STATE_INITIATED),
            'provider_created_not_paid' => in_array($paymentState, [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING], true)
                && in_array($attemptState, [PaymentAttempt::STATE_PROVIDER_CREATED, PaymentAttempt::STATE_CLIENT_PRESENTED], true),
            'callback_missing' => in_array($paymentState, [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING], true)
                && in_array($attemptState, [PaymentAttempt::STATE_CALLBACK_RECEIVED, PaymentAttempt::STATE_VERIFIED], true),
            'paid_no_grant' => $paymentState === Order::PAYMENT_STATE_PAID
                && ! $hasActiveGrant,
            'grant_without_paid' => $hasActiveGrant
                && ! in_array($paymentState, [Order::PAYMENT_STATE_PAID, Order::PAYMENT_STATE_REFUNDED], true),
            'webhook_error' => $latestSignatureOk !== null && ! $this->truthy($latestSignatureOk)
                || in_array($latestPaymentStatus, ['failed', 'rejected', 'post_commit_failed'], true)
                || in_array($latestHandleStatus, ['failed', 'reprocess_failed'], true)
                || $latestPaymentError !== '',
            'compensation_touched' => filled($order->last_reconciled_at ?? null),
            'refund_revoked' => $paymentState === Order::PAYMENT_STATE_REFUNDED
                && in_array(strtolower(trim((string) ($order->latest_benefit_status ?? ''))), ['revoked', 'expired'], true),
            'late_callback_corrected' => $paymentState === Order::PAYMENT_STATE_PAID
                && filled($order->last_reconciled_at ?? null)
                && filled($order->paid_at ?? null)
                && Carbon::parse((string) $order->paid_at)->gt(Carbon::parse((string) $order->last_reconciled_at)),
            default => false,
        };
    }

    private function exceptionState(string $key): string
    {
        return match ($key) {
            'webhook_error', 'paid_no_grant', 'grant_without_paid' => 'danger',
            'late_callback_corrected' => 'success',
            default => 'warning',
        };
    }

    private function exceptionHint(string $key, object $order): string
    {
        return match ($key) {
            'pending_unpaid' => 'No provider-created attempt is visible yet. This still looks like a true unpaid order.',
            'provider_created_not_paid' => 'A provider session exists, but the order has not reached a paid terminal state.',
            'callback_missing' => 'Attempt reached callback_received/verified while the order still reads pending. Check webhook handling.',
            'paid_no_grant' => 'Payment is confirmed, but grant/access is not unlocked yet.',
            'grant_without_paid' => 'A grant exists without a paid order state. Audit entitlement issuance.',
            'webhook_error' => 'Latest webhook signature or handling status indicates an error.',
            'compensation_touched' => 'Compensation touched this order at '.$this->formatTimestamp($order->last_reconciled_at ?? null).'.',
            'refund_revoked' => 'Refunded order has a revoked/expired grant trail.',
            'late_callback_corrected' => 'Late callback corrected an order after compensation already touched it.',
            default => '-',
        };
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
            && $this->resolvedPaymentStateValue($order) === Order::PAYMENT_STATE_PAID
            && $this->hasActiveBenefitGrant($order)
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
            in_array($status, ['pending', 'queued', 'running', 'processing', 'initiated', 'provider_created', 'client_presented', 'callback_received', 'verified'], true) => 'warning',
            in_array($status, ['failed', 'error', 'revoked', 'expired', 'invalid', 'refunded', 'canceled', 'cancelled'], true) => 'danger',
            default => 'gray',
        };
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
