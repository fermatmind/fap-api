<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\AttemptResource\Support;

use App\Models\Attempt;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AttemptExplorerSupport
{
    /**
     * @return Builder<Attempt>
     */
    public function query(): Builder
    {
        $query = Attempt::query()
            ->withoutGlobalScopes()
            ->select('attempts.*')
            ->selectRaw('coalesce(attempts.submitted_at, attempts.created_at) as activity_at');

        if (SchemaBaseline::hasTable('attempt_submissions')) {
            $query
                ->selectSub($this->latestAttemptSubmissionField('state'), 'latest_submission_state')
                ->selectSub($this->latestAttemptSubmissionField('mode'), 'latest_submission_mode');
        }

        if (SchemaBaseline::hasTable('results')) {
            $query
                ->selectSub($this->latestAttemptResultField('id'), 'latest_result_id')
                ->selectSub($this->latestAttemptResultField('type_code'), 'latest_result_type_code')
                ->selectSub($this->latestAttemptResultField('computed_at'), 'latest_result_computed_at')
                ->selectSub($this->latestAttemptResultField('report_engine_version'), 'latest_result_report_engine_version');
        }

        if (SchemaBaseline::hasTable('report_snapshots')) {
            $query
                ->selectSub($this->latestReportSnapshotField('status'), 'latest_report_snapshot_status')
                ->selectSub($this->latestReportSnapshotField('order_no'), 'latest_snapshot_order_no')
                ->selectSub($this->latestReportSnapshotField('report_engine_version'), 'latest_snapshot_report_engine_version');
        }

        if (SchemaBaseline::hasTable('orders')) {
            $query
                ->selectSub($this->latestAttemptOrderField('order_no'), 'latest_order_no')
                ->selectSub($this->latestAttemptOrderField('status'), 'latest_order_status')
                ->selectSub($this->latestAttemptOrderField('provider'), 'latest_order_provider')
                ->selectSub($this->latestAttemptOrderField('paid_at'), 'latest_order_paid_at');
        }

        if (SchemaBaseline::hasTable('payment_events')) {
            $query->selectSub($this->latestAttemptPaymentField('status'), 'latest_payment_status');
        }

        if (SchemaBaseline::hasTable('benefit_grants')) {
            $query->selectSub($this->latestAttemptBenefitField('status'), 'latest_benefit_status');
        }

        if (SchemaBaseline::hasTable('shares')) {
            $query->selectSub($this->latestAttemptShareField('id'), 'latest_share_id');
        }

        return $query;
    }

    /**
     * @param  Builder<Attempt>  $query
     */
    public function applySearch(Builder $query, string $search): void
    {
        $needle = trim($search);
        if ($needle === '') {
            return;
        }

        $like = '%'.$needle.'%';

        $query->where(function (Builder $attemptQuery) use ($like): void {
            $attemptQuery
                ->where('attempts.id', 'like', $like)
                ->orWhere('attempts.anon_id', 'like', $like)
                ->orWhere('attempts.user_id', 'like', $like)
                ->orWhere('attempts.ticket_code', 'like', $like);

            if (SchemaBaseline::hasTable('orders')) {
                $attemptQuery->orWhereExists(function (QueryBuilder $orderQuery) use ($like): void {
                    $orderQuery
                        ->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.target_attempt_id', 'attempts.id')
                        ->where('orders.order_no', 'like', $like);
                });
            }

            if (SchemaBaseline::hasTable('shares')) {
                $attemptQuery->orWhereExists(function (QueryBuilder $shareQuery) use ($like): void {
                    $shareQuery
                        ->selectRaw('1')
                        ->from('shares')
                        ->whereColumn('shares.attempt_id', 'attempts.id')
                        ->where('shares.id', 'like', $like);
                });
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function distinctAttemptOptions(string $column): array
    {
        if (! SchemaBaseline::hasColumn('attempts', $column)) {
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
    public function distinctReportEngineVersionOptions(): array
    {
        $values = collect();

        if (SchemaBaseline::hasColumn('results', 'report_engine_version')) {
            $values = $values->merge(
                DB::table('results')
                    ->whereNotNull('report_engine_version')
                    ->where('report_engine_version', '!=', '')
                    ->distinct()
                    ->pluck('report_engine_version')
                    ->map(fn ($value): string => (string) $value)
            );
        }

        if (SchemaBaseline::hasColumn('report_snapshots', 'report_engine_version')) {
            $values = $values->merge(
                DB::table('report_snapshots')
                    ->whereNotNull('report_engine_version')
                    ->where('report_engine_version', '!=', '')
                    ->distinct()
                    ->pluck('report_engine_version')
                    ->map(fn ($value): string => (string) $value)
            );
        }

        return $values
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $value): array => [$value => $value])
            ->all();
    }

    /**
     * @param  Builder<Attempt>  $query
     */
    public function applyUnlockStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        match ($status) {
            'unlocked' => $query->whereExists($this->activeBenefitExistsQuery()),
            'paid_pending' => $query->whereExists($this->paidOrderExistsQuery())
                ->whereNotExists($this->activeBenefitExistsQuery()),
            'payment_pending' => $query->whereExists($this->orderExistsQuery())
                ->whereNotExists($this->paidOrderExistsQuery())
                ->whereNotExists($this->refundedOrderExistsQuery()),
            'refunded' => $query->whereExists($this->refundedOrderExistsQuery()),
            'no_order' => $query->whereNotExists($this->orderExistsQuery()),
            default => null,
        };
    }

    /**
     * @param  Builder<Attempt>  $query
     */
    public function applyReportEngineVersionFilter(Builder $query, ?string $value): void
    {
        $version = trim((string) $value);
        if ($version === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($version): void {
            if (SchemaBaseline::hasColumn('results', 'report_engine_version')) {
                $builder->whereExists(function (QueryBuilder $resultQuery) use ($version): void {
                    $resultQuery
                        ->selectRaw('1')
                        ->from('results')
                        ->whereColumn('results.attempt_id', 'attempts.id')
                        ->where('results.report_engine_version', $version);
                });
            }

            if (SchemaBaseline::hasColumn('report_snapshots', 'report_engine_version')) {
                $builder->orWhereExists(function (QueryBuilder $snapshotQuery) use ($version): void {
                    $snapshotQuery
                        ->selectRaw('1')
                        ->from('report_snapshots')
                        ->whereColumn('report_snapshots.attempt_id', 'attempts.id')
                        ->where('report_snapshots.report_engine_version', $version);
                });
            }
        });
    }

    /**
     * @return array{label:string,state:string}
     */
    public function submittedStatus(Attempt $attempt): array
    {
        $state = strtolower(trim((string) ($attempt->latest_submission_state ?? '')));

        if ($state !== '') {
            return [
                'label' => $state,
                'state' => match ($state) {
                    'succeeded', 'ready' => 'success',
                    'pending', 'running' => 'warning',
                    'failed' => 'danger',
                    default => 'gray',
                },
            ];
        }

        if ($attempt->submitted_at !== null) {
            return ['label' => 'submitted', 'state' => 'success'];
        }

        return ['label' => 'draft', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function resultStatus(Attempt $attempt): array
    {
        return filled((string) ($attempt->latest_result_id ?? ''))
            ? ['label' => 'present', 'state' => 'success']
            : ['label' => 'missing', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function reportStatus(Attempt $attempt): array
    {
        $status = strtolower(trim((string) ($attempt->latest_report_snapshot_status ?? '')));

        if ($status === '') {
            return ['label' => 'missing', 'state' => 'gray'];
        }

        return [
            'label' => $status,
            'state' => match (true) {
                in_array($status, ['ready', 'full', 'completed'], true) => 'success',
                in_array($status, ['pending', 'queued', 'running'], true) => 'warning',
                in_array($status, ['failed', 'error'], true) => 'danger',
                default => 'gray',
            },
        ];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function unlockStatus(Attempt $attempt): array
    {
        $benefitStatus = strtolower(trim((string) ($attempt->latest_benefit_status ?? '')));
        $orderStatus = strtolower(trim((string) ($attempt->latest_order_status ?? '')));
        $paymentStatus = strtolower(trim((string) ($attempt->latest_payment_status ?? '')));

        if ($benefitStatus === 'active') {
            return ['label' => 'unlocked', 'state' => 'success'];
        }

        if ($this->isPaidLike($orderStatus) || $this->isPaidLike($paymentStatus)) {
            return ['label' => 'paid_pending', 'state' => 'warning'];
        }

        if ($this->isRefundedLike($orderStatus)) {
            return ['label' => 'refunded', 'state' => 'danger'];
        }

        if ($orderStatus !== '') {
            return ['label' => 'payment_pending', 'state' => 'gray'];
        }

        return ['label' => 'no_order', 'state' => 'gray'];
    }

    public function latestOrderNo(Attempt $attempt): string
    {
        $orderNo = trim((string) ($attempt->latest_order_no ?? ''));
        if ($orderNo !== '') {
            return $orderNo;
        }

        return trim((string) ($attempt->latest_snapshot_order_no ?? ''));
    }

    public function latestShareId(Attempt $attempt): string
    {
        return trim((string) ($attempt->latest_share_id ?? ''));
    }

    public function reportEngineVersion(Attempt $attempt): string
    {
        $resultVersion = trim((string) ($attempt->latest_result_report_engine_version ?? ''));
        if ($resultVersion !== '') {
            return $resultVersion;
        }

        return trim((string) ($attempt->latest_snapshot_report_engine_version ?? ''));
    }

    /**
     * @return array{
     *   headline: array<string, array{label:string,state:string}>,
     *   attempt_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>},
     *   answers_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   result_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   report_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   commerce_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   event_timeline: list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>,
     *   links: list<array{label:string,url:string,kind:string}>
     * }
     */
    public function buildDetail(Attempt $attempt): array
    {
        $result = $this->fetchResult($attempt);
        $snapshot = $this->fetchReportSnapshot($attempt);
        $answerSummary = $this->fetchAnswersSummary($attempt);
        $commerce = $this->fetchCommerceSummary($attempt);
        $timeline = $this->fetchEventTimeline($attempt, $commerce['share_id']);
        $reportJobSummary = $this->fetchReportJobSummary($attempt);
        $localeSegment = $this->frontendLocaleSegment((string) ($attempt->locale ?? ''));

        $headline = [
            'submitted' => $this->submittedStatus($attempt),
            'result' => $this->resultStatus($attempt),
            'report' => $this->reportStatus($attempt),
            'unlock' => $this->unlockStatus($attempt),
        ];

        $attemptSummary = [
            'fields' => [
                $this->field('attempt_id', (string) $attempt->id),
                $this->field('org_id', (string) ($attempt->org_id ?? '-')),
                $this->field('user_id', $this->stringOrDash($attempt->user_id)),
                $this->field('anon_id', $this->stringOrDash($attempt->anon_id)),
                $this->field('scale_code', $this->stringOrDash($attempt->scale_code)),
                $this->field('scale_uid', $this->stringOrDash($attempt->scale_uid)),
                $this->field('locale', $this->stringOrDash($attempt->locale)),
                $this->field('region', $this->stringOrDash($attempt->region)),
                $this->field('channel', $this->stringOrDash($attempt->channel)),
                $this->field('created_at', $this->formatTimestamp($attempt->created_at)),
                $this->field('submitted_at', $this->formatTimestamp($attempt->submitted_at)),
                $this->pillField('status', $headline['submitted']['label'], $headline['submitted']['state'], $this->stringOrNull($attempt->latest_submission_mode)),
                $this->field('pack_id', $this->stringOrDash($attempt->pack_id)),
                $this->field('dir_version', $this->stringOrDash($attempt->dir_version)),
                $this->field('content_package_version', $this->stringOrDash($attempt->content_package_version)),
                $this->field('scoring_spec_version', $this->stringOrDash($attempt->scoring_spec_version)),
                $this->field('norm_version', $this->stringOrDash($attempt->norm_version)),
                $this->field('scale_version', $this->stringOrDash($attempt->scale_version)),
                $this->field('report_engine_version', $this->stringOrDash($this->reportEngineVersion($attempt))),
            ],
        ];

        $answersSummarySection = [
            'fields' => [
                $this->pillField('answer_set', $answerSummary['exists'] ? 'present' : 'missing', $answerSummary['exists'] ? 'success' : 'gray'),
                $this->field('answers_hash', $answerSummary['answers_hash']),
                $this->field('row_count', (string) $answerSummary['row_count']),
                $this->pillField('storage_mode', $answerSummary['storage_mode'], $answerSummary['storage_state']),
                $this->field('question_count', $answerSummary['question_count']),
            ],
            'notes' => $answerSummary['notes'],
        ];

        $resultSummarySection = [
            'fields' => [
                $this->pillField('result', $result['exists'] ? 'present' : 'missing', $result['exists'] ? 'success' : 'gray'),
                $this->field('computed_at', $result['computed_at']),
                $this->field('type_code', $result['type_code']),
                $this->pillField('validity', $result['validity_label'], $result['validity_state']),
                $this->field('profile_version', $result['profile_version']),
                $this->field('content_package_version', $result['content_package_version']),
                $this->field('scoring_spec_version', $result['scoring_spec_version']),
                $this->field('report_engine_version', $result['report_engine_version']),
            ],
            'notes' => $result['notes'],
        ];

        $reportSummarySection = [
            'fields' => [
                $this->pillField('snapshot', $snapshot['exists'] ? 'present' : 'missing', $snapshot['exists'] ? 'success' : 'gray'),
                $this->pillField('status', $snapshot['status'], $snapshot['status_state']),
                $this->pillField('locked', $snapshot['locked'], $snapshot['locked_state']),
                $this->field('access_level', $snapshot['access_level']),
                $this->field('variant', $snapshot['variant']),
                $this->field('pack_id', $snapshot['pack_id']),
                $this->field('dir_version', $snapshot['dir_version']),
                $this->field('scoring_spec_version', $snapshot['scoring_spec_version']),
                $this->field('report_engine_version', $snapshot['report_engine_version']),
                $this->field('snapshot_version', $snapshot['snapshot_version']),
                $this->field('report_jobs', $reportJobSummary['summary'], $reportJobSummary['hint']),
            ],
            'notes' => $snapshot['notes'],
        ];

        $commerceSummarySection = [
            'fields' => [
                $this->field('order_no', $commerce['order_no']),
                $this->pillField('order_status', $commerce['order_status'], $commerce['order_status_state']),
                $this->field('payment_summary', $commerce['payment_summary'], $commerce['payment_hint']),
                $this->field('active_benefit_grant', $commerce['benefit_summary'], $commerce['benefit_hint']),
                $this->field('entitlement', $commerce['entitlement']),
                $this->field('delivery', $commerce['delivery']),
                $this->field('claim', $commerce['claim']),
                $this->field('share_id', $commerce['share_id']),
            ],
            'notes' => $commerce['notes'],
        ];

        return [
            'headline' => $headline,
            'attempt_summary' => $attemptSummary,
            'answers_summary' => $answersSummarySection,
            'result_summary' => $resultSummarySection,
            'report_summary' => $reportSummarySection,
            'commerce_summary' => $commerceSummarySection,
            'event_timeline' => $timeline,
            'links' => $this->buildLinks($attempt, $localeSegment, $commerce['order_no'], $commerce['share_id']),
        ];
    }

    private function latestAttemptSubmissionField(string $column): QueryBuilder
    {
        return DB::table('attempt_submissions')
            ->select($column)
            ->whereColumn('attempt_submissions.attempt_id', 'attempts.id')
            ->orderByRaw('coalesce(finished_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestAttemptResultField(string $column): QueryBuilder
    {
        return DB::table('results')
            ->select($column)
            ->whereColumn('results.attempt_id', 'attempts.id')
            ->orderByRaw('coalesce(computed_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestReportSnapshotField(string $column): QueryBuilder
    {
        return DB::table('report_snapshots')
            ->select($column)
            ->whereColumn('report_snapshots.attempt_id', 'attempts.id')
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestAttemptOrderField(string $column): QueryBuilder
    {
        return DB::table('orders')
            ->select($column)
            ->whereColumn('orders.target_attempt_id', 'attempts.id')
            ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestAttemptPaymentField(string $column): QueryBuilder
    {
        return DB::table('payment_events')
            ->select($column)
            ->whereExists(function (QueryBuilder $orderQuery): void {
                $orderQuery
                    ->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.order_no', 'payment_events.order_no')
                    ->whereColumn('orders.target_attempt_id', 'attempts.id');
            })
            ->orderByRaw('coalesce(processed_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestAttemptBenefitField(string $column): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->select($column)
            ->where(function (QueryBuilder $benefitQuery): void {
                $benefitQuery->whereColumn('benefit_grants.attempt_id', 'attempts.id')
                    ->orWhereExists(function (QueryBuilder $orderQuery): void {
                        $orderQuery
                            ->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.order_no', 'benefit_grants.order_no')
                            ->whereColumn('orders.target_attempt_id', 'attempts.id');
                    });
            })
            ->orderByRaw("case when lower(status) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestAttemptShareField(string $column): QueryBuilder
    {
        return DB::table('shares')
            ->select($column)
            ->whereColumn('shares.attempt_id', 'attempts.id')
            ->orderByDesc('created_at')
            ->limit(1);
    }

    private function orderExistsQuery(): \Closure
    {
        return function (QueryBuilder $orderQuery): void {
            if (! SchemaBaseline::hasTable('orders')) {
                $orderQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $orderQuery
                ->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.target_attempt_id', 'attempts.id');
        };
    }

    private function paidOrderExistsQuery(): \Closure
    {
        return function (QueryBuilder $orderQuery): void {
            if (! SchemaBaseline::hasTable('orders')) {
                $orderQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $orderQuery
                ->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.target_attempt_id', 'attempts.id')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereRaw("lower(coalesce(orders.status, '')) in (?, ?, ?, ?)", ['paid', 'fulfilled', 'complete', 'completed']);

                    if (SchemaBaseline::hasColumn('orders', 'paid_at')) {
                        $builder->orWhereNotNull('orders.paid_at');
                    }
                });
        };
    }

    private function refundedOrderExistsQuery(): \Closure
    {
        return function (QueryBuilder $orderQuery): void {
            if (! SchemaBaseline::hasTable('orders')) {
                $orderQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $orderQuery
                ->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.target_attempt_id', 'attempts.id')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereRaw("lower(coalesce(orders.status, '')) like ?", ['%refund%']);

                    if (SchemaBaseline::hasColumn('orders', 'refunded_at')) {
                        $builder->orWhereNotNull('orders.refunded_at');
                    }
                });
        };
    }

    private function activeBenefitExistsQuery(): \Closure
    {
        return function (QueryBuilder $benefitQuery): void {
            if (! SchemaBaseline::hasTable('benefit_grants')) {
                $benefitQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $benefitQuery
                ->selectRaw('1')
                ->from('benefit_grants')
                ->where('benefit_grants.status', 'active')
                ->where(function (QueryBuilder $builder): void {
                    $builder->whereColumn('benefit_grants.attempt_id', 'attempts.id')
                        ->orWhereExists(function (QueryBuilder $orderQuery): void {
                            $orderQuery
                                ->selectRaw('1')
                                ->from('orders')
                                ->whereColumn('orders.order_no', 'benefit_grants.order_no')
                                ->whereColumn('orders.target_attempt_id', 'attempts.id');
                        });
                });
        };
    }

    /**
     * @return array{
     *   exists:bool,
     *   answers_hash:string,
     *   row_count:int,
     *   question_count:string,
     *   storage_mode:string,
     *   storage_state:string,
     *   notes:list<string>
     * }
     */
    private function fetchAnswersSummary(Attempt $attempt): array
    {
        $answerSet = null;

        if (SchemaBaseline::hasTable('attempt_answer_sets')) {
            $answerSet = DB::table('attempt_answer_sets')
                ->where('attempt_id', (string) $attempt->id)
                ->first();
        }

        $rowCount = 0;
        if (SchemaBaseline::hasTable('attempt_answer_rows')) {
            $rowCount = (int) DB::table('attempt_answer_rows')
                ->where('attempt_id', (string) $attempt->id)
                ->count();
        }

        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        $answersHash = trim((string) (($answerSet->answers_hash ?? null) ?: ($attempt->answers_hash ?? '')));
        $questionCount = (string) (($answerSet->question_count ?? null) ?: ($attempt->question_count ?? '-'));
        $storedAnswers = $answerSet->answers_json ?? null;

        $storageMode = 'missing';
        $storageState = 'gray';

        if ($answerSet !== null) {
            if ($scaleCode === 'CLINICAL_COMBO_68' || ($storedAnswers === null && $rowCount === 0 && $answersHash !== '')) {
                $storageMode = 'rowless';
                $storageState = 'warning';
            } elseif ($storedAnswers === null && $rowCount > 0) {
                $storageMode = 'redacted';
                $storageState = 'warning';
            } elseif ($storedAnswers !== null) {
                $storageMode = 'full';
                $storageState = 'success';
            }
        }

        $notes = [];
        if ($scaleCode === 'CLINICAL_COMBO_68') {
            $notes[] = 'Clinical assessments default to rowless storage.';
        }
        if ($scaleCode === 'SDS_20') {
            $notes[] = 'SDS answer rows stay redacted by design.';
        }
        $notes[] = 'Raw answers stay hidden here. Only answer-set presence, hash, row count, and storage mode are shown.';

        return [
            'exists' => $answerSet !== null,
            'answers_hash' => $answersHash !== '' ? $answersHash : '-',
            'row_count' => $rowCount,
            'question_count' => $questionCount !== '' ? $questionCount : '-',
            'storage_mode' => $storageMode,
            'storage_state' => $storageState,
            'notes' => $notes,
        ];
    }

    /**
     * @return array{
     *   exists:bool,
     *   computed_at:string,
     *   type_code:string,
     *   validity_label:string,
     *   validity_state:string,
     *   profile_version:string,
     *   content_package_version:string,
     *   scoring_spec_version:string,
     *   report_engine_version:string,
     *   notes:list<string>
     * }
     */
    private function fetchResult(Attempt $attempt): array
    {
        $row = null;

        if (SchemaBaseline::hasTable('results')) {
            $row = DB::table('results')
                ->where('attempt_id', (string) $attempt->id)
                ->orderByRaw('coalesce(computed_at, updated_at, created_at) desc')
                ->first();
        }

        $resultJson = $this->decodeJson($row->result_json ?? null);
        $attemptResultJson = $this->decodeJson($attempt->result_json ?? null);
        $notes = [];

        if (! empty($attemptResultJson)) {
            $notes[] = 'Public attempt cache is present on attempts.result_json.';
        }

        if (! empty($resultJson)) {
            $notes[] = 'Private result row is present on results.result_json.';
        }

        if ($row === null) {
            return [
                'exists' => false,
                'computed_at' => '-',
                'type_code' => '-',
                'validity_label' => 'unknown',
                'validity_state' => 'gray',
                'profile_version' => '-',
                'content_package_version' => '-',
                'scoring_spec_version' => '-',
                'report_engine_version' => $this->stringOrDash($this->reportEngineVersion($attempt)),
                'notes' => $notes === [] ? ['No result row found for this attempt yet.'] : $notes,
            ];
        }

        $validity = is_null($row->is_valid ?? null)
            ? ['label' => 'unknown', 'state' => 'gray']
            : ((bool) $row->is_valid ? ['label' => 'valid', 'state' => 'success'] : ['label' => 'invalid', 'state' => 'danger']);

        return [
            'exists' => true,
            'computed_at' => $this->formatTimestamp($row->computed_at ?? null),
            'type_code' => $this->stringOrDash($row->type_code ?? null),
            'validity_label' => $validity['label'],
            'validity_state' => $validity['state'],
            'profile_version' => $this->stringOrDash($row->profile_version ?? null),
            'content_package_version' => $this->stringOrDash($row->content_package_version ?? null),
            'scoring_spec_version' => $this->stringOrDash($row->scoring_spec_version ?? null),
            'report_engine_version' => $this->stringOrDash($row->report_engine_version ?? null),
            'notes' => $notes === [] ? ['Result payload stays collapsed here.'] : $notes,
        ];
    }

    /**
     * @return array{
     *   exists:bool,
     *   status:string,
     *   status_state:string,
     *   locked:string,
     *   locked_state:string,
     *   access_level:string,
     *   variant:string,
     *   pack_id:string,
     *   dir_version:string,
     *   scoring_spec_version:string,
     *   report_engine_version:string,
     *   snapshot_version:string,
     *   notes:list<string>
     * }
     */
    private function fetchReportSnapshot(Attempt $attempt): array
    {
        $row = null;

        if (SchemaBaseline::hasTable('report_snapshots')) {
            $row = DB::table('report_snapshots')
                ->where('attempt_id', (string) $attempt->id)
                ->orderByRaw('coalesce(updated_at, created_at) desc')
                ->first();
        }

        if ($row === null) {
            return [
                'exists' => false,
                'status' => 'missing',
                'status_state' => 'gray',
                'locked' => '-',
                'locked_state' => 'gray',
                'access_level' => '-',
                'variant' => '-',
                'pack_id' => '-',
                'dir_version' => '-',
                'scoring_spec_version' => '-',
                'report_engine_version' => $this->stringOrDash($this->reportEngineVersion($attempt)),
                'snapshot_version' => '-',
                'notes' => ['No report snapshot row found for this attempt yet.'],
            ];
        }

        $reportJson = $this->decodeJson($row->report_json ?? null);
        $locked = $this->normalizeBooleanLabel(data_get($reportJson, 'locked'));
        $variant = $this->stringOrDash(data_get($reportJson, 'variant'));
        $accessLevel = $this->stringOrDash(data_get($reportJson, 'access_level'));
        $notes = [];

        if ($row->report_free_json !== null) {
            $notes[] = 'Free snapshot payload is present.';
        }

        if ($row->report_full_json !== null) {
            $notes[] = 'Full snapshot payload is present.';
        }

        $notes[] = 'Report payload JSON stays collapsed here.';

        return [
            'exists' => true,
            'status' => $this->stringOrDash($row->status ?? null),
            'status_state' => $this->statusState((string) ($row->status ?? '')),
            'locked' => $locked['label'],
            'locked_state' => $locked['state'],
            'access_level' => $accessLevel,
            'variant' => $variant,
            'pack_id' => $this->stringOrDash($row->pack_id ?? null),
            'dir_version' => $this->stringOrDash($row->dir_version ?? null),
            'scoring_spec_version' => $this->stringOrDash($row->scoring_spec_version ?? null),
            'report_engine_version' => $this->stringOrDash($row->report_engine_version ?? null),
            'snapshot_version' => $this->stringOrDash($row->snapshot_version ?? null),
            'notes' => $notes,
        ];
    }

    /**
     * @return array{
     *   order_no:string,
     *   order_status:string,
     *   order_status_state:string,
     *   payment_summary:string,
     *   payment_hint:?string,
     *   benefit_summary:string,
     *   benefit_hint:?string,
     *   entitlement:string,
     *   delivery:string,
     *   claim:string,
     *   share_id:string,
     *   notes:list<string>
     * }
     */
    private function fetchCommerceSummary(Attempt $attempt): array
    {
        $order = null;
        $orderNo = $this->latestOrderNo($attempt);

        if (SchemaBaseline::hasTable('orders')) {
            $order = DB::table('orders')
                ->where('target_attempt_id', (string) $attempt->id)
                ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc')
                ->first();

            if ($order !== null) {
                $orderNo = trim((string) ($order->order_no ?? $orderNo));
            }
        }

        $payment = null;
        if ($orderNo !== '' && SchemaBaseline::hasTable('payment_events')) {
            $payment = DB::table('payment_events')
                ->where('order_no', $orderNo)
                ->orderByRaw('coalesce(processed_at, updated_at, created_at) desc')
                ->first();
        }

        $benefit = null;
        if (SchemaBaseline::hasTable('benefit_grants')) {
            $benefit = DB::table('benefit_grants')
                ->where(function (QueryBuilder $builder) use ($attempt, $orderNo): void {
                    $builder->where('attempt_id', (string) $attempt->id);

                    if ($orderNo !== '') {
                        $builder->orWhere('order_no', $orderNo);
                    }
                })
                ->orderByRaw("case when lower(status) = 'active' then 0 else 1 end")
                ->orderByRaw('coalesce(updated_at, created_at) desc')
                ->first();
        }

        $benefitMeta = $this->decodeJson($benefit->meta_json ?? null);
        $orderMeta = $this->decodeJson($order->meta_json ?? null);
        $shareId = $this->latestShareId($attempt);

        $notes = [];
        if ($orderNo !== '') {
            $notes[] = 'Support can follow payment, unlock, and share state from the same attempt root.';
        } else {
            $notes[] = 'No linked order found for this attempt yet.';
        }

        return [
            'order_no' => $orderNo !== '' ? $orderNo : '-',
            'order_status' => $this->stringOrDash($order->status ?? null),
            'order_status_state' => $this->statusState((string) ($order->status ?? '')),
            'payment_summary' => $payment ? $this->stringOrDash($payment->status ?? null) : '-',
            'payment_hint' => $payment ? 'event='.$this->stringOrDash($payment->event_type ?? null) : null,
            'benefit_summary' => $benefit ? $this->stringOrDash($benefit->status ?? null) : '-',
            'benefit_hint' => $benefit ? 'benefit='.$this->stringOrDash($benefit->benefit_code ?? null) : null,
            'entitlement' => $this->stringOrDash(($order->entitlement_id ?? null) ?: ($payment->entitlement_id ?? null)),
            'delivery' => $this->formatTimestamp(($order->fulfilled_at ?? null) ?: ($attempt->paid_at ?? null)),
            'claim' => $this->stringOrDash(data_get($benefitMeta, 'claim_status') ?: data_get($orderMeta, 'claim_status')),
            'share_id' => $shareId !== '' ? $shareId : '-',
            'notes' => $notes,
        ];
    }

    /**
     * @return list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>
     */
    private function fetchEventTimeline(Attempt $attempt, string $shareId): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $rows = DB::table('events')
            ->select(['event_code', 'occurred_at', 'share_id', 'channel', 'meta_json'])
            ->where(function (QueryBuilder $query) use ($attempt, $shareId): void {
                $query->where('attempt_id', (string) $attempt->id);

                if ($shareId !== '' && $shareId !== '-') {
                    $query->orWhere('share_id', $shareId);
                }
            })
            ->orderByRaw('coalesce(occurred_at, created_at) desc')
            ->limit(20)
            ->get();

        return $rows
            ->map(function ($row): array {
                $meta = $this->decodeJson($row->meta_json ?? null);

                return [
                    'title' => $this->stringOrDash($row->event_code ?? null),
                    'occurred_at' => $this->formatTimestamp($row->occurred_at ?? null),
                    'meta' => $this->summarizeMeta($meta),
                    'share_id' => $this->stringOrDash($row->share_id ?? null),
                    'channel' => $this->stringOrDash($row->channel ?? null),
                ];
            })
            ->all();
    }

    /**
     * @return array{summary:string,hint:?string}
     */
    private function fetchReportJobSummary(Attempt $attempt): array
    {
        if (! SchemaBaseline::hasTable('report_jobs')) {
            return ['summary' => '-', 'hint' => null];
        }

        $rows = DB::table('report_jobs')
            ->select(['status', 'created_at', 'finished_at'])
            ->where('attempt_id', (string) $attempt->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return ['summary' => 'missing', 'hint' => null];
        }

        $latest = $rows->first();

        return [
            'summary' => sprintf('latest=%s total=%d', (string) ($latest->status ?? 'unknown'), $rows->count()),
            'hint' => 'finished='.$this->formatTimestamp($latest->finished_at ?? null),
        ];
    }

    /**
     * @return list<array{label:string,url:string,kind:string}>
     */
    private function buildLinks(Attempt $attempt, string $localeSegment, string $orderNo, string $shareId): array
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $links = [];

        if ($base !== '') {
            $links[] = [
                'label' => 'Result page',
                'url' => $base.'/'.$localeSegment.'/result/'.urlencode((string) $attempt->id),
                'kind' => 'frontend',
            ];

            $links[] = [
                'label' => 'Report page',
                'url' => $base.'/'.$localeSegment.'/attempts/'.urlencode((string) $attempt->id).'/report',
                'kind' => 'frontend',
            ];

            if ($orderNo !== '' && $orderNo !== '-') {
                $links[] = [
                    'label' => 'Order page',
                    'url' => $base.'/'.$localeSegment.'/orders/'.urlencode($orderNo),
                    'kind' => 'frontend',
                ];
            }

            if ($shareId !== '' && $shareId !== '-') {
                $links[] = [
                    'label' => 'Share page',
                    'url' => $base.'/'.$localeSegment.'/share/'.urlencode($shareId),
                    'kind' => 'frontend',
                ];
            }
        }

        $links[] = [
            'label' => 'Order Lookup',
            'url' => '/ops/order-lookup',
            'kind' => 'ops',
        ];

        return $links;
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
            'label' => $label,
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
            'label' => $label,
            'value' => $value !== '' ? $value : '-',
            'hint' => $hint,
            'kind' => 'pill',
            'state' => $state,
        ];
    }

    /**
     * @return list<string>
     */
    private function summarizeMeta(array $meta): array
    {
        $summary = [];

        foreach (['status', 'stage', 'variant', 'locked', 'access_level', 'question_id', 'reason', 'provider'] as $key) {
            $value = $meta[$key] ?? null;
            if (is_scalar($value) || is_bool($value)) {
                $summary[] = sprintf('%s=%s', $key, $this->scalarToString($value));
            }
        }

        return array_slice($summary, 0, 4);
    }

    private function stringOrDash(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : '-';
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : null;
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            if ($value instanceof Carbon) {
                return $value->toDateTimeString();
            }

            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return trim((string) $value) !== '' ? (string) $value : '-';
        }
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array{label:string,state:string}
     */
    private function normalizeBooleanLabel(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['label' => '-', 'state' => 'gray'];
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            return ['label' => (string) $value, 'state' => 'gray'];
        }

        return $bool
            ? ['label' => 'yes', 'state' => 'success']
            : ['label' => 'no', 'state' => 'gray'];
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function statusState(string $value): string
    {
        $status = strtolower(trim($value));

        return match (true) {
            $status === '' => 'gray',
            in_array($status, ['ready', 'completed', 'complete', 'active', 'paid', 'fulfilled', 'success', 'succeeded'], true) => 'success',
            in_array($status, ['pending', 'queued', 'running', 'processing'], true) => 'warning',
            str_contains($status, 'fail') || str_contains($status, 'error') || str_contains($status, 'refund') => 'danger',
            default => 'gray',
        };
    }

    private function isPaidLike(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['paid', 'fulfilled', 'complete', 'completed', 'success', 'succeeded'], true);
    }

    private function isRefundedLike(string $value): bool
    {
        return str_contains(strtolower(trim($value)), 'refund');
    }
}
