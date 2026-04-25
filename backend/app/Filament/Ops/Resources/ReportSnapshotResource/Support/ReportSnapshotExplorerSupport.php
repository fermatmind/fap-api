<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ReportSnapshotResource\Support;

use App\Filament\Ops\Resources\AttemptResource;
use App\Filament\Ops\Resources\OrderResource;
use App\Filament\Ops\Resources\ResultResource;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\ReportSnapshot;
use App\Services\Commerce\OrderManager;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

final class ReportSnapshotExplorerSupport
{
    /**
     * @return Builder<ReportSnapshot>
     */
    public function query(): Builder
    {
        $query = ReportSnapshot::query()
            ->withoutGlobalScopes()
            ->select('report_snapshots.*');

        if (SchemaBaseline::hasTable('attempts')) {
            foreach ([
                'locale' => 'locale',
                'region' => 'region',
                'channel' => 'attempt_channel',
                'ticket_code' => 'attempt_ticket_code',
                'user_id' => 'attempt_user_id',
                'anon_id' => 'attempt_anon_id',
                'submitted_at' => 'attempt_submitted_at',
            ] as $column => $alias) {
                if (SchemaBaseline::hasColumn('attempts', $column)) {
                    $query->selectSub($this->attemptField($column), $alias);
                }
            }
        }

        if (SchemaBaseline::hasTable('results')) {
            foreach ([
                'id' => 'result_id',
                'type_code' => 'result_type_code',
                'computed_at' => 'result_computed_at',
            ] as $column => $alias) {
                if (SchemaBaseline::hasColumn('results', $column)) {
                    $query->selectSub($this->resultField($column), $alias);
                }
            }
        }

        if (SchemaBaseline::hasTable('orders')) {
            foreach ([
                'id' => 'order_id',
                'status' => 'order_status',
                'paid_at' => 'order_paid_at',
                'updated_at' => 'order_updated_at',
            ] as $column => $alias) {
                if (SchemaBaseline::hasColumn('orders', $column)) {
                    $query->selectSub($this->orderField($column), $alias);
                }
            }

            $query->selectSub($this->orderExistsField(), 'has_order');

            if (SchemaBaseline::hasColumn('orders', 'contact_email_hash')) {
                $query->selectSub($this->contactEmailPresentField(), 'contact_email_present');
            } else {
                $query->selectRaw('0 as contact_email_present');
            }
        } else {
            $query
                ->selectRaw('0 as has_order')
                ->selectRaw('0 as contact_email_present');
        }

        if (SchemaBaseline::hasTable('payment_events')) {
            foreach ([
                'status' => 'payment_status',
                'processed_at' => 'payment_processed_at',
                'provider_event_id' => 'payment_provider_event_id',
            ] as $column => $alias) {
                if (SchemaBaseline::hasColumn('payment_events', $column)) {
                    $query->selectSub($this->paymentField($column), $alias);
                }
            }
        }

        if (SchemaBaseline::hasTable('benefit_grants')) {
            foreach ([
                'status' => 'benefit_status',
                'benefit_code' => 'benefit_code',
                'scope' => 'benefit_scope',
                'expires_at' => 'benefit_expires_at',
            ] as $column => $alias) {
                if (SchemaBaseline::hasColumn('benefit_grants', $column)) {
                    $query->selectSub($this->benefitField($column), $alias);
                }
            }

            $query->selectSub($this->activeBenefitExistsField(), 'has_active_benefit_grant');
        } else {
            $query->selectRaw('0 as has_active_benefit_grant');
        }

        if (SchemaBaseline::hasTable('shares')) {
            $query->selectSub($this->shareField('id'), 'share_id');
        }

        if (SchemaBaseline::hasTable('report_jobs')) {
            foreach ([
                'id' => 'report_job_id',
                'status' => 'report_job_status',
                'last_error' => 'report_job_last_error',
                'updated_at' => 'report_job_updated_at',
            ] as $column => $alias) {
                if (SchemaBaseline::hasColumn('report_jobs', $column)) {
                    $query->selectSub($this->reportJobField($column), $alias);
                }
            }
        }

        if (
            SchemaBaseline::hasTable('email_outbox')
            && SchemaBaseline::hasColumn('email_outbox', 'attempt_id')
            && SchemaBaseline::hasColumn('email_outbox', 'sent_at')
        ) {
            $query->selectSub($this->deliveryEmailSentAtField(), 'last_delivery_email_sent_at');
        }

        return $query;
    }

