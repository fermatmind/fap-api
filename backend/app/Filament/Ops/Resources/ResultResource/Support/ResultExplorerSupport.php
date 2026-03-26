<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ResultResource\Support;

use App\Filament\Ops\Resources\AttemptResource;
use App\Filament\Ops\Resources\OrderResource;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\Result;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResultExplorerSupport
{
    /**
     * @return Builder<Result>
     */
    public function query(): Builder
    {
        $query = Result::query()
            ->withoutGlobalScopes()
            ->select('results.*')
            ->selectRaw('coalesce(results.computed_at, results.updated_at, results.created_at) as activity_at');

        if (SchemaBaseline::hasTable('attempts')) {
            $query
                ->selectRaw('case when exists (select 1 from attempts where attempts.id = results.attempt_id) then 1 else 0 end as attempt_exists')
                ->selectSub($this->latestAttemptField('locale'), 'attempt_locale')
                ->selectSub($this->latestAttemptField('region'), 'attempt_region')
                ->selectSub($this->latestAttemptField('norm_version'), 'attempt_norm_version');
        }

        if (SchemaBaseline::hasTable('report_snapshots')) {
            $query
                ->selectSub($this->latestSnapshotField('status'), 'latest_snapshot_status')
                ->selectSub($this->latestSnapshotField('order_no'), 'latest_snapshot_order_no')
                ->selectSub($this->latestSnapshotField('report_engine_version'), 'latest_snapshot_report_engine_version');
        }

        if (SchemaBaseline::hasTable('orders')) {
            $query
                ->selectSub($this->latestOrderField('id'), 'latest_order_id')
                ->selectSub($this->latestOrderField('order_no'), 'latest_order_no')
                ->selectSub($this->latestOrderField('status'), 'latest_order_status')
                ->selectSub($this->latestOrderField('paid_at'), 'latest_order_paid_at');
        }

        if (SchemaBaseline::hasTable('payment_events')) {
            $query->selectSub($this->latestPaymentField('status'), 'latest_payment_status');
        }

        if (SchemaBaseline::hasTable('benefit_grants')) {
            $query->selectSub($this->latestBenefitField('status'), 'latest_benefit_status');
        }

        if (SchemaBaseline::hasTable('shares')) {
            $query->selectSub($this->latestShareField('id'), 'latest_share_id');
        }

        return $query;
    }

    /**
     * @param  Builder<Result>  $query
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
                ->where('results.id', 'like', $like)
                ->orWhere('results.attempt_id', 'like', $like)
                ->orWhere('results.type_code', 'like', $like)
                ->orWhere('results.scale_code', 'like', $like);

            if (SchemaBaseline::hasTable('orders')) {
                $builder->orWhereExists(function (QueryBuilder $orderQuery) use ($like): void {
                    $orderQuery
                        ->selectRaw('1')
                        ->from('orders')
                        ->whereColumn('orders.target_attempt_id', 'results.attempt_id')
                        ->where('orders.order_no', 'like', $like);
                });
            }

            if (SchemaBaseline::hasTable('report_snapshots')) {
                $builder->orWhereExists(function (QueryBuilder $snapshotQuery) use ($like): void {
                    $snapshotQuery
                        ->selectRaw('1')
                        ->from('report_snapshots')
                        ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
                        ->where('report_snapshots.order_no', 'like', $like);
                });
            }

            if (SchemaBaseline::hasTable('shares')) {
                $builder->orWhereExists(function (QueryBuilder $shareQuery) use ($like): void {
                    $shareQuery
                        ->selectRaw('1')
                        ->from('shares')
                        ->whereColumn('shares.attempt_id', 'results.attempt_id')
                        ->where('shares.id', 'like', $like);
                });
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function distinctResultOptions(string $column): array
    {
        if (! SchemaBaseline::hasColumn('results', $column)) {
            return [];
        }

        return DB::table('results')
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
    public function distinctSnapshotStatusOptions(): array
    {
        $options = ['missing' => 'missing'];

        if (! SchemaBaseline::hasColumn('report_snapshots', 'status')) {
            return $options;
        }

        return $options + DB::table('report_snapshots')
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
     * @return array<string, string>
     */
    public function diagnosticOptions(): array
    {
        return [
            'linked' => 'linked',
            'orphan_result' => 'orphan_result',
            'report_missing' => 'report_missing',
            'report_pending' => 'report_pending',
            'report_failed' => 'report_failed',
        ];
    }

    /**
     * @param  Builder<Result>  $query
     */
    public function applyAttemptFieldFilter(Builder $query, string $column, ?string $value): void
    {
        $selected = trim((string) $value);
        if ($selected === '' || ! SchemaBaseline::hasColumn('attempts', $column)) {
            return;
        }

        $query->whereExists(function (QueryBuilder $attemptQuery) use ($column, $selected): void {
            $attemptQuery
                ->selectRaw('1')
                ->from('attempts')
                ->whereColumn('attempts.id', 'results.attempt_id')
                ->where('attempts.'.$column, $selected);
        });
    }

    /**
     * @param  Builder<Result>  $query
     */
    public function applySnapshotStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        if ($status === 'missing') {
            $query->whereNotExists($this->snapshotExistsQuery());

            return;
        }

        $query->whereExists(function (QueryBuilder $snapshotQuery) use ($status): void {
            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
                ->whereRaw('lower(coalesce(report_snapshots.status, \'\')) = ?', [$status]);
        });
    }

    /**
     * @param  Builder<Result>  $query
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
     * @param  Builder<Result>  $query
     */
    public function applyReportEngineVersionFilter(Builder $query, ?string $value): void
    {
        $version = trim((string) $value);
        if ($version === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($version): void {
            if (SchemaBaseline::hasColumn('results', 'report_engine_version')) {
                $builder->where('results.report_engine_version', $version);
            }

            if (SchemaBaseline::hasColumn('report_snapshots', 'report_engine_version')) {
                $builder->orWhereExists(function (QueryBuilder $snapshotQuery) use ($version): void {
                    $snapshotQuery
                        ->selectRaw('1')
                        ->from('report_snapshots')
                        ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
                        ->where('report_snapshots.report_engine_version', $version);
                });
            }
        });
    }

    /**
     * @param  Builder<Result>  $query
     */
    public function applyDiagnosticStatusFilter(Builder $query, ?string $value): void
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return;
        }

        match ($status) {
            'linked' => $query
                ->whereExists($this->attemptExistsQuery())
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereExists($this->snapshotReadyExistsQuery())
                        ->orWhere(function (Builder $nested): void {
                            $nested
                                ->whereNotExists($this->snapshotExistsQuery())
                                ->whereNotExists($this->orderExistsQuery());
                        });
                }),
            'orphan_result' => $query->whereNotExists($this->attemptExistsQuery()),
            'report_missing' => $query
                ->whereExists($this->attemptExistsQuery())
                ->whereNotExists($this->snapshotExistsQuery()),
            'report_pending' => $query
                ->whereExists($this->attemptExistsQuery())
                ->where(function (Builder $builder): void {
                    $builder->whereExists($this->snapshotPendingExistsQuery());

                    if (SchemaBaseline::hasTable('report_jobs')) {
                        $builder->orWhereExists($this->reportJobPendingExistsQuery());
                    }
                }),
            'report_failed' => $query
                ->whereExists($this->attemptExistsQuery())
                ->where(function (Builder $builder): void {
                    $builder->whereExists($this->snapshotFailedExistsQuery());

                    if (SchemaBaseline::hasTable('report_jobs')) {
                        $builder->orWhereExists($this->reportJobFailedExistsQuery());
                    }
                }),
            default => null,
        };
    }

    /**
     * @return array{label:string,state:string}
     */
    public function resultStatus(Result $result): array
    {
        if ($this->isOrphanResult($result)) {
            return ['label' => 'orphan_result', 'state' => 'danger'];
        }

        if ($result->is_valid === true) {
            return ['label' => 'valid', 'state' => 'success'];
        }

        if ($result->is_valid === false) {
            return ['label' => 'invalid', 'state' => 'danger'];
        }

        return ['label' => 'computed', 'state' => 'warning'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function snapshotStatus(Result $result): array
    {
        $status = strtolower(trim((string) ($result->latest_snapshot_status ?? '')));
        if ($status === '') {
            return ['label' => 'missing', 'state' => 'gray'];
        }

        return ['label' => $status, 'state' => StatusBadge::color($status)];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function unlockStatus(Result $result): array
    {
        $benefitStatus = strtolower(trim((string) ($result->latest_benefit_status ?? '')));
        $orderStatus = strtolower(trim((string) ($result->latest_order_status ?? '')));
        $paymentStatus = strtolower(trim((string) ($result->latest_payment_status ?? '')));

        if ($benefitStatus === 'active') {
            return ['label' => 'unlocked', 'state' => 'success'];
        }

        if ($this->isRefundedLike($orderStatus)) {
            return ['label' => 'refunded', 'state' => 'danger'];
        }

        if ($this->isPaidLike($orderStatus) || $this->isPaidLike($paymentStatus)) {
            return ['label' => 'paid_pending', 'state' => 'warning'];
        }

        if ($orderStatus !== '') {
            return ['label' => 'payment_pending', 'state' => 'gray'];
        }

        return ['label' => 'no_order', 'state' => 'gray'];
    }

    /**
     * @return array{label:string,state:string}
     */
    public function diagnosticStatus(Result $result): array
    {
        if ($this->isOrphanResult($result)) {
            return ['label' => 'orphan_result', 'state' => 'danger'];
        }

        $snapshotStatus = $this->snapshotStatus($result)['label'];
        if (in_array($snapshotStatus, ['failed', 'error'], true)) {
            return ['label' => 'report_failed', 'state' => 'danger'];
        }

        if (in_array($snapshotStatus, ['pending', 'queued', 'running'], true)) {
            return ['label' => 'report_pending', 'state' => 'warning'];
        }

        if ($snapshotStatus === 'missing' && $this->unlockStatus($result)['label'] !== 'no_order') {
            return ['label' => 'report_missing', 'state' => 'warning'];
        }

        return ['label' => 'linked', 'state' => 'success'];
    }

    public function attemptLocale(Result $result): string
    {
        return $this->stringOrDash($result->attempt_locale ?? null);
    }

    public function attemptRegion(Result $result): string
    {
        return $this->stringOrDash($result->attempt_region ?? null);
    }

    public function reportEngineVersion(Result $result): string
    {
        $resultVersion = trim((string) ($result->report_engine_version ?? ''));
        if ($resultVersion !== '') {
            return $resultVersion;
        }

        return $this->stringOrDash($result->latest_snapshot_report_engine_version ?? null);
    }

    public function scoreSummary(Result $result): string
    {
        $scores = $this->arrayFromMixed($result->scores_pct ?? null);
        if ($scores !== []) {
            return $this->summarizePairs($scores);
        }

        $fallback = $this->arrayFromMixed($result->scores_json ?? null);
        if ($fallback !== []) {
            return $this->summarizePairs($fallback);
        }

        return 'not_applicable';
    }

    public function axisSummary(Result $result): string
    {
        $axes = $this->arrayFromMixed($result->axis_states ?? null);
        if ($axes !== []) {
            return $this->summarizePairs($axes);
        }

        return 'not_applicable';
    }

    /**
     * @return array{
     *   headline: array<string, array{label:string,state:string}>,
     *   result_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   score_axis_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   version_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   report_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   attempt_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   commerce_summary: array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>},
     *   share_summary: array{
     *      fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,
     *      notes:list<string>,
     *      events:list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>
     *   },
     *   links:list<array{label:string,url:string,kind:string}>
     * }
     */
    public function buildDetail(Result $result): array
    {
        $attempt = $this->fetchAttempt($result);
        $snapshot = $this->fetchSnapshot($result);
        $commerce = $this->fetchCommerceSummary($result, $snapshot);
        $share = $this->fetchShareSummary($result, $commerce['share_id']);
        $reportJobs = $this->fetchReportJobSummary($result);
        $attemptSubmission = $this->fetchAttemptSubmissionSummary($result);
        $quality = $this->fetchAttemptQualitySummary($result);
        $orphanResult = $this->isOrphanResult($result);
        $headline = [
            'result' => $this->resultStatus($result),
            'diagnostic' => $this->diagnosticStatus($result),
            'snapshot' => $this->snapshotStatus($result),
            'unlock' => $this->unlockStatus($result),
        ];
        $localeSegment = $this->frontendLocaleSegment((string) ($attempt->locale ?? $result->attempt_locale ?? ''));

        $resultSummary = [
            'fields' => [
                $this->field('result_id', (string) $result->id),
                $this->field('attempt_id', $this->stringOrDash($result->attempt_id)),
                $this->field('org_id', $this->stringOrDash($result->org_id)),
                $this->field('scale_code', $this->stringOrDash($result->scale_code)),
                $this->field('type_code', $this->stringOrDash($result->type_code)),
                $this->field('computed_at', $this->formatTimestamp($result->computed_at)),
                $this->pillField('result_status', $headline['result']['label'], $headline['result']['state']),
                $this->pillField('orphan_result', $orphanResult ? 'yes' : 'no', $orphanResult ? 'danger' : 'success'),
            ],
            'notes' => [
                'Raw result payloads stay hidden here.',
            ],
        ];

        $scoreAxisSummary = [
            'fields' => [
                $this->field('score_summary', $this->scoreSummary($result)),
                $this->field('axis_summary', $this->axisSummary($result)),
                $this->field('dominant_outcome', $this->stringOrDash($result->type_code)),
                $this->field('scale_uid', $this->stringOrDash($result->scale_uid)),
                $this->field('scale_version', $this->stringOrDash($result->scale_version)),
            ],
            'notes' => [
                'Compact summaries only. Raw score and axis payloads stay collapsed.',
            ],
        ];

        $versionNotes = [];
        if ($attempt === null) {
            $versionNotes[] = 'Attempt-derived norm_version is unavailable because the attempt row is missing.';
        }

        $versionSummary = [
            'fields' => [
                $this->field('pack_id', $this->stringOrDash($result->pack_id)),
                $this->field('dir_version', $this->stringOrDash($result->dir_version)),
                $this->field('content_package_version', $this->stringOrDash($result->content_package_version)),
                $this->field('scoring_spec_version', $this->stringOrDash($result->scoring_spec_version)),
                $this->field('norm_version', $this->stringOrDash($attempt->norm_version ?? null)),
                $this->field('report_engine_version', $this->reportEngineVersion($result)),
                $this->field('profile_version', $this->stringOrDash($result->profile_version)),
                $this->pillField('diagnostic_status', $headline['diagnostic']['label'], $headline['diagnostic']['state']),
            ],
            'notes' => $versionNotes,
        ];

        $reportNotes = $snapshot['notes'];
        if ($orphanResult) {
            $reportNotes[] = 'Report drill-through is unavailable while the attempt row is missing.';
        }

        $reportSummary = [
            'fields' => [
                $this->pillField('snapshot_present', $snapshot['exists'] ? 'present' : 'missing', $snapshot['exists'] ? 'success' : 'gray'),
                $this->pillField('snapshot_status', $snapshot['status'], $snapshot['status_state']),
                $this->pillField('locked', $snapshot['locked'], $snapshot['locked_state']),
                $this->field('access_level', $snapshot['access_level']),
                $this->field('variant', $snapshot['variant']),
                $this->field('order_no', $snapshot['order_no']),
                $this->field('report_jobs', $reportJobs['summary'], $reportJobs['hint']),
                $this->field('pdf_hint', $commerce['pdf']),
            ],
            'notes' => $reportNotes,
        ];

        $attemptNotes = $attempt === null
            ? ['Attempt row is missing for this result. Result search stays available by result_id and attempt_id.']
            : [];

        if ($quality['hint'] !== null) {
            $attemptNotes[] = $quality['hint'];
        }

        $attemptSummary = [
            'fields' => [
                $this->pillField('attempt_linkage', $attempt === null ? 'missing' : 'linked', $attempt === null ? 'danger' : 'success'),
                $this->pillField('submitted_status', $attemptSubmission['submitted_status'], $attemptSubmission['submitted_state'], $attemptSubmission['summary']),
                $this->field('locale', $this->stringOrDash($attempt->locale ?? null)),
                $this->field('region', $this->stringOrDash($attempt->region ?? null)),
                $this->field('user_id', $this->stringOrDash($attempt->user_id ?? null)),
                $this->field('anon_id', $this->stringOrDash($attempt->anon_id ?? null)),
                $this->field('ticket_code', $this->stringOrDash($attempt->ticket_code ?? null)),
                $this->field('quality', $quality['summary']),
            ],
            'notes' => $attemptNotes,
        ];

        $commerceSummary = [
            'fields' => [
                $this->field('order_no', $commerce['order_no']),
                $this->pillField('order_status', $commerce['order_status'], $commerce['order_status_state']),
                $this->field('payment_summary', $commerce['payment_summary'], $commerce['payment_hint']),
                $this->field('active_benefit_grant', $commerce['benefit_summary'], $commerce['benefit_hint']),
                $this->pillField('unlock_status', $headline['unlock']['label'], $headline['unlock']['state']),
                $this->field('delivery', $commerce['delivery']),
                $this->field('pdf', $commerce['pdf']),
                $this->field('claim', $commerce['claim']),
            ],
            'notes' => $commerce['notes'],
        ];

        $shareSummary = [
            'fields' => [
                $this->field('share_id', $share['share_id']),
                $this->field('share_created_at', $share['created_at']),
                $this->field('latest_event', $share['latest_event']),
                $this->field('recent_events', (string) count($share['events'])),
                $this->field('engagement_channel', $share['latest_channel']),
            ],
            'notes' => $share['notes'],
            'events' => $share['events'],
        ];

        return [
            'headline' => $headline,
            'result_summary' => $resultSummary,
            'score_axis_summary' => $scoreAxisSummary,
            'version_summary' => $versionSummary,
            'report_summary' => $reportSummary,
            'attempt_summary' => $attemptSummary,
            'commerce_summary' => $commerceSummary,
            'share_summary' => $shareSummary,
            'links' => $this->buildLinks($result, $attempt, $localeSegment, $commerce['order_id'], $share['share_id']),
        ];
    }

    private function latestAttemptField(string $column): QueryBuilder
    {
        return DB::table('attempts')
            ->select($column)
            ->whereColumn('attempts.id', 'results.attempt_id')
            ->limit(1);
    }

    private function latestSnapshotField(string $column): QueryBuilder
    {
        return DB::table('report_snapshots')
            ->select($column)
            ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestOrderField(string $column): QueryBuilder
    {
        return DB::table('orders')
            ->select($column)
            ->whereColumn('orders.target_attempt_id', 'results.attempt_id')
            ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestPaymentField(string $column): QueryBuilder
    {
        return DB::table('payment_events')
            ->select($column)
            ->whereExists(function (QueryBuilder $orderQuery): void {
                $orderQuery
                    ->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.order_no', 'payment_events.order_no')
                    ->whereColumn('orders.target_attempt_id', 'results.attempt_id');
            })
            ->orderByRaw('coalesce(processed_at, updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestBenefitField(string $column): QueryBuilder
    {
        return DB::table('benefit_grants')
            ->select($column)
            ->where(function (QueryBuilder $benefitQuery): void {
                $benefitQuery
                    ->whereColumn('benefit_grants.attempt_id', 'results.attempt_id')
                    ->orWhereExists(function (QueryBuilder $orderQuery): void {
                        $orderQuery
                            ->selectRaw('1')
                            ->from('orders')
                            ->whereColumn('orders.order_no', 'benefit_grants.order_no')
                            ->whereColumn('orders.target_attempt_id', 'results.attempt_id');
                    });
            })
            ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->limit(1);
    }

    private function latestShareField(string $column): QueryBuilder
    {
        return DB::table('shares')
            ->select($column)
            ->whereColumn('shares.attempt_id', 'results.attempt_id')
            ->orderByDesc('created_at')
            ->limit(1);
    }

    private function attemptExistsQuery(): \Closure
    {
        return function (QueryBuilder $attemptQuery): void {
            if (! SchemaBaseline::hasTable('attempts')) {
                $attemptQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $attemptQuery
                ->selectRaw('1')
                ->from('attempts')
                ->whereColumn('attempts.id', 'results.attempt_id');
        };
    }

    private function snapshotExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            if (! SchemaBaseline::hasTable('report_snapshots')) {
                $snapshotQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id');
        };
    }

    private function snapshotReadyExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            if (! SchemaBaseline::hasTable('report_snapshots')) {
                $snapshotQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
                ->whereRaw("lower(coalesce(report_snapshots.status, '')) in (?, ?, ?)", ['ready', 'full', 'completed']);
        };
    }

    private function snapshotPendingExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            if (! SchemaBaseline::hasTable('report_snapshots')) {
                $snapshotQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
                ->whereRaw("lower(coalesce(report_snapshots.status, '')) in (?, ?, ?)", ['pending', 'queued', 'running']);
        };
    }

    private function snapshotFailedExistsQuery(): \Closure
    {
        return function (QueryBuilder $snapshotQuery): void {
            if (! SchemaBaseline::hasTable('report_snapshots')) {
                $snapshotQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $snapshotQuery
                ->selectRaw('1')
                ->from('report_snapshots')
                ->whereColumn('report_snapshots.attempt_id', 'results.attempt_id')
                ->whereRaw("lower(coalesce(report_snapshots.status, '')) in (?, ?)", ['failed', 'error']);
        };
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
                ->whereColumn('orders.target_attempt_id', 'results.attempt_id');
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
                ->whereColumn('orders.target_attempt_id', 'results.attempt_id')
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
                ->whereColumn('orders.target_attempt_id', 'results.attempt_id')
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
                ->whereRaw("lower(coalesce(benefit_grants.status, '')) = 'active'")
                ->where(function (QueryBuilder $builder): void {
                    $builder
                        ->whereColumn('benefit_grants.attempt_id', 'results.attempt_id')
                        ->orWhereExists(function (QueryBuilder $orderQuery): void {
                            $orderQuery
                                ->selectRaw('1')
                                ->from('orders')
                                ->whereColumn('orders.order_no', 'benefit_grants.order_no')
                                ->whereColumn('orders.target_attempt_id', 'results.attempt_id');
                        });
                });
        };
    }

    private function reportJobPendingExistsQuery(): \Closure
    {
        return function (QueryBuilder $jobQuery): void {
            if (! SchemaBaseline::hasTable('report_jobs')) {
                $jobQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $jobQuery
                ->selectRaw('1')
                ->from('report_jobs')
                ->whereColumn('report_jobs.attempt_id', 'results.attempt_id')
                ->whereRaw("lower(coalesce(report_jobs.status, '')) in (?, ?, ?)", ['pending', 'queued', 'running']);
        };
    }

    private function reportJobFailedExistsQuery(): \Closure
    {
        return function (QueryBuilder $jobQuery): void {
            if (! SchemaBaseline::hasTable('report_jobs')) {
                $jobQuery->selectRaw('1')->fromRaw('(select 1) as noop')->whereRaw('1 = 0');

                return;
            }

            $jobQuery
                ->selectRaw('1')
                ->from('report_jobs')
                ->whereColumn('report_jobs.attempt_id', 'results.attempt_id')
                ->whereRaw("lower(coalesce(report_jobs.status, '')) in (?, ?)", ['failed', 'error']);
        };
    }

    private function fetchAttempt(Result $result): ?object
    {
        if (! SchemaBaseline::hasTable('attempts')) {
            return null;
        }

        return DB::table('attempts')
            ->where('id', (string) $result->attempt_id)
            ->first();
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
     *   order_no:string,
     *   notes:list<string>
     * }
     */
    private function fetchSnapshot(Result $result): array
    {
        if (! SchemaBaseline::hasTable('report_snapshots')) {
            return [
                'exists' => false,
                'status' => 'missing',
                'status_state' => 'gray',
                'locked' => '-',
                'locked_state' => 'gray',
                'access_level' => '-',
                'variant' => '-',
                'order_no' => '-',
                'notes' => ['No report snapshot row found for this result yet.'],
            ];
        }

        $row = DB::table('report_snapshots')
            ->where('attempt_id', (string) $result->attempt_id)
            ->orderByRaw('coalesce(updated_at, created_at) desc')
            ->first();

        if ($row === null) {
            return [
                'exists' => false,
                'status' => 'missing',
                'status_state' => 'gray',
                'locked' => '-',
                'locked_state' => 'gray',
                'access_level' => '-',
                'variant' => '-',
                'order_no' => '-',
                'notes' => ['No report snapshot row found for this result yet.'],
            ];
        }

        $reportJson = SchemaBaseline::hasColumn('report_snapshots', 'report_json')
            ? $this->arrayFromMixed($row->report_json ?? null)
            : [];
        $locked = $this->normalizeBooleanLabel($reportJson['locked'] ?? null);
        $notes = ['Report payload JSON stays collapsed here.'];

        if (SchemaBaseline::hasColumn('report_snapshots', 'report_free_json') && ($row->report_free_json ?? null) !== null) {
            $notes[] = 'Free report snapshot is present.';
        }
        if (SchemaBaseline::hasColumn('report_snapshots', 'report_full_json') && ($row->report_full_json ?? null) !== null) {
            $notes[] = 'Full report snapshot is present.';
        }

        return [
            'exists' => true,
            'status' => $this->stringOrDash($row->status ?? null),
            'status_state' => StatusBadge::color($row->status ?? null),
            'locked' => $locked['label'],
            'locked_state' => $locked['state'],
            'access_level' => $this->stringOrDash($reportJson['access_level'] ?? null),
            'variant' => $this->stringOrDash($reportJson['variant'] ?? null),
            'order_no' => $this->stringOrDash($row->order_no ?? null),
            'notes' => $notes,
        ];
    }

    /**
     * @param  array{order_no:string}  $snapshot
     * @return array{
     *   order_id:?string,
     *   order_no:string,
     *   order_status:string,
     *   order_status_state:string,
     *   payment_summary:string,
     *   payment_hint:?string,
     *   benefit_summary:string,
     *   benefit_hint:?string,
     *   delivery:string,
     *   pdf:string,
     *   claim:string,
     *   share_id:string,
     *   notes:list<string>
     * }
     */
    private function fetchCommerceSummary(Result $result, array $snapshot): array
    {
        $order = null;
        if (SchemaBaseline::hasTable('orders')) {
            $order = DB::table('orders')
                ->where('target_attempt_id', (string) $result->attempt_id)
                ->orderByRaw('coalesce(paid_at, updated_at, created_at) desc')
                ->first();
        }

        $orderNo = trim((string) (($order->order_no ?? null) ?: ($snapshot['order_no'] ?? '')));
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
                ->where(function (QueryBuilder $builder) use ($result, $orderNo): void {
                    $builder->where('attempt_id', (string) $result->attempt_id);

                    if ($orderNo !== '') {
                        $builder->orWhere('order_no', $orderNo);
                    }
                })
                ->orderByRaw("case when lower(coalesce(status, '')) = 'active' then 0 else 1 end")
                ->orderByRaw('coalesce(updated_at, created_at) desc')
                ->first();
        }

        $orderMeta = SchemaBaseline::hasColumn('orders', 'meta_json') ? $this->arrayFromMixed($order->meta_json ?? null) : [];
        $benefitMeta = SchemaBaseline::hasColumn('benefit_grants', 'meta_json') ? $this->arrayFromMixed($benefit->meta_json ?? null) : [];
        $shareId = trim((string) ($result->latest_share_id ?? ''));
        $unlock = $this->unlockStatus($result);
        $snapshotStatus = $this->snapshotStatus($result)['label'];

        $notes = ['Unlock status is derived from active benefit_grants first, not from order status alone.'];
        if ($orderNo === '') {
            $notes[] = 'No linked order row was found for this result yet.';
        }

        return [
            'order_id' => ($order->id ?? null) !== null ? (string) $order->id : null,
            'order_no' => $orderNo !== '' ? $orderNo : '-',
            'order_status' => $this->stringOrDash($order->status ?? null),
            'order_status_state' => StatusBadge::color($order->status ?? null),
            'payment_summary' => $this->stringOrDash($payment->status ?? null),
            'payment_hint' => $payment !== null ? 'event='.$this->stringOrDash($payment->event_type ?? null) : null,
            'benefit_summary' => $this->stringOrDash($benefit->status ?? null),
            'benefit_hint' => $benefit !== null ? 'benefit='.$this->stringOrDash($benefit->benefit_code ?? null) : null,
            'delivery' => $this->formatTimestamp(($order->fulfilled_at ?? null) ?: ($order->paid_at ?? null)),
            'pdf' => $snapshotStatus === 'ready' && $unlock['label'] === 'unlocked' ? 'ready' : 'unavailable',
            'claim' => $this->stringOrDash($benefitMeta['claim_status'] ?? $orderMeta['claim_status'] ?? null),
            'share_id' => $shareId !== '' ? $shareId : '-',
            'notes' => $notes,
        ];
    }

    /**
     * @return array{
     *   share_id:string,
     *   created_at:string,
     *   latest_event:string,
     *   latest_channel:string,
     *   notes:list<string>,
     *   events:list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>
     * }
     */
    private function fetchShareSummary(Result $result, string $fallbackShareId): array
    {
        $shareId = trim((string) $fallbackShareId);
        $createdAt = '-';
        $notes = ['Share and engagement traces stay summarized only. Raw event payloads stay hidden.'];

        if (SchemaBaseline::hasTable('shares')) {
            $share = DB::table('shares')
                ->where('attempt_id', (string) $result->attempt_id)
                ->orderByDesc('created_at')
                ->first();

            if ($share !== null) {
                $shareId = trim((string) (($share->id ?? null) ?: $shareId));
                $createdAt = $this->formatTimestamp($share->created_at ?? null);
            }
        }

        if ($shareId === '') {
            $notes[] = 'No share row found for this result yet.';
        }

        $events = $this->fetchRecentEvents((string) $result->attempt_id, $shareId);
        $latestEvent = $events[0]['title'] ?? '-';
        $latestChannel = $events[0]['channel'] ?? '-';

        return [
            'share_id' => $shareId !== '' ? $shareId : '-',
            'created_at' => $createdAt,
            'latest_event' => $latestEvent,
            'latest_channel' => $latestChannel,
            'notes' => $notes,
            'events' => $events,
        ];
    }

    /**
     * @return list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>
     */
    private function fetchRecentEvents(string $attemptId, string $shareId): array
    {
        if (! SchemaBaseline::hasTable('events')) {
            return [];
        }

        $rows = DB::table('events')
            ->select(['event_code', 'occurred_at', 'share_id', 'channel', 'meta_json'])
            ->where(function (QueryBuilder $query) use ($attemptId, $shareId): void {
                $query->where('attempt_id', $attemptId);

                if ($shareId !== '' && $shareId !== '-') {
                    $query->orWhere('share_id', $shareId);
                }
            })
            ->orderByRaw('coalesce(occurred_at, created_at) desc')
            ->limit(8)
            ->get();

        return $rows->map(function ($row): array {
            return [
                'title' => $this->stringOrDash($row->event_code ?? null),
                'occurred_at' => $this->formatTimestamp($row->occurred_at ?? null),
                'meta' => $this->summarizeMeta($this->arrayFromMixed($row->meta_json ?? null)),
                'share_id' => $this->stringOrDash($row->share_id ?? null),
                'channel' => $this->stringOrDash($row->channel ?? null),
            ];
        })->all();
    }

    /**
     * @return array{summary:string,hint:?string}
     */
    private function fetchReportJobSummary(Result $result): array
    {
        if (! SchemaBaseline::hasTable('report_jobs')) {
            return ['summary' => '-', 'hint' => null];
        }

        $rows = DB::table('report_jobs')
            ->select(['status', 'created_at', 'finished_at'])
            ->where('attempt_id', (string) $result->attempt_id)
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
     * @return array{summary:string,submitted_status:string,submitted_state:string}
     */
    private function fetchAttemptSubmissionSummary(Result $result): array
    {
        if (! SchemaBaseline::hasTable('attempt_submissions')) {
            return [
                'summary' => 'missing',
                'submitted_status' => 'unknown',
                'submitted_state' => 'gray',
            ];
        }

        $row = DB::table('attempt_submissions')
            ->select(['state', 'mode', 'finished_at', 'error_code'])
            ->where('attempt_id', (string) $result->attempt_id)
            ->orderByRaw('coalesce(finished_at, updated_at, created_at) desc')
            ->first();

        if ($row === null) {
            return [
                'summary' => 'missing',
                'submitted_status' => 'unknown',
                'submitted_state' => 'gray',
            ];
        }

        $state = strtolower(trim((string) ($row->state ?? 'unknown')));
        $parts = [
            'mode='.$this->stringOrDash($row->mode ?? null),
            'finished='.$this->formatTimestamp($row->finished_at ?? null),
        ];

        if (filled($row->error_code ?? null)) {
            $parts[] = 'error='.$this->stringOrDash($row->error_code ?? null);
        }

        return [
            'summary' => implode(' | ', $parts),
            'submitted_status' => $state !== '' ? $state : 'unknown',
            'submitted_state' => StatusBadge::color($state),
        ];
    }

    /**
     * @return array{summary:string,hint:?string}
     */
    private function fetchAttemptQualitySummary(Result $result): array
    {
        $payload = $this->arrayFromMixed($result->result_json);
        $candidates = [
            $payload['quality'] ?? null,
            data_get($payload, 'normed_json.quality'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $summary = trim((string) ($candidate['grade'] ?? $candidate['level'] ?? ''));
            if ($summary !== '') {
                return ['summary' => $summary, 'hint' => null];
            }
        }

        return ['summary' => '-', 'hint' => null];
    }

    /**
     * @return list<array{label:string,url:string,kind:string}>
     */
    private function buildLinks(Result $result, ?object $attempt, string $localeSegment, ?string $orderId, string $shareId): array
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        $links = [];

        if ($base !== '') {
            $attemptId = trim((string) $result->attempt_id);

            if ($attemptId !== '') {
                $links[] = [
                    'label' => 'Result page',
                    'url' => $base.'/'.$localeSegment.'/result/'.urlencode($attemptId),
                    'kind' => 'frontend',
                ];
            }

            if ($attempt !== null && $attemptId !== '') {
                $links[] = [
                    'label' => 'Report page',
                    'url' => $base.'/'.$localeSegment.'/attempts/'.urlencode($attemptId).'/report',
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

        if ($attempt !== null) {
            $links[] = [
                'label' => 'Attempt Explorer',
                'url' => AttemptResource::getUrl('view', ['record' => (string) $result->attempt_id]),
                'kind' => 'ops',
            ];
        }

        if ($orderId !== null && $orderId !== '') {
            $links[] = [
                'label' => 'Order diagnostics',
                'url' => OrderResource::getUrl('view', ['record' => $orderId]),
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

    private function isOrphanResult(Result $result): bool
    {
        return (int) ($result->attempt_exists ?? 0) !== 1;
    }

    private function isPaidLike(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['paid', 'fulfilled', 'complete', 'completed'], true);
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

    /**
     * @param  array<string, mixed>  $pairs
     */
    private function summarizePairs(array $pairs, int $limit = 4): string
    {
        $summary = [];

        foreach ($pairs as $key => $value) {
            if (count($summary) >= $limit) {
                break;
            }

            $summary[] = sprintf('%s=%s', (string) $key, $this->scalarSummary($value));
        }

        return $summary === [] ? 'not_applicable' : implode(' | ', $summary);
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

    /**
     * @return array{label:string,state:string}
     */
    private function normalizeBooleanLabel(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['label' => '-', 'state' => 'gray'];
        }

        $truthy = StatusBadge::isTruthy($value);

        return [
            'label' => $truthy ? 'yes' : 'no',
            'state' => $truthy ? 'warning' : 'success',
        ];
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
}
