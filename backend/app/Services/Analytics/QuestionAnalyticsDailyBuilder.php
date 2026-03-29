<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class QuestionAnalyticsDailyBuilder
{
    public function __construct(
        private readonly QuestionAnalyticsSupport $support,
    ) {}

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     * @return array{
     *     option_rows:list<array<string,mixed>>,
     *     progress_rows:list<array<string,mixed>>,
     *     attempted_option_rows:int,
     *     attempted_progress_rows:int,
     *     source_answer_rows:int,
     *     source_attempts:int,
     *     authoritative_scales:list<string>,
     *     requested_scales:list<string>,
     *     ignored_requested_scales:list<string>,
     *     org_scope:list<int>,
     *     locale_scope:list<string>,
     *     from:string,
     *     to:string
     * }
     */
    public function build(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $scaleCodes = [],
        array $locales = [],
    ): array {
        $fromAt = CarbonImmutable::parse($from)->startOfDay();
        $toAt = CarbonImmutable::parse($to)->endOfDay();
        $normalizedOrgIds = $this->normalizeOrgIds($orgIds);
        $normalizedScaleCodes = $this->normalizeScaleCodes($scaleCodes);
        $normalizedLocales = $this->normalizeLocales($locales);
        $scaleConfigs = $this->support->authoritativeScaleConfigs($normalizedScaleCodes);
        $effectiveScaleCodes = array_keys($scaleConfigs);
        $ignoredRequestedScales = array_values(array_diff($normalizedScaleCodes, $effectiveScaleCodes));

        if (
            ! SchemaBaseline::hasTable('attempts')
            || ! SchemaBaseline::hasTable('attempt_answer_rows')
        ) {
            return [
                'option_rows' => [],
                'progress_rows' => [],
                'attempted_option_rows' => 0,
                'attempted_progress_rows' => 0,
                'source_answer_rows' => 0,
                'source_attempts' => 0,
                'authoritative_scales' => $effectiveScaleCodes,
                'requested_scales' => $normalizedScaleCodes,
                'ignored_requested_scales' => $ignoredRequestedScales,
                'org_scope' => $normalizedOrgIds,
                'locale_scope' => $normalizedLocales,
                'from' => $fromAt->toDateString(),
                'to' => $toAt->toDateString(),
            ];
        }

        if ($effectiveScaleCodes === []) {
            return [
                'option_rows' => [],
                'progress_rows' => [],
                'attempted_option_rows' => 0,
                'attempted_progress_rows' => 0,
                'source_answer_rows' => 0,
                'source_attempts' => 0,
                'authoritative_scales' => [],
                'requested_scales' => $normalizedScaleCodes,
                'ignored_requested_scales' => $ignoredRequestedScales,
                'org_scope' => $normalizedOrgIds,
                'locale_scope' => $normalizedLocales,
                'from' => $fromAt->toDateString(),
                'to' => $toAt->toDateString(),
            ];
        }

        $optionPayload = $this->buildOptionRows($fromAt, $toAt, $normalizedOrgIds, $normalizedLocales, $scaleConfigs);
        $progressPayload = $this->buildProgressRows($fromAt, $toAt, $normalizedOrgIds, $normalizedLocales, $scaleConfigs);

        return [
            'option_rows' => $optionPayload['rows'],
            'progress_rows' => $progressPayload['rows'],
            'attempted_option_rows' => count($optionPayload['rows']),
            'attempted_progress_rows' => count($progressPayload['rows']),
            'source_answer_rows' => $optionPayload['source_answer_rows'],
            'source_attempts' => $progressPayload['source_attempts'],
            'authoritative_scales' => $effectiveScaleCodes,
            'requested_scales' => $normalizedScaleCodes,
            'ignored_requested_scales' => $ignoredRequestedScales,
            'org_scope' => $normalizedOrgIds,
            'locale_scope' => $normalizedLocales,
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $scaleCodes
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    public function refresh(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        array $orgIds = [],
        array $scaleCodes = [],
        array $locales = [],
        bool $dryRun = false,
    ): array {
        $payload = $this->build($from, $to, $orgIds, $scaleCodes, $locales);
        $deletedOptionRows = 0;
        $deletedProgressRows = 0;
        $upsertedOptionRows = 0;
        $upsertedProgressRows = 0;

        if (! $dryRun && SchemaBaseline::hasTable('analytics_question_option_daily') && SchemaBaseline::hasTable('analytics_question_progress_daily')) {
            DB::transaction(function () use (
                $payload,
                &$deletedOptionRows,
                &$deletedProgressRows,
                &$upsertedOptionRows,
                &$upsertedProgressRows
            ): void {
                $deletedOptionRows = $this->deleteScope(
                    'analytics_question_option_daily',
                    $payload['from'],
                    $payload['to'],
                    $payload['org_scope'],
                    $payload['locale_scope'],
                    $payload['authoritative_scales']
                );
                $deletedProgressRows = $this->deleteScope(
                    'analytics_question_progress_daily',
                    $payload['from'],
                    $payload['to'],
                    $payload['org_scope'],
                    $payload['locale_scope'],
                    $payload['authoritative_scales']
                );

                if ($payload['option_rows'] !== []) {
                    DB::table('analytics_question_option_daily')->upsert(
                        $payload['option_rows'],
                        [
                            'day',
                            'org_id',
                            'locale',
                            'region',
                            'scale_code',
                            'content_package_version',
                            'scoring_spec_version',
                            'norm_version',
                            'question_id',
                            'option_key',
                        ],
                        [
                            'question_order',
                            'answered_rows_count',
                            'distinct_attempts_answered',
                            'last_refreshed_at',
                            'updated_at',
                        ]
                    );
                    $upsertedOptionRows = count($payload['option_rows']);
                }

                if ($payload['progress_rows'] !== []) {
                    DB::table('analytics_question_progress_daily')->upsert(
                        $payload['progress_rows'],
                        [
                            'day',
                            'org_id',
                            'locale',
                            'region',
                            'scale_code',
                            'content_package_version',
                            'scoring_spec_version',
                            'norm_version',
                            'question_id',
                        ],
                        [
                            'question_order',
                            'reached_attempts_count',
                            'answered_attempts_count',
                            'completed_attempts_count',
                            'dropoff_attempts_count',
                            'last_refreshed_at',
                            'updated_at',
                        ]
                    );
                    $upsertedProgressRows = count($payload['progress_rows']);
                }
            });
        }

        return $payload + [
            'deleted_option_rows' => $deletedOptionRows,
            'deleted_progress_rows' => $deletedProgressRows,
            'upserted_option_rows' => $upsertedOptionRows,
            'upserted_progress_rows' => $upsertedProgressRows,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     * @param  array<string,array<string,mixed>>  $scaleConfigs
     * @return array{rows:list<array<string,mixed>>,source_answer_rows:int}
     */
    private function buildOptionRows(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $locales,
        array $scaleConfigs,
    ): array {
        $aggregates = [];
        $attemptIdsByKey = [];
        $sourceAnswerRows = 0;
        $now = now();

        foreach ($this->authoritativeAnswerRowCursor($fromAt, $toAt, $orgIds, $locales) as $row) {
            $scaleCode = $this->support->resolveAuthoritativeScaleCode(
                (string) ($row->attempt_scale_code ?? $row->row_scale_code ?? ''),
                (string) ($row->attempt_scale_code_v2 ?? $row->row_scale_code_v2 ?? ''),
                (string) ($row->attempt_scale_uid ?? $row->row_scale_uid ?? '')
            );

            if ($scaleCode === null || ! isset($scaleConfigs[$scaleCode])) {
                continue;
            }

            $questionId = trim((string) ($row->question_id ?? ''));
            if ($questionId === '') {
                continue;
            }

            $questionDefinition = $scaleConfigs[$scaleCode]['question_definition'] ?? [];
            $questionOrder = (int) ($questionDefinition['order_by_question_id'][$questionId] ?? 0);
            if ($questionOrder <= 0) {
                continue;
            }

            $optionKey = $this->extractOptionKey($row->answer_json ?? null);
            if ($optionKey === null || $optionKey === '') {
                continue;
            }

            $sourceAnswerRows++;
            $day = $this->resolveDateString(
                $row->row_submitted_at ?? null,
                $row->attempt_submitted_at ?? null,
                $row->attempt_created_at ?? null
            );
            $dimensions = $this->dimensionPayload($row, $day, $scaleCode);
            $aggregateKey = $this->optionAggregateKey($dimensions, $questionId, $questionOrder, $optionKey);

            if (! isset($aggregates[$aggregateKey])) {
                $aggregates[$aggregateKey] = [
                    'day' => $dimensions['day'],
                    'org_id' => $dimensions['org_id'],
                    'locale' => $dimensions['locale'],
                    'region' => $dimensions['region'],
                    'scale_code' => $dimensions['scale_code'],
                    'content_package_version' => $dimensions['content_package_version'],
                    'scoring_spec_version' => $dimensions['scoring_spec_version'],
                    'norm_version' => $dimensions['norm_version'],
                    'question_id' => $questionId,
                    'question_order' => $questionOrder,
                    'option_key' => $optionKey,
                    'answered_rows_count' => 0,
                    'distinct_attempts_answered' => 0,
                    'last_refreshed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $attemptIdsByKey[$aggregateKey] = [];
            }

            $aggregates[$aggregateKey]['answered_rows_count']++;
            $attemptId = trim((string) ($row->attempt_id ?? ''));
            if ($attemptId !== '') {
                $attemptIdsByKey[$aggregateKey][$attemptId] = true;
            }
        }

        foreach ($aggregates as $aggregateKey => &$payload) {
            $payload['distinct_attempts_answered'] = count($attemptIdsByKey[$aggregateKey] ?? []);
        }
        unset($payload);

        return [
            'rows' => array_values($aggregates),
            'source_answer_rows' => $sourceAnswerRows,
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     * @param  array<string,array<string,mixed>>  $scaleConfigs
     * @return array{rows:list<array<string,mixed>>,source_attempts:int}
     */
    private function buildProgressRows(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $locales,
        array $scaleConfigs,
    ): array {
        if (! SchemaBaseline::hasTable('attempts')) {
            return ['rows' => [], 'source_attempts' => 0];
        }

        $attempts = $this->candidateAttemptCollection($fromAt, $toAt, $orgIds, $locales);
        if ($attempts->isEmpty()) {
            return ['rows' => [], 'source_attempts' => 0];
        }

        $candidateAttempts = $attempts
            ->map(function (object $row) use ($scaleConfigs): ?object {
                $scaleCode = $this->support->resolveAuthoritativeScaleCode(
                    (string) ($row->attempt_scale_code ?? ''),
                    (string) ($row->attempt_scale_code_v2 ?? ''),
                    (string) ($row->attempt_scale_uid ?? '')
                );

                if ($scaleCode === null || ! isset($scaleConfigs[$scaleCode])) {
                    return null;
                }

                $row->authoritative_scale_code = $scaleCode;

                return $row;
            })
            ->filter()
            ->values();

        if ($candidateAttempts->isEmpty()) {
            return ['rows' => [], 'source_attempts' => 0];
        }

        $rowsByAttemptId = $this->loadAnswerRowsByAttemptId(
            $candidateAttempts->pluck('attempt_id')->map(static fn (mixed $value): string => (string) $value)->all()
        );

        $aggregates = [];
        $now = now();

        foreach ($candidateAttempts as $attempt) {
            $scaleCode = (string) ($attempt->authoritative_scale_code ?? '');
            $questionDefinition = $scaleConfigs[$scaleCode]['question_definition'] ?? null;
            if (! is_array($questionDefinition)) {
                continue;
            }

            $questionOrders = is_array($questionDefinition['order_by_question_id'] ?? null)
                ? $questionDefinition['order_by_question_id']
                : [];
            $questionIdsByOrder = is_array($questionDefinition['question_ids_by_order'] ?? null)
                ? $questionDefinition['question_ids_by_order']
                : [];
            $totalQuestions = max(0, (int) ($questionDefinition['total_questions'] ?? count($questionIdsByOrder)));
            if ($totalQuestions <= 0 || $questionOrders === [] || $questionIdsByOrder === []) {
                continue;
            }

            $attemptId = trim((string) ($attempt->attempt_id ?? ''));
            if ($attemptId === '') {
                continue;
            }

            $answeredOrderMap = [];
            foreach ($rowsByAttemptId[$attemptId] ?? [] as $questionId) {
                $questionOrder = (int) ($questionOrders[(string) $questionId] ?? 0);
                if ($questionOrder > 0) {
                    $answeredOrderMap[$questionOrder] = (string) $questionId;
                }
            }

            foreach ($this->extractDraftQuestionIds($attempt->draft_answers_json ?? null) as $questionId) {
                $questionOrder = (int) ($questionOrders[$questionId] ?? 0);
                if ($questionOrder > 0) {
                    $answeredOrderMap[$questionOrder] = $questionId;
                }
            }

            ksort($answeredOrderMap);
            $maxAnsweredOrder = $answeredOrderMap === [] ? 0 : max(array_keys($answeredOrderMap));
            $cursorOrder = $this->cursorToQuestionOrder(
                (string) ($attempt->draft_cursor ?? ''),
                $questionOrders,
                $totalQuestions
            );
            $isCompleted = $attempt->attempt_submitted_at !== null;

            if ($isCompleted) {
                if ($answeredOrderMap === []) {
                    continue;
                }

                $reachedMax = $totalQuestions;
            } else {
                $reachedMax = max($maxAnsweredOrder, $cursorOrder);
                if ($reachedMax <= 0) {
                    continue;
                }
            }

            $day = $this->resolveDateString(
                $attempt->attempt_submitted_at ?? null,
                $attempt->draft_updated_at ?? null,
                $attempt->attempt_updated_at ?? null,
                $attempt->attempt_created_at ?? null
            );
            $dimensions = $this->dimensionPayload($attempt, $day, $scaleCode);

            for ($order = 1; $order <= $reachedMax; $order++) {
                $questionId = $questionIdsByOrder[$order] ?? null;
                if (! is_string($questionId) || $questionId === '') {
                    continue;
                }

                $aggregateKey = $this->progressAggregateKey($dimensions, $questionId, $order);
                if (! isset($aggregates[$aggregateKey])) {
                    $aggregates[$aggregateKey] = [
                        'day' => $dimensions['day'],
                        'org_id' => $dimensions['org_id'],
                        'locale' => $dimensions['locale'],
                        'region' => $dimensions['region'],
                        'scale_code' => $dimensions['scale_code'],
                        'content_package_version' => $dimensions['content_package_version'],
                        'scoring_spec_version' => $dimensions['scoring_spec_version'],
                        'norm_version' => $dimensions['norm_version'],
                        'question_id' => $questionId,
                        'question_order' => $order,
                        'reached_attempts_count' => 0,
                        'answered_attempts_count' => 0,
                        'completed_attempts_count' => 0,
                        'dropoff_attempts_count' => 0,
                        'last_refreshed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $aggregates[$aggregateKey]['reached_attempts_count']++;
                if ($isCompleted) {
                    $aggregates[$aggregateKey]['completed_attempts_count']++;
                }
            }

            foreach ($answeredOrderMap as $questionOrder => $questionId) {
                $aggregateKey = $this->progressAggregateKey($dimensions, $questionId, (int) $questionOrder);
                if (! isset($aggregates[$aggregateKey])) {
                    $aggregates[$aggregateKey] = [
                        'day' => $dimensions['day'],
                        'org_id' => $dimensions['org_id'],
                        'locale' => $dimensions['locale'],
                        'region' => $dimensions['region'],
                        'scale_code' => $dimensions['scale_code'],
                        'content_package_version' => $dimensions['content_package_version'],
                        'scoring_spec_version' => $dimensions['scoring_spec_version'],
                        'norm_version' => $dimensions['norm_version'],
                        'question_id' => $questionId,
                        'question_order' => (int) $questionOrder,
                        'reached_attempts_count' => 0,
                        'answered_attempts_count' => 0,
                        'completed_attempts_count' => 0,
                        'dropoff_attempts_count' => 0,
                        'last_refreshed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $aggregates[$aggregateKey]['answered_attempts_count']++;
            }

            if (! $isCompleted) {
                $dropoffOrder = min($reachedMax, $totalQuestions);
                $dropoffQuestionId = $questionIdsByOrder[$dropoffOrder] ?? null;
                if (is_string($dropoffQuestionId) && $dropoffQuestionId !== '') {
                    $aggregateKey = $this->progressAggregateKey($dimensions, $dropoffQuestionId, $dropoffOrder);
                    if (! isset($aggregates[$aggregateKey])) {
                        $aggregates[$aggregateKey] = [
                            'day' => $dimensions['day'],
                            'org_id' => $dimensions['org_id'],
                            'locale' => $dimensions['locale'],
                            'region' => $dimensions['region'],
                            'scale_code' => $dimensions['scale_code'],
                            'content_package_version' => $dimensions['content_package_version'],
                            'scoring_spec_version' => $dimensions['scoring_spec_version'],
                            'norm_version' => $dimensions['norm_version'],
                            'question_id' => $dropoffQuestionId,
                            'question_order' => $dropoffOrder,
                            'reached_attempts_count' => 0,
                            'answered_attempts_count' => 0,
                            'completed_attempts_count' => 0,
                            'dropoff_attempts_count' => 0,
                            'last_refreshed_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $aggregates[$aggregateKey]['dropoff_attempts_count']++;
                }
            }
        }

        return [
            'rows' => array_values($aggregates),
            'source_attempts' => $candidateAttempts->count(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     */
    private function authoritativeAnswerRowCursor(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $locales,
    ): \Traversable {
        $query = DB::table('attempt_answer_rows as rows')
            ->join('attempts as attempts', 'attempts.id', '=', 'rows.attempt_id')
            ->select([
                'rows.attempt_id',
                'rows.question_id',
                'rows.answer_json',
                'rows.scale_code as row_scale_code',
                'rows.scale_code_v2 as row_scale_code_v2',
                'rows.scale_uid as row_scale_uid',
                'rows.submitted_at as row_submitted_at',
                'attempts.org_id',
                'attempts.scale_code as attempt_scale_code',
                'attempts.scale_code_v2 as attempt_scale_code_v2',
                'attempts.scale_uid as attempt_scale_uid',
                'attempts.locale',
                'attempts.region',
                'attempts.content_package_version',
                'attempts.scoring_spec_version',
                'attempts.norm_version',
                'attempts.submitted_at as attempt_submitted_at',
                'attempts.created_at as attempt_created_at',
            ])
            ->whereBetween(DB::raw('coalesce(rows.submitted_at, attempts.submitted_at, attempts.created_at)'), [$fromAt, $toAt]);

        if ($orgIds !== []) {
            $query->whereIn('attempts.org_id', $orgIds);
        }
        if ($locales !== []) {
            $query->whereIn('attempts.locale', $locales);
        }

        return $query
            ->orderBy('rows.attempt_id')
            ->orderBy('rows.question_id')
            ->cursor();
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     * @return Collection<int,object>
     */
    private function candidateAttemptCollection(
        CarbonImmutable $fromAt,
        CarbonImmutable $toAt,
        array $orgIds,
        array $locales,
    ): Collection {
        $query = DB::table('attempts as attempts')
            ->leftJoin('attempt_drafts as drafts', 'drafts.attempt_id', '=', 'attempts.id')
            ->select([
                'attempts.id as attempt_id',
                'attempts.org_id',
                'attempts.scale_code as attempt_scale_code',
                'attempts.scale_code_v2 as attempt_scale_code_v2',
                'attempts.scale_uid as attempt_scale_uid',
                'attempts.locale',
                'attempts.region',
                'attempts.content_package_version',
                'attempts.scoring_spec_version',
                'attempts.norm_version',
                'attempts.submitted_at as attempt_submitted_at',
                'attempts.created_at as attempt_created_at',
                'attempts.updated_at as attempt_updated_at',
                'drafts.cursor as draft_cursor',
                'drafts.answers_json as draft_answers_json',
                'drafts.updated_at as draft_updated_at',
            ])
            ->whereBetween(DB::raw('coalesce(attempts.submitted_at, drafts.updated_at, attempts.updated_at, attempts.created_at)'), [$fromAt, $toAt]);

        if ($orgIds !== []) {
            $query->whereIn('attempts.org_id', $orgIds);
        }
        if ($locales !== []) {
            $query->whereIn('attempts.locale', $locales);
        }

        return $query
            ->orderBy('attempts.id')
            ->get();
    }

    /**
     * @param  list<string>  $attemptIds
     * @return array<string,list<string>>
     */
    private function loadAnswerRowsByAttemptId(array $attemptIds): array
    {
        if ($attemptIds === [] || ! SchemaBaseline::hasTable('attempt_answer_rows')) {
            return [];
        }

        $rowsByAttemptId = [];

        foreach (array_chunk($attemptIds, 500) as $chunk) {
            $rows = DB::table('attempt_answer_rows')
                ->whereIn('attempt_id', $chunk)
                ->get(['attempt_id', 'question_id']);

            foreach ($rows as $row) {
                $attemptId = trim((string) ($row->attempt_id ?? ''));
                $questionId = trim((string) ($row->question_id ?? ''));

                if ($attemptId === '' || $questionId === '') {
                    continue;
                }

                $rowsByAttemptId[$attemptId] ??= [];
                $rowsByAttemptId[$attemptId][$questionId] = $questionId;
            }
        }

        foreach ($rowsByAttemptId as $attemptId => $questionIds) {
            $rowsByAttemptId[$attemptId] = array_values($questionIds);
        }

        return $rowsByAttemptId;
    }

    /**
     * @return list<string>
     */
    private function extractDraftQuestionIds(mixed $rawAnswers): array
    {
        if (! is_string($rawAnswers) || trim($rawAnswers) === '') {
            return [];
        }

        $decoded = json_decode($rawAnswers, true);
        if (! is_array($decoded)) {
            return [];
        }

        $questionIds = [];

        foreach ($decoded as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $questionId = trim((string) ($answer['question_id'] ?? ''));
            if ($questionId !== '') {
                $questionIds[$questionId] = $questionId;
            }
        }

        return array_values($questionIds);
    }

    /**
     * @param  array<string,int>  $questionOrders
     */
    private function cursorToQuestionOrder(string $cursor, array $questionOrders, int $totalQuestions): int
    {
        $normalized = trim($cursor);
        if ($normalized === '') {
            return 0;
        }

        if (isset($questionOrders[$normalized])) {
            return (int) $questionOrders[$normalized];
        }

        if (preg_match('/(\d+)/', $normalized, $matches) !== 1) {
            return 0;
        }

        $candidate = (int) ($matches[1] ?? 0);
        if ($candidate <= 0) {
            return 0;
        }

        if (isset($questionOrders[(string) $candidate])) {
            return (int) $questionOrders[(string) $candidate];
        }

        return min($candidate, $totalQuestions);
    }

    private function extractOptionKey(mixed $rawAnswerJson): ?string
    {
        if (! is_string($rawAnswerJson) || trim($rawAnswerJson) === '') {
            return null;
        }

        $decoded = json_decode($rawAnswerJson, true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach (['code', 'option_key', 'option_code', 'value'] as $key) {
            $candidate = $decoded[$key] ?? null;
            if (is_scalar($candidate)) {
                $normalized = trim((string) $candidate);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        $answer = $decoded['answer'] ?? null;
        if (is_array($answer)) {
            foreach (['code', 'value'] as $key) {
                $candidate = $answer[$key] ?? null;
                if (is_scalar($candidate)) {
                    $normalized = trim((string) $candidate);
                    if ($normalized !== '') {
                        return $normalized;
                    }
                }
            }
        }

        return null;
    }

    private function resolveDateString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            return CarbonImmutable::parse($value)->toDateString();
        }

        return now()->toDateString();
    }

    /**
     * @return array{
     *     day:string,
     *     org_id:int,
     *     locale:string,
     *     region:string,
     *     scale_code:string,
     *     content_package_version:string,
     *     scoring_spec_version:string,
     *     norm_version:string
     * }
     */
    private function dimensionPayload(object $row, string $day, string $scaleCode): array
    {
        return [
            'day' => $day,
            'org_id' => max(0, (int) ($row->org_id ?? 0)),
            'locale' => $this->stringOrDefault($row->locale ?? null, 'unknown'),
            'region' => $this->stringOrDefault($row->region ?? null, 'unknown'),
            'scale_code' => $scaleCode,
            'content_package_version' => $this->stringOrDefault($row->content_package_version ?? null, 'unknown'),
            'scoring_spec_version' => $this->stringOrDefault($row->scoring_spec_version ?? null, 'unknown'),
            'norm_version' => $this->stringOrDefault($row->norm_version ?? null, 'unknown'),
        ];
    }

    /**
     * @param  array<string,mixed>  $dimensions
     */
    private function optionAggregateKey(array $dimensions, string $questionId, int $questionOrder, string $optionKey): string
    {
        return implode('|', [
            $dimensions['day'],
            (string) $dimensions['org_id'],
            $dimensions['locale'],
            $dimensions['region'],
            $dimensions['scale_code'],
            $dimensions['content_package_version'],
            $dimensions['scoring_spec_version'],
            $dimensions['norm_version'],
            $questionId,
            (string) $questionOrder,
            $optionKey,
        ]);
    }

    /**
     * @param  array<string,mixed>  $dimensions
     */
    private function progressAggregateKey(array $dimensions, string $questionId, int $questionOrder): string
    {
        return implode('|', [
            $dimensions['day'],
            (string) $dimensions['org_id'],
            $dimensions['locale'],
            $dimensions['region'],
            $dimensions['scale_code'],
            $dimensions['content_package_version'],
            $dimensions['scoring_spec_version'],
            $dimensions['norm_version'],
            $questionId,
            (string) $questionOrder,
        ]);
    }

    /**
     * @param  list<int>  $orgIds
     * @param  list<string>  $locales
     * @param  list<string>  $scaleCodes
     */
    private function deleteScope(
        string $table,
        string $from,
        string $to,
        array $orgIds,
        array $locales,
        array $scaleCodes,
    ): int {
        if (! SchemaBaseline::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table)
            ->whereBetween('day', [$from, $to]);

        if ($orgIds !== []) {
            $query->whereIn('org_id', $orgIds);
        }
        if ($locales !== []) {
            $query->whereIn('locale', $locales);
        }
        if ($scaleCodes !== []) {
            $query->whereIn('scale_code', $scaleCodes);
        }

        return $query->delete();
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private function normalizeOrgIds(array $orgIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): int => max(0, (int) $value),
            $orgIds
        ), static fn (int $value): bool => $value > 0)));
    }

    /**
     * @param  list<string>  $scaleCodes
     * @return list<string>
     */
    private function normalizeScaleCodes(array $scaleCodes): array
    {
        return array_values(array_unique(array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            array_filter($scaleCodes, static fn (string $value): bool => trim($value) !== '')
        )));
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        return array_values(array_unique(array_map(
            static fn (string $value): string => trim($value),
            array_filter($locales, static fn (string $value): bool => trim($value) !== '')
        )));
    }

    private function stringOrDefault(mixed $value, string $fallback): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }
}