    /**
     * Production index pages must stay cheap: detail-only linkage data is fetched
     * lazily from buildDetail() after an operator opens a single snapshot.
     *
     * @return Builder<ReportSnapshot>
     */
    public function indexQuery(): Builder
    {
        return ReportSnapshot::query()
            ->withoutGlobalScopes()
            ->select('report_snapshots.*');
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applySearch(Builder $query, string $search): void
    {
        $needle = trim($search);
        if ($needle === '') {
            return;
        }

        $like = '%'.$needle.'%';

        $query->where(function (Builder $builder) use ($like): void {
            $builder
                ->where('report_snapshots.attempt_id', 'like', $like)
                ->orWhere('report_snapshots.order_no', 'like', $like)
                ->orWhere('report_snapshots.scale_code', 'like', $like);

            if (SchemaBaseline::hasTable('shares')) {
                $builder->orWhereExists(function (QueryBuilder $shareQuery) use ($like): void {
                    $shareQuery
                        ->selectRaw('1')
                        ->from('shares')
                        ->whereColumn('shares.attempt_id', 'report_snapshots.attempt_id')
                        ->where('shares.id', 'like', $like);
                });
            }

            if (SchemaBaseline::hasTable('orders') && SchemaBaseline::hasColumn('orders', 'target_attempt_id')) {
                $builder->orWhereExists(function (QueryBuilder $orderQuery) use ($like): void {
                    $orderQuery
                        ->selectRaw('1')
                        ->from('orders')
                        ->where(function (QueryBuilder $nested): void {
                            $this->applyOrderSnapshotCorrelation($nested);
                        })
                        ->where(function (QueryBuilder $nested) use ($like): void {
                            $nested->where('orders.order_no', 'like', $like)
                                ->orWhere('orders.target_attempt_id', 'like', $like);
                        });
                });
            }

            if (SchemaBaseline::hasTable('results')) {
                $builder->orWhereExists(function (QueryBuilder $resultQuery) use ($like): void {
                    $resultQuery
                        ->selectRaw('1')
                        ->from('results')
                        ->whereColumn('results.attempt_id', 'report_snapshots.attempt_id')
                        ->where(function (QueryBuilder $nested) use ($like): void {
                            $nested->where('results.attempt_id', 'like', $like);

                            if (SchemaBaseline::hasColumn('results', 'id')) {
                                $nested->orWhere('results.id', 'like', $like);
                            }

                            if (SchemaBaseline::hasColumn('results', 'type_code')) {
                                $nested->orWhere('results.type_code', 'like', $like);
                            }
                        });
                });
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function distinctSnapshotOptions(string $column): array
    {
        if (! SchemaBaseline::hasColumn('report_snapshots', $column)) {
            return [];
        }

        return DB::table('report_snapshots')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->limit(100)
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
            ->limit(100)
            ->pluck($column, $column)
            ->mapWithKeys(fn ($value): array => [(string) $value => (string) $value])
            ->all();
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applyAttemptFieldFilter(Builder $query, string $column, ?string $value): void
    {
        $needle = trim((string) $value);
        if ($needle === '' || ! SchemaBaseline::hasTable('attempts') || ! SchemaBaseline::hasColumn('attempts', $column)) {
            return;
        }

        $query->whereExists(function (QueryBuilder $attemptQuery) use ($column, $needle): void {
            $attemptQuery
                ->selectRaw('1')
                ->from('attempts')
                ->whereColumn('attempts.id', 'report_snapshots.attempt_id')
                ->where('attempts.'.$column, $needle);
        });
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applySnapshotStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        $query->whereRaw('lower(coalesce(report_snapshots.status, ?)) = ?', ['', $status]);
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applyHasOrderFilter(Builder $query, bool $wanted): void
    {
        if (! SchemaBaseline::hasTable('orders')) {
            if ($wanted) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($wanted) {
            $query->whereExists($this->orderExistsField());

            return;
        }

        $query->whereNotExists($this->orderExistsField());
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applyPaidSuccessFilter(Builder $query, bool $wanted): void
    {
        $hasOrders = SchemaBaseline::hasTable('orders');
        $hasPayments = SchemaBaseline::hasTable('payment_events');

        if (! $hasOrders && ! $hasPayments) {
            if ($wanted) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($wanted) {
            $query->where(function (Builder $builder) use ($hasOrders, $hasPayments): void {
                if ($hasOrders) {
                    $builder->whereExists($this->paidOrderExistsField());
                }

                if ($hasPayments) {
                    $method = $hasOrders ? 'orWhereExists' : 'whereExists';
                    $builder->{$method}($this->paidPaymentExistsField());
                }
            });

            return;
        }

        if ($hasOrders) {
            $query->whereNotExists($this->paidOrderExistsField());
        }

        if ($hasPayments) {
            $query->whereNotExists($this->paidPaymentExistsField());
        }
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applyPdfReadyFilter(Builder $query, bool $wanted): void
    {
        $query->where(function (Builder $builder) use ($wanted): void {
            $readyStatuses = ['ready', 'full', 'completed'];

            if ($wanted) {
                $builder
                    ->whereIn(DB::raw('lower(coalesce(report_snapshots.status, \'\'))'), $readyStatuses)
                    ->where(function (Builder $nested): void {
                        if (SchemaBaseline::hasTable('benefit_grants')) {
                            $nested->whereExists($this->activeBenefitExistsField());
                        }

                        if (SchemaBaseline::hasTable('orders') || SchemaBaseline::hasTable('payment_events')) {
                            $method = SchemaBaseline::hasTable('benefit_grants') ? 'orWhere' : 'where';
                            $nested->{$method}(function (Builder $inner): void {
                                $this->applyPaidSuccessFilter($inner, true);
                            });
                        }
                    });

                return;
            }

            $builder->where(function (Builder $nested) use ($readyStatuses): void {
                $nested
                    ->whereNotIn(DB::raw('lower(coalesce(report_snapshots.status, \'\'))'), $readyStatuses)
                    ->orWhere(function (Builder $inner): void {
                        $inner->whereIn(DB::raw('lower(coalesce(report_snapshots.status, \'\'))'), ['ready', 'full', 'completed']);

                        if (SchemaBaseline::hasTable('benefit_grants')) {
                            $inner->whereNotExists($this->activeBenefitExistsField());
                        }

                        if (SchemaBaseline::hasTable('orders') || SchemaBaseline::hasTable('payment_events')) {
                            $this->applyPaidSuccessFilter($inner, false);
                        }
                    });
            });
        });
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applyUnlockStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        match ($status) {
            'unlocked' => $query->whereExists($this->activeBenefitExistsField()),
            'paid_pending' => $query
                ->whereNotExists($this->activeBenefitExistsField())
                ->where(function (Builder $builder): void {
                    $this->applyPaidSuccessFilter($builder, true);
                }),
            'payment_pending' => $query
                ->whereNotExists($this->activeBenefitExistsField())
                ->where(function (Builder $builder): void {
                    $this->applyHasOrderFilter($builder, true);
                })
                ->where(function (Builder $builder): void {
                    $this->applyPaidSuccessFilter($builder, false);
                }),
            'refunded' => $query->where(function (Builder $builder): void {
                if (SchemaBaseline::hasTable('orders')) {
                    $builder->whereExists($this->refundedOrderExistsField());
                }

                if (SchemaBaseline::hasTable('payment_events')) {
                    $method = SchemaBaseline::hasTable('orders') ? 'orWhereExists' : 'whereExists';
                    $builder->{$method}($this->refundedPaymentExistsField());
                }
            }),
            'no_order' => $query
                ->whereNotExists($this->orderExistsField())
                ->whereNotExists($this->activeBenefitExistsField()),
            default => null,
        };
    }

    /**
     * @param  Builder<ReportSnapshot>  $query
     */
    public function applyDeliveryStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        if ($status === 'delivered') {
            if (! SchemaBaseline::hasTable('email_outbox')) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereExists($this->deliveryEmailExistsField());

            return;
        }

        if ($status === 'failed') {
            $query->where(function (Builder $builder): void {
                $builder->whereIn(DB::raw('lower(coalesce(report_snapshots.status, \'\'))'), ['failed', 'error']);

                if (SchemaBaseline::hasTable('report_jobs')) {
                    $builder->orWhereExists($this->failedReportJobExistsField());
                }
            });

            return;
        }

        if ($status === 'ready') {
            if (SchemaBaseline::hasTable('email_outbox')) {
                $query->whereNotExists($this->deliveryEmailExistsField());
            }

            $query->where(function (Builder $builder): void {
                $builder->whereNotIn(DB::raw('lower(coalesce(report_snapshots.status, \'\'))'), ['failed', 'error']);

                if (SchemaBaseline::hasTable('report_jobs')) {
                    $builder->whereNotExists($this->failedReportJobExistsField());
                }
            });

            $this->applyPdfReadyFilter($query, true);

            return;
        }

        if ($status === 'pending') {
            if (SchemaBaseline::hasTable('email_outbox')) {
                $query->whereNotExists($this->deliveryEmailExistsField());
            }

            $query->where(function (Builder $builder): void {
                $builder->whereNotIn(DB::raw('lower(coalesce(report_snapshots.status, \'\'))'), ['failed', 'error']);

                if (SchemaBaseline::hasTable('report_jobs')) {
                    $builder->whereNotExists($this->failedReportJobExistsField());
                }
            });

            $this->applyPdfReadyFilter($query, false);
        }
    }

    /**
     * @return array{label:string,state:string}
     */
    public function snapshotStatus(object $snapshot): array
    {
        $status = strtolower(trim((string) ($snapshot->status ?? '')));
        if ($status === '') {
            $status = 'missing';
        }

        return [
            'label' => $status,
            'state' => StatusBadge::color($status),
        ];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function unlockStatus(object $snapshot): array
    {
        if ($this->hasActiveGrant($snapshot)) {
            return ['label' => 'unlocked', 'state' => 'success'];
        }

        if ($this->isRefundedLike((string) ($snapshot->order_status ?? '')) || $this->isRefundedLike((string) ($snapshot->payment_status ?? ''))) {
            return ['label' => 'refunded', 'state' => 'danger'];
        }

        if ($this->isPaidLike((string) ($snapshot->order_status ?? '')) || $this->isPaidLike((string) ($snapshot->payment_status ?? ''))) {
            return ['label' => 'paid_pending', 'state' => 'warning'];
        }

        if ($this->hasOrder($snapshot)) {
            return ['label' => 'payment_pending', 'state' => 'gray'];
        }

        return ['label' => 'no_order', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function pdfStatus(object $snapshot): array
    {
        if ($this->snapshotFailed($snapshot) || $this->reportJobFailed($snapshot)) {
            return ['label' => 'failed', 'state' => 'danger'];
        }

        if ($this->pdfAvailable($snapshot)) {
            return ['label' => 'ready', 'state' => 'success'];
        }

        if ($this->snapshotReady($snapshot)) {
            return ['label' => 'unavailable', 'state' => 'gray'];
        }

        return ['label' => 'pending', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function deliveryStatus(object $snapshot): array
    {
        $lastSentAt = trim((string) ($snapshot->last_delivery_email_sent_at ?? ''));
        if ($lastSentAt !== '') {
            return ['label' => 'delivered', 'state' => 'success'];
        }

        if ($this->snapshotFailed($snapshot) || $this->reportJobFailed($snapshot)) {
            return ['label' => 'failed', 'state' => 'danger'];
        }

        if ($this->pdfAvailable($snapshot)) {
            return ['label' => 'ready', 'state' => 'warning'];
        }

        return ['label' => 'pending', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function reportJobStatus(object $snapshot): array
    {
        $status = strtolower(trim((string) ($snapshot->report_job_status ?? '')));
        if ($status === '') {
            return ['label' => 'missing', 'state' => 'gray'];
        }

        if ($status === 'succeeded') {
            $status = 'succeeded';
        }

        return [
            'label' => $status,
            'state' => StatusBadge::color($status),
        ];
    }

    public function contactEmailPresent(object $snapshot): bool
    {
        return StatusBadge::isTruthy($snapshot->contact_email_present ?? null);
    }

    /**
     * @return array{
     *     headline: array<string, array{label:string,state:string}>,
     *     snapshot_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     pdf_delivery_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     report_job_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     attempt_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     result_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     commerce_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     share_access_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     exception_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *     links: list<array{label:string,url:string,kind:string}>
     * }
     */
    public function buildDetail(ReportSnapshot $snapshot): array
    {
        $attemptId = trim((string) $snapshot->getKey());
        $snapshotRow = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first() ?? $snapshot;
        $attempt = $this->attempt($attemptId);
        $result = $this->result($attemptId);
        $order = $this->order((string) ($snapshotRow->order_no ?? $snapshot->order_no ?? ''), $attemptId);
        $orderNo = trim((string) (($order->order_no ?? null) ?: ($snapshotRow->order_no ?? $snapshot->order_no ?? '')));
        $payment = $this->payment($orderNo);
        $grants = $this->benefitGrants($orderNo, $attemptId);
        $activeGrant = $this->activeGrant($grants);
        $share = $this->share($attemptId);
        $reportJob = $this->reportJob($attemptId);
        $delivery = $order !== null ? app(OrderManager::class)->presentOrderDelivery($order) : [];
        $deliveryState = is_array($delivery['delivery'] ?? null) ? $delivery['delivery'] : [];
        $attribution = $order !== null ? app(OrderManager::class)->extractAttributionFromOrder($order) : [];
        $reportData = $this->arrayFromMixed($snapshot->report_json ?? ($snapshotRow->report_json ?? null));
        $shareId = trim((string) (($share->id ?? null) ?: ($attribution['share_id'] ?? '')));
        $events = $this->recentEvents($attemptId, $shareId);
        $latestEvent = $events[0] ?? null;
        $lastDeliveryEmailSentAt = trim((string) (($deliveryState['last_delivery_email_sent_at'] ?? null) ?: $this->lastDeliveryEmailSentAt($attemptId, $orderNo)));
        $snapshotDisplay = (object) array_merge($snapshot->getAttributes(), [
            'order_status' => $order->status ?? ($snapshot->order_status ?? null),
            'payment_status' => $payment->status ?? ($snapshot->payment_status ?? null),
            'has_active_benefit_grant' => $activeGrant !== null ? 1 : ($snapshot->has_active_benefit_grant ?? 0),
            'report_job_status' => $reportJob->status ?? ($snapshot->report_job_status ?? null),
            'last_delivery_email_sent_at' => $lastDeliveryEmailSentAt !== '' ? $lastDeliveryEmailSentAt : ($snapshot->last_delivery_email_sent_at ?? null),
            'contact_email_present' => ($deliveryState['contact_email_present'] ?? null) ?? $snapshot->contact_email_present ?? ($this->orderHasContactEmailHash($order) ? 1 : 0),
            'has_order' => $order !== null ? 1 : ($snapshot->has_order ?? 0),
        ]);

        $headline = [
            'snapshot' => $this->snapshotStatus($snapshotDisplay),
            'unlock' => $this->unlockStatus($snapshotDisplay),
            'pdf' => $this->pdfStatus($snapshotDisplay),
            'delivery' => $this->deliveryStatus($snapshotDisplay),
            'job' => $this->reportJobStatus($snapshotDisplay),
        ];

        $resultId = ($result->id ?? null) !== null ? (string) $result->id : null;
        $locale = (string) (($attempt->locale ?? null) ?: ($snapshot->locale ?? ''));
        $region = (string) (($attempt->region ?? null) ?: ($snapshot->region ?? ''));
        $reportEndpoint = $attemptId !== '' ? '/api/v0.3/attempts/'.urlencode($attemptId).'/report' : null;
        $pdfEndpoint = $attemptId !== '' ? '/api/v0.3/attempts/'.urlencode($attemptId).'/report.pdf' : null;
        $pdfAvailable = $this->pdfAvailable($snapshotDisplay);
        $shareCreatedAt = $this->formatTimestamp($share->created_at ?? null);
        $orderMeta = $this->arrayFromMixed($order->meta_json ?? null);
        $benefitMeta = $this->arrayFromMixed($activeGrant->meta_json ?? null);
        $claimStatus = $benefitMeta['claim_status'] ?? $orderMeta['claim_status'] ?? null;
        $lastActivityAt = $this->latestTimestamp([
            $snapshotRow->updated_at ?? null,
            $order->updated_at ?? null,
            $result->computed_at ?? null,
            $reportJob->updated_at ?? null,
            $lastDeliveryEmailSentAt,
        ]);

        $snapshotSummary = [
            'fields' => [
                $this->field('attempt_id', $attemptId),
                $this->field('order_no', $orderNo !== '' ? $orderNo : '-'),
                $this->field('org_id', $this->stringOrDash($snapshotRow->org_id ?? $snapshot->org_id ?? null)),
                $this->field('scale_code', $this->stringOrDash($snapshotRow->scale_code ?? $snapshot->scale_code ?? null)),
                $this->field('locale', $this->stringOrDash($locale)),
                $this->field('region', $this->stringOrDash($region)),
                $this->pillField('snapshot_status', $headline['snapshot']['label'], $headline['snapshot']['state']),
                $this->field('report_engine_version', $this->stringOrDash($snapshotRow->report_engine_version ?? $snapshot->report_engine_version ?? null)),
                $this->field('variant', $this->stringOrDash($reportData['variant'] ?? null)),
                $this->field('access_level', $this->stringOrDash($reportData['access_level'] ?? null)),
                $this->pillField(
                    'locked',
                    StatusBadge::isTruthy($reportData['locked'] ?? null) ? 'locked' : 'unlocked',
                    StatusBadge::isTruthy($reportData['locked'] ?? null) ? 'warning' : 'success'
                ),
                $this->field('last_error', $this->shortText($snapshotRow->last_error ?? null)),
                $this->field('updated_at', $this->formatTimestamp($snapshotRow->updated_at ?? $snapshot->updated_at ?? null)),
                $this->field('last_activity_at', $lastActivityAt),
            ],
            'notes' => [__('ops.custom_pages.reports.detail.notes.raw_report_payloads_hidden')],
        ];

        $pdfDeliveryNotes = [__('ops.custom_pages.reports.detail.notes.delivery_read_only')];
        if ($order === null) {
            $pdfDeliveryNotes[] = __('ops.custom_pages.reports.detail.notes.no_linked_order');
        }

        $pdfDeliverySummary = [
            'fields' => [
                $this->pillField('pdf_ready', $headline['pdf']['label'], $headline['pdf']['state']),
                $this->pillField('pdf_available', $pdfAvailable ? 'available' : 'unavailable', $pdfAvailable ? 'success' : 'gray'),
                $this->field('report_endpoint', $this->stringOrDash($reportEndpoint)),
                $this->field('report_pdf_endpoint', $this->stringOrDash($pdfEndpoint)),
                $this->field('last_delivery_email_sent_at', $this->stringOrDash($lastDeliveryEmailSentAt)),
                $this->pillField(
                    'contact_email_present',
                    (($deliveryState['contact_email_present'] ?? false) || $this->orderHasContactEmailHash($order)) ? 'present' : 'missing',
                    (($deliveryState['contact_email_present'] ?? false) || $this->orderHasContactEmailHash($order)) ? 'success' : 'gray'
                ),
                $this->pillField(
                    'can_resend',
                    ($deliveryState['can_resend'] ?? false) ? 'eligible' : 'not_eligible',
                    ($deliveryState['can_resend'] ?? false) ? 'warning' : 'gray'
                ),
                $this->pillField(
                    'can_request_claim_email',
                    ($deliveryState['can_request_claim_email'] ?? false) ? 'available' : 'not_available',
                    ($deliveryState['can_request_claim_email'] ?? false) ? 'warning' : 'gray'
                ),
                $this->pillField('delivery_status', $headline['delivery']['label'], $headline['delivery']['state']),
            ],
            'notes' => $pdfDeliveryNotes,
        ];

        $reportJobNotes = [__('ops.custom_pages.reports.detail.notes.report_jobs_auxiliary')];
        if (filled($reportJob->last_error ?? null)) {
            $reportJobNotes[] = __('ops.custom_pages.reports.detail.notes.compact_report_job_error');
        }

        $reportJobSummary = [
            'fields' => [
                $this->pillField('report_job', $reportJob !== null ? 'present' : 'missing', $reportJob !== null ? 'warning' : 'gray'),
                $this->pillField('report_job_status', $headline['job']['label'], $headline['job']['state']),
                $this->field('report_job_id', $this->stringOrDash($reportJob->id ?? null)),
                $this->field('tries', $this->stringOrDash($reportJob->tries ?? null)),
                $this->field('started_at', $this->formatTimestamp($reportJob->started_at ?? null)),
                $this->field('finished_at', $this->formatTimestamp($reportJob->finished_at ?? null)),
                $this->field('failed_at', $this->formatTimestamp($reportJob->failed_at ?? null)),
                $this->field('last_error', $this->shortText($reportJob->last_error ?? null)),
            ],
            'notes' => $reportJobNotes,
        ];

        $attemptNotes = [__('ops.custom_pages.reports.detail.notes.attempt_read_only')];
        if ($attempt === null) {
            $attemptNotes[] = __('ops.custom_pages.reports.detail.notes.attempt_missing');
        }

        $attemptSummary = [
            'fields' => [
                $this->pillField('attempt', $attempt !== null ? 'present' : 'missing', $attempt !== null ? 'success' : 'danger'),
                $this->field('attempt_id', $attemptId),
                $this->pillField(
                    'submitted',
                    filled($attempt->submitted_at ?? null) ? 'submitted' : 'pending',
                    filled($attempt->submitted_at ?? null) ? 'success' : 'gray'
                ),
                $this->field('submitted_at', $this->formatTimestamp($attempt->submitted_at ?? null)),
                $this->field('ticket_code', $this->stringOrDash($attempt->ticket_code ?? null)),
                $this->field('user_id', $this->stringOrDash($attempt->user_id ?? null)),
                $this->field('anon_id', $this->stringOrDash($attempt->anon_id ?? null)),
                $this->field('channel', $this->stringOrDash($attempt->channel ?? null)),
            ],
            'notes' => $attemptNotes,
        ];

        $resultNotes = [__('ops.custom_pages.reports.detail.notes.raw_result_payloads_hidden')];
        if ($result === null) {
            $resultNotes[] = __('ops.custom_pages.reports.detail.notes.result_missing');
        }

        $resultSummary = [
            'fields' => [
                $this->pillField('result', $result !== null ? 'present' : 'missing', $result !== null ? 'success' : 'gray'),
                $this->field('result_id', $this->stringOrDash($result->id ?? null)),
                $this->field('type_code', $this->stringOrDash($result->type_code ?? null)),
                $this->field('computed_at', $this->formatTimestamp($result->computed_at ?? null)),
                $this->field('scale_code', $this->stringOrDash($result->scale_code ?? null)),
            ],
            'notes' => $resultNotes,
        ];

        $commerceNotes = [
            __('ops.custom_pages.reports.detail.notes.unlock_truth_source'),
            __('ops.custom_pages.reports.detail.notes.payment_payloads_hidden'),
        ];
        if ($order === null) {
            $commerceNotes[] = __('ops.custom_pages.reports.detail.notes.no_order_for_chain');
        }

        $commerceSummary = [
            'fields' => [
                $this->pillField('has_order', $order !== null ? 'present' : 'missing', $order !== null ? 'success' : 'gray'),
                $this->field('order_no', $orderNo !== '' ? $orderNo : '-'),
                $this->pillField('order_status', $this->stringOrDash($order->status ?? null), StatusBadge::color($order->status ?? null)),
                $this->pillField('payment_status', $this->stringOrDash($payment->status ?? null), StatusBadge::color($payment->status ?? null)),
                $this->field('payment_event', $this->stringOrDash($payment->provider_event_id ?? null), $this->stringOrDash($payment->event_type ?? null)),
                $this->pillField('active_benefit_grant', $activeGrant !== null ? 'present' : 'missing', $activeGrant !== null ? 'success' : 'gray'),
                $this->field('benefit_code', $this->stringOrDash($activeGrant->benefit_code ?? null)),
                $this->field('benefit_scope', $this->stringOrDash($activeGrant->scope ?? null)),
                $this->field('benefit_expires_at', $this->formatTimestamp($activeGrant->expires_at ?? null)),
                $this->pillField('unlock_status', $headline['unlock']['label'], $headline['unlock']['state']),
                $this->field('paid_at', $this->formatTimestamp($order->paid_at ?? null)),
                $this->field('claim_status', $this->stringOrDash($claimStatus)),
            ],
            'notes' => $commerceNotes,
        ];

        $shareNotes = [__('ops.custom_pages.reports.detail.notes.share_access_summarized')];
        if ($shareId === '') {
            $shareNotes[] = __('ops.custom_pages.reports.detail.notes.share_missing');
        }

        $shareAccessSummary = [
            'fields' => [
                $this->field('share_id', $shareId !== '' ? $shareId : '-'),
                $this->field('share_created_at', $shareCreatedAt),
                $this->field('entrypoint', $this->stringOrDash($attribution['entrypoint'] ?? null)),
                $this->field('share_click_id', $this->stringOrDash($attribution['share_click_id'] ?? null)),
                $this->field('utm', $this->utmSummary($attribution['utm'] ?? null)),
                $this->field('latest_event', $this->stringOrDash($latestEvent['title'] ?? null)),
                $this->field('latest_channel', $this->stringOrDash($latestEvent['channel'] ?? null)),
            ],
            'notes' => $shareNotes,
        ];

        $exceptionSummary = [
            'fields' => [
                $this->pillField(
                    'grant_exists_but_snapshot_missing',
                    $activeGrant !== null && ! $this->snapshotPresent($snapshotRow) ? 'flagged' : 'clear',
                    $activeGrant !== null && ! $this->snapshotPresent($snapshotRow) ? 'danger' : 'success'
                ),
                $this->pillField(
                    'snapshot_failed',
                    $this->snapshotFailed($snapshotDisplay) ? 'flagged' : 'clear',
                    $this->snapshotFailed($snapshotDisplay) ? 'danger' : 'success'
                ),
                $this->pillField(
                    'report_job_failed',
                    $this->reportJobFailed($snapshotDisplay) ? 'flagged' : 'clear',
                    $this->reportJobFailed($snapshotDisplay) ? 'danger' : 'success'
                ),
                $this->pillField(
                    'pdf_unavailable',
                    $headline['pdf']['label'] === 'unavailable' ? 'flagged' : 'clear',
                    $headline['pdf']['label'] === 'unavailable' ? 'warning' : 'success'
                ),
                $this->pillField(
                    'delivery_pending',
                    $headline['delivery']['label'] === 'pending' ? 'flagged' : 'clear',
                    $headline['delivery']['label'] === 'pending' ? 'warning' : 'success'
                ),
                $this->pillField(
                    'paid_but_no_unlock',
                    $headline['unlock']['label'] === 'paid_pending' ? 'flagged' : 'clear',
                    $headline['unlock']['label'] === 'paid_pending' ? 'warning' : 'success'
                ),
            ],
            'notes' => [__('ops.custom_pages.reports.detail.notes.exception_rules')],
        ];

        return [
            'headline' => $headline,
            'snapshot_summary' => $snapshotSummary,
            'pdf_delivery_summary' => $pdfDeliverySummary,
            'report_job_summary' => $reportJobSummary,
            'attempt_summary' => $attemptSummary,
            'result_summary' => $resultSummary,
            'commerce_summary' => $commerceSummary,
            'share_access_summary' => $shareAccessSummary,
            'exception_summary' => $exceptionSummary,
            'links' => $this->buildLinks(
                $attemptId,
                $orderNo !== '' ? $orderNo : null,
                $shareId !== '' ? $shareId : null,
                $locale,
                ($order->id ?? null) !== null ? (string) $order->id : null,
                $resultId,
                $pdfEndpoint
            ),
        ];
    }

    private function attemptField(string $column): QueryBuilder
    {
        return DB::table('attempts')
            ->select($column)
            ->whereColumn('attempts.id', 'report_snapshots.attempt_id')
            ->limit(1);
    }

    private function resultField(string $column): QueryBuilder
    {
        return DB::table('results')
            ->select($column)
            ->whereColumn('results.attempt_id', 'report_snapshots.attempt_id')
            ->orderByRaw('coalesce(computed_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function orderField(string $column): QueryBuilder
    {
        return DB::table('orders')
            ->select($column)
            ->where(function (QueryBuilder $builder): void {
                $this->applyOrderSnapshotCorrelation($builder);
            })
            ->orderByRaw("case when lower(coalesce(status, '')) in ('paid', 'fulfilled') then 0 else 1 end")
            ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function orderExistsField(): QueryBuilder
    {
        return DB::table('orders')
            ->selectRaw('1')
            ->where(function (QueryBuilder $builder): void {
                $this->applyOrderSnapshotCorrelation($builder);
            })
            ->limit(1);
    }

    private function contactEmailPresentField(): QueryBuilder
    {
        return DB::table('orders')
            ->selectRaw("case when trim(coalesce(contact_email_hash, '')) <> '' then 1 else 0 end")
            ->where(function (QueryBuilder $builder): void {
                $this->applyOrderSnapshotCorrelation($builder);
            })
            ->orderByRaw("case when lower(coalesce(status, '')) in ('paid', 'fulfilled') then 0 else 1 end")
            ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function paymentField(string $column): QueryBuilder
    {
        return DB::table('payment_events')
            ->select($column)
            ->whereColumn('payment_events.order_no', 'report_snapshots.order_no')
            ->orderByRaw('coalesce(processed_at, handled_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function benefitField(string $column): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->select($column)
            ->where(function (QueryBuilder $builder): void {
                $this->applyBenefitSnapshotCorrelation($builder);
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
                $this->applyBenefitSnapshotCorrelation($builder);
            })
            ->limit(1);
    }

    private function shareField(string $column): QueryBuilder
    {
        return DB::table('shares')
            ->select($column)
            ->whereColumn('shares.attempt_id', 'report_snapshots.attempt_id')
            ->orderByDesc('created_at')
            ->limit(1);
    }

    private function reportJobField(string $column): QueryBuilder
    {
        return DB::table('report_jobs')
            ->select($column)
            ->whereColumn('report_jobs.attempt_id', 'report_snapshots.attempt_id')
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function deliveryEmailSentAtField(): QueryBuilder
    {
        $query = DB::table('email_outbox')
            ->select('sent_at')
            ->whereColumn('email_outbox.attempt_id', 'report_snapshots.attempt_id')
            ->whereNotNull('sent_at');

        if (SchemaBaseline::hasColumn('email_outbox', 'template')) {
            $query->whereIn('template', ['payment_success', 'report_claim']);
        } elseif (SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $query->whereIn('template_key', ['payment_success', 'report_claim']);
        }

        return $query
            ->orderByDesc('sent_at')
            ->limit(1);
    }

    private function deliveryEmailExistsField(): QueryBuilder
    {
        $query = DB::table('email_outbox')
            ->selectRaw('1')
            ->whereColumn('email_outbox.attempt_id', 'report_snapshots.attempt_id')
            ->whereNotNull('sent_at');

        if (SchemaBaseline::hasColumn('email_outbox', 'template')) {
            $query->whereIn('template', ['payment_success', 'report_claim']);
        } elseif (SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $query->whereIn('template_key', ['payment_success', 'report_claim']);
        }

        return $query->limit(1);
    }

    private function paidOrderExistsField(): QueryBuilder
    {
        return DB::table('orders')
            ->selectRaw('1')
            ->where(function (QueryBuilder $builder): void {
                $this->applyOrderSnapshotCorrelation($builder);
            })
            ->whereIn(DB::raw('lower(coalesce(orders.status, \'\'))'), ['paid', 'fulfilled', 'complete', 'completed'])
            ->limit(1);
    }

    private function refundedOrderExistsField(): QueryBuilder
    {
        return DB::table('orders')
            ->selectRaw('1')
            ->where(function (QueryBuilder $builder): void {
                $this->applyOrderSnapshotCorrelation($builder);
            })
            ->whereRaw("lower(coalesce(orders.status, '')) like '%refund%'")
            ->limit(1);
    }

    private function paidPaymentExistsField(): QueryBuilder
    {
        return DB::table('payment_events')
            ->selectRaw('1')
            ->whereColumn('payment_events.order_no', 'report_snapshots.order_no')
            ->whereIn(DB::raw('lower(coalesce(payment_events.status, \'\'))'), ['paid', 'succeeded', 'fulfilled', 'complete', 'completed'])
            ->limit(1);
    }

    private function refundedPaymentExistsField(): QueryBuilder
    {
        return DB::table('payment_events')
            ->selectRaw('1')
            ->whereColumn('payment_events.order_no', 'report_snapshots.order_no')
            ->whereRaw("lower(coalesce(payment_events.status, '')) like '%refund%'")
            ->limit(1);
    }

    private function failedReportJobExistsField(): QueryBuilder
    {
        return DB::table('report_jobs')
            ->selectRaw('1')
            ->whereColumn('report_jobs.attempt_id', 'report_snapshots.attempt_id')
            ->whereIn(DB::raw('lower(coalesce(report_jobs.status, \'\'))'), ['failed', 'error'])
            ->limit(1);
    }

    private function attempt(string $attemptId): ?object
    {
        if ($attemptId === '' || ! SchemaBaseline::hasTable('attempts')) {
            return null;
        }

        return DB::table('attempts')->where('id', $attemptId)->first();
    }

    private function result(string $attemptId): ?object
    {
        if ($attemptId === '' || ! SchemaBaseline::hasTable('results')) {
            return null;
        }

        return DB::table('results')
            ->where('attempt_id', $attemptId)
            ->orderByRaw('coalesce(computed_at, updated_at, created_at) desc')
            ->first();
    }

    private function order(string $orderNo, string $attemptId): ?object
    {
        if (! SchemaBaseline::hasTable('orders')) {
            return null;
        }

        $query = DB::table('orders')
            ->where(function (QueryBuilder $builder) use ($orderNo, $attemptId): void {
                $hasAny = false;

                if ($orderNo !== '' && SchemaBaseline::hasColumn('orders', 'order_no')) {
                    $builder->where('orders.order_no', $orderNo);
                    $hasAny = true;
                }

                if ($attemptId !== '' && SchemaBaseline::hasColumn('orders', 'target_attempt_id')) {
                    if ($hasAny) {
                        $builder->orWhere('orders.target_attempt_id', $attemptId);
                    } else {
                        $builder->where('orders.target_attempt_id', $attemptId);
                        $hasAny = true;
                    }
                }

                if (! $hasAny) {
                    $builder->whereRaw('1 = 0');
                }
            })
            ->orderByRaw("case when lower(coalesce(status, '')) in ('paid', 'fulfilled') then 0 else 1 end")
            ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc');

        return $query->first();
    }

    private function payment(string $orderNo): ?object
    {
        if ($orderNo === '' || ! SchemaBaseline::hasTable('payment_events')) {
            return null;
        }

        return DB::table('payment_events')
            ->where('order_no', $orderNo)
            ->orderByRaw('coalesce(processed_at, handled_at, updated_at, created_at) desc')
            ->first();
    }

    /**
     * @return list<object>
     */
    private function benefitGrants(string $orderNo, string $attemptId): array
    {
        if (! SchemaBaseline::hasTable('benefit_grants')) {
            return [];
        }

        $query = DB::table('benefit_grants')
            ->where(function (QueryBuilder $builder) use ($orderNo, $attemptId): void {
                $hasAny = false;

                if ($attemptId !== '' && SchemaBaseline::hasColumn('benefit_grants', 'attempt_id')) {
                    $builder->where('benefit_grants.attempt_id', $attemptId);
                    $hasAny = true;
                }

                if ($orderNo !== '' && SchemaBaseline::hasColumn('benefit_grants', 'order_no')) {
                    if ($hasAny) {
                        $builder->orWhere('benefit_grants.order_no', $orderNo);
                    } else {
                        $builder->where('benefit_grants.order_no', $orderNo);
                        $hasAny = true;
                    }
                }

                if (! $hasAny) {
                    $builder->whereRaw('1 = 0');
                }
            })
            ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc');

        return $query->get()->all();
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

    private function share(string $attemptId): ?object
    {
        if ($attemptId === '' || ! SchemaBaseline::hasTable('shares')) {
            return null;
        }

        return DB::table('shares')
            ->where('attempt_id', $attemptId)
            ->orderByDesc('created_at')
            ->first();
    }

    private function reportJob(string $attemptId): ?object
    {
        if ($attemptId === '' || ! SchemaBaseline::hasTable('report_jobs')) {
            return null;
        }

        return DB::table('report_jobs')
            ->where('attempt_id', $attemptId)
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->first();
    }

    private function lastDeliveryEmailSentAt(string $attemptId, string $orderNo): ?string
    {
        if (
            $attemptId === ''
            || ! SchemaBaseline::hasTable('email_outbox')
            || ! SchemaBaseline::hasColumn('email_outbox', 'attempt_id')
            || ! SchemaBaseline::hasColumn('email_outbox', 'sent_at')
        ) {
            return null;
        }

        $query = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->whereNotNull('sent_at');

        if (SchemaBaseline::hasColumn('email_outbox', 'template')) {
            $query->whereIn('template', ['payment_success', 'report_claim']);
        } elseif (SchemaBaseline::hasColumn('email_outbox', 'template_key')) {
            $query->whereIn('template_key', ['payment_success', 'report_claim']);
        }

        $sentAt = $query->orderByDesc('sent_at')->value('sent_at');

        return $sentAt !== null ? (string) $sentAt : null;
    }

    /**
     * @return list<array{title:string,occurred_at:string,channel:string,meta:list<string>}>
     */
    private function recentEvents(string $attemptId, string $shareId): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $query = DB::table('events')
            ->select(['event_code', 'occurred_at', 'channel', 'meta_json']);

        if (SchemaBaseline::hasColumn('events', 'share_id')) {
            $query->addSelect('share_id');
        }

        $query->where(function (QueryBuilder $builder) use ($attemptId, $shareId): void {
            $hasAny = false;

            if ($attemptId !== '' && SchemaBaseline::hasColumn('events', 'attempt_id')) {
                $builder->where('attempt_id', $attemptId);
                $hasAny = true;
            }

            if ($shareId !== '' && SchemaBaseline::hasColumn('events', 'share_id')) {
                if ($hasAny) {
                    $builder->orWhere('share_id', $shareId);
                } else {
                    $builder->where('share_id', $shareId);
                    $hasAny = true;
                }
            }

            if (! $hasAny) {
                $builder->whereRaw('1 = 0');
            }
        });

        return $query
            ->orderByRaw('coalesce(occurred_at, created_at) desc')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'title' => $this->stringOrDash($row->event_code ?? null),
                'occurred_at' => $this->formatTimestamp($row->occurred_at ?? null),
                'channel' => $this->stringOrDash($row->channel ?? null),
                'meta' => $this->summarizeMeta($this->arrayFromMixed($row->meta_json ?? null)),
            ])
            ->all();
    }

    /**
     * @return list<array{label:string,url:string,kind:string}>
     */
    private function buildLinks(
        string $attemptId,
        ?string $orderNo,
        ?string $shareId,
        string $locale,
        ?string $orderId,
        ?string $resultId,
        ?string $pdfEndpoint
    ): array {
        $base = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $links = [];
        $localeSegment = $this->frontendLocaleSegment($locale);

        if ($base !== '') {
            if ($orderNo !== null && $orderNo !== '') {
                $links[] = [
                    'label' => __('ops.custom_pages.reports.detail.links.order_page'),
                    'url' => $base.'/'.$localeSegment.'/orders/'.urlencode($orderNo),
                    'kind' => 'frontend',
                ];
            }

            if ($attemptId !== '') {
                $links[] = [
                    'label' => __('ops.custom_pages.reports.detail.links.result_page'),
                    'url' => $base.'/'.$localeSegment.'/result/'.urlencode($attemptId),
                    'kind' => 'frontend',
                ];
                $links[] = [
                    'label' => __('ops.custom_pages.reports.detail.links.report_page'),
                    'url' => $base.'/'.$localeSegment.'/attempts/'.urlencode($attemptId).'/report',
                    'kind' => 'frontend',
                ];
            }

            if ($shareId !== null && $shareId !== '') {
                $links[] = [
                    'label' => __('ops.custom_pages.reports.detail.links.share_page'),
                    'url' => $base.'/'.$localeSegment.'/share/'.urlencode($shareId),
                    'kind' => 'frontend',
                ];
            }
        }

        if ($pdfEndpoint !== null && $pdfEndpoint !== '') {
            $links[] = [
                'label' => __('ops.custom_pages.reports.detail.links.pdf_endpoint'),
                'url' => $pdfEndpoint,
                'kind' => 'frontend',
            ];
        }

        if ($attemptId !== '') {
            $links[] = [
                'label' => __('ops.custom_pages.reports.detail.links.attempt_explorer'),
                'url' => AttemptResource::getUrl('view', ['record' => $attemptId]),
                'kind' => 'ops',
            ];
        }

        if ($resultId !== null && $resultId !== '') {
            $links[] = [
                'label' => __('ops.custom_pages.reports.detail.links.result_diagnostics'),
                'url' => ResultResource::getUrl('view', ['record' => $resultId]),
                'kind' => 'ops',
            ];
        }

        if ($orderId !== null && $orderId !== '') {
            $links[] = [
                'label' => __('ops.custom_pages.reports.detail.links.order_diagnostics'),
                'url' => OrderResource::getUrl('view', ['record' => $orderId]),
                'kind' => 'ops',
            ];
        }

        $links[] = [
            'label' => __('ops.custom_pages.reports.detail.links.order_lookup'),
            'url' => '/ops/order-lookup',
            'kind' => 'ops',
        ];

        return $links;
    }

    private function snapshotReady(object $snapshot): bool
    {
        return in_array(strtolower(trim((string) ($snapshot->status ?? ''))), ['ready', 'full', 'completed'], true);
    }

    private function snapshotFailed(object $snapshot): bool
    {
        return in_array(strtolower(trim((string) ($snapshot->status ?? ''))), ['failed', 'error'], true);
    }

    private function reportJobFailed(object $snapshot): bool
    {
        return in_array(strtolower(trim((string) ($snapshot->report_job_status ?? ''))), ['failed', 'error'], true);
    }

    private function snapshotPresent(object $snapshot): bool
    {
        return trim((string) ($snapshot->attempt_id ?? '')) !== '';
    }

    private function pdfAvailable(object $snapshot): bool
    {
        return $this->snapshotReady($snapshot)
            && trim((string) ($snapshot->attempt_id ?? '')) !== ''
            && ($this->hasActiveGrant($snapshot) || $this->isPaidLike((string) ($snapshot->order_status ?? '')) || $this->isPaidLike((string) ($snapshot->payment_status ?? '')));
    }

    private function hasOrder(object $snapshot): bool
    {
        return StatusBadge::isTruthy($snapshot->has_order ?? null)
            || trim((string) ($snapshot->order_no ?? '')) !== ''
            || trim((string) ($snapshot->order_id ?? '')) !== '';
    }

    private function hasActiveGrant(object $snapshot): bool
    {
        return StatusBadge::isTruthy($snapshot->has_active_benefit_grant ?? null)
            || strtolower(trim((string) ($snapshot->benefit_status ?? ''))) === 'active';
    }

    private function orderHasContactEmailHash(?object $order): bool
    {
        if ($order === null) {
            return false;
        }

        return trim((string) ($order->contact_email_hash ?? '')) !== '';
    }

    private function applyOrderSnapshotCorrelation(QueryBuilder $builder): void
    {
        $hasAny = false;

        if (SchemaBaseline::hasColumn('orders', 'order_no') && SchemaBaseline::hasColumn('report_snapshots', 'order_no')) {
            $builder->whereColumn('orders.order_no', 'report_snapshots.order_no');
            $hasAny = true;
        }

        if (SchemaBaseline::hasColumn('orders', 'target_attempt_id')) {
            if ($hasAny) {
                $builder->orWhereColumn('orders.target_attempt_id', 'report_snapshots.attempt_id');
            } else {
                $builder->whereColumn('orders.target_attempt_id', 'report_snapshots.attempt_id');
                $hasAny = true;
            }
        }

        if (! $hasAny) {
            $builder->whereRaw('1 = 0');
        }
    }

    private function applyBenefitSnapshotCorrelation(QueryBuilder $builder): void
    {
        $hasAny = false;

        if (SchemaBaseline::hasColumn('benefit_grants', 'attempt_id')) {
            $builder->whereColumn('benefit_grants.attempt_id', 'report_snapshots.attempt_id');
            $hasAny = true;
        }

        if (SchemaBaseline::hasColumn('benefit_grants', 'order_no') && SchemaBaseline::hasColumn('report_snapshots', 'order_no')) {
            if ($hasAny) {
                $builder->orWhereColumn('benefit_grants.order_no', 'report_snapshots.order_no');
            } else {
                $builder->whereColumn('benefit_grants.order_no', 'report_snapshots.order_no');
                $hasAny = true;
            }
        }

        if (! $hasAny) {
            $builder->whereRaw('1 = 0');
        }
    }

    private function isPaidLike(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['paid', 'fulfilled', 'complete', 'completed', 'succeeded'], true);
    }

    private function isRefundedLike(string $status): bool
    {
        return str_contains(strtolower(trim($status)), 'refund');
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }

    /**
     * @return array{label:string,value:string,hint:?string,kind:string,state:?string}
     */
    private function field(string $label, string $value, ?string $hint = null): array
    {
        return [
            'label' => $this->displayFieldLabel($label),
            'value' => $value !== '' ? $value : '-',
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
            'label' => $this->displayFieldLabel($label),
            'value' => $this->displayStatusLabel($value),
            'hint' => $hint,
            'kind' => 'pill',
            'state' => $state,
        ];
    }

    public function displayStatusLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return '-';
        }

        return $this->translated('ops.custom_pages.reports.statuses.'.$value, $value);
    }

    private function displayFieldLabel(string $label): string
    {
        return $this->translated('ops.custom_pages.reports.fields.'.$label, $label);
    }

    private function translated(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }

    private function stringOrDash(mixed $value): string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : '-';
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return $this->stringOrDash($value);
        }
    }

    /**
     * @param  array<mixed>  $values
     */
    private function latestTimestamp(array $values): string
    {
        $latest = null;

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            try {
                $parsed = $value instanceof Carbon ? $value : Carbon::parse((string) $value);
            } catch (\Throwable) {
                continue;
            }

            if ($latest === null || $parsed->greaterThan($latest)) {
                $latest = $parsed;
            }
        }

        return $latest?->toDateTimeString() ?? '-';
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFromMixed(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function shortText(mixed $value, int $limit = 140): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '-';
        }

        return Str::limit($text, $limit, '...');
    }

    private function utmSummary(mixed $utm): string
    {
        if (! is_array($utm)) {
            return '-';
        }

        $parts = [];

        foreach (['source', 'medium', 'campaign'] as $key) {
            $value = trim((string) ($utm[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $key.'='.$value;
            }
        }

        return $parts === [] ? '-' : implode(' | ', $parts);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function summarizeMeta(array $meta): array
    {
        $summary = [];

        foreach (['status', 'stage', 'variant', 'locked', 'access_level', 'reason', 'provider'] as $key) {
            $value = $meta[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $summary[] = $key.'='.$this->scalarSummary($value);
        }

        return $summary;
    }

    private function scalarSummary(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return Str::limit((string) $value, 32, '...');
        }

        if (is_array($value)) {
            return 'array('.count($value).')';
        }

        return '-';
    }
}
