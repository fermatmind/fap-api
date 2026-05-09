<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Support\Facades\DB;

final class CanonicalBatchPromotionExecutorService
{
    public function __construct(
        private readonly CanonicalPromotionRollbackGate $rollbackGate,
        private readonly CareerFullReleaseLedgerService $ledgerService,
        private readonly CareerRuntimePublishProjectionService $projectionService,
        private readonly CareerCanonicalRuntimeTruthExporter $truthExporter,
        private readonly CanonicalPostPromotionReleaseGateService $releaseGateService,
    ) {}

    /**
     * @param  array{
     *      batch_id: string,
     *      slugs: list<string>,
     *      locales: list<string>,
     *      rollback_group: list<string>,
     *  }  $params
     * @param  array<string, mixed>|null  $prePromotionProjection
     * @return array<string, mixed>
     */
    public function execute(
        array $params,
        bool $dryRun = true,
        bool $quarantineOnFailure = false,
        ?array $prePromotionProjection = null,
    ): array {
        $manifest = $this->toManifest($params);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest, dryRun: $dryRun);
        $slugs = $transaction->slugs;
        $locales = $transaction->locales;
        $rollbackGroup = $transaction->rollbackGroup;
        $batchId = $transaction->batchId;

        $planValidation = $this->rollbackGate->validatePromotionPlan(
            $manifest,
            $prePromotionProjection !== null ? $this->truthFromProjection($prePromotionProjection) : null,
            $prePromotionProjection,
        );

        if (($planValidation['status'] ?? null) !== 'pass') {
            return $this->blockedResult($transaction, $planValidation);
        }

        if ($dryRun) {
            return $this->dryRunResult($transaction, $planValidation);
        }

        $preStates = $this->capturePreStates($slugs);

        $promotionResult = $this->executePromotion(
            $slugs, $locales, $rollbackGroup, $batchId, $transaction, $preStates,
        );

        if (($promotionResult['status'] ?? null) === 'promoted_success') {
            return $promotionResult;
        }

        return $this->executeRemediation(
            $slugs, $batchId, $transaction, $preStates,
            $promotionResult, $quarantineOnFailure,
        );
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, string>  $preStates
     * @return array<string, mixed>
     */
    private function executePromotion(
        array $slugs,
        array $locales,
        array $rollbackGroup,
        string $batchId,
        CanonicalPromotionTransaction $transaction,
        array $preStates,
    ): array {
        DB::beginTransaction();

        try {
            $promotedStates = $this->createPromotionIndexStates($slugs, $batchId);

            $postProjection = $this->freshProjection();
            $postTruth = $this->truthExporter->buildFromProjectionArray($postProjection);

            $expectedLocaleRows = $transaction->expectedLocaleRows();
            $persistenceCheck = $this->verifyPromotionPersistence(
                $postProjection, $postTruth, $expectedLocaleRows,
            );

            if (! $persistenceCheck['persisted']) {
                DB::rollBack();

                return $this->promotionNotPersistedResult(
                    $transaction, $preStates, $promotedStates, $persistenceCheck,
                );
            }

            $postPromotionValidation = $this->rollbackGate->validatePostPromotion(
                $this->publishedManifest($batchId, $slugs, $locales, $rollbackGroup),
                $postTruth,
                $postProjection,
            );

            if (($postPromotionValidation['status'] ?? null) !== 'pass') {
                DB::rollBack();

                return $this->promotionValidationFailedResult(
                    $transaction, $preStates, $promotedStates,
                    $postPromotionValidation, null,
                );
            }

            $releaseGate = $this->releaseGateService->evaluate(
                $this->publishedManifest($batchId, $slugs, $locales, $rollbackGroup),
                $postTruth,
                $postProjection,
            );

            $closeoutAllowed = (bool) ($releaseGate['closeout_allowed'] ?? false);

            if (! $closeoutAllowed) {
                DB::rollBack();

                return $this->promotionValidationFailedResult(
                    $transaction, $preStates, $promotedStates,
                    $postPromotionValidation, $releaseGate,
                );
            }

            DB::commit();

            return $this->successResult(
                $transaction, $preStates, $promotedStates, $releaseGate,
                $postProjection, $postTruth,
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, string>  $preStates
     * @param  array<string, mixed>  $promotionResult
     * @return array<string, mixed>
     */
    private function executeRemediation(
        array $slugs,
        string $batchId,
        CanonicalPromotionTransaction $transaction,
        array $preStates,
        array $promotionResult,
        bool $quarantineOnFailure,
    ): array {
        $reason = $quarantineOnFailure ? 'quarantine' : 'rollback';
        $targetState = $quarantineOnFailure ? 'noindex' : 'promotion_candidate';

        DB::beginTransaction();

        try {
            $this->createRemediationIndexStates($slugs, $batchId, $targetState, $quarantineOnFailure);

            $postProjection = $this->freshProjection();
            $expectedLocaleRows = $transaction->expectedLocaleRows();
            $quarantineCheck = $this->verifyQuarantinePersistence(
                $postProjection, $expectedLocaleRows, $quarantineOnFailure, $targetState,
            );

            if (! ($quarantineCheck['persisted'] ?? false)) {
                DB::rollBack();

                return $this->remediationNotPersistedResult(
                    $transaction, $preStates, $quarantineOnFailure, $quarantineCheck,
                );
            }

            DB::commit();

            return $this->rollbackResult(
                $transaction, $promotionResult['post_promotion_validation'] ?? null,
                $promotionResult['release_gate'] ?? null,
                $preStates, $quarantineOnFailure,
            );
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function freshProjection(): array
    {
        $ledger = $this->ledgerService->build();

        return $this->projectionService->buildFromLedgerArray($ledger->toArray());
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, string>
     */
    private function capturePreStates(array $slugs): array
    {
        $preStates = [];
        $occupations = Occupation::query()
            ->with(['indexStates' => function ($q): void {
                $q->orderByDesc('changed_at')->orderByDesc('updated_at')->limit(1);
            }])
            ->whereIn('canonical_slug', $slugs)
            ->get(['id', 'canonical_slug']);

        foreach ($occupations as $occupation) {
            $slug = strtolower(trim((string) $occupation->canonical_slug));
            $latestIndexState = $occupation->indexStates->first();
            $preStates[$slug] = $latestIndexState?->index_state ?? 'noindex';
        }

        foreach ($slugs as $slug) {
            if (! isset($preStates[$slug])) {
                $preStates[$slug] = 'noindex';
            }
        }

        return $preStates;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, array{occupation_id: string, index_state_id: string, previous_state: string}>
     */
    private function createPromotionIndexStates(array $slugs, string $batchId): array
    {
        $now = now()->addSeconds(5);
        $promoted = [];
        $occupations = Occupation::query()->whereIn('canonical_slug', $slugs)->get(['id', 'canonical_slug']);

        foreach ($occupations as $occupation) {
            $slug = strtolower(trim((string) $occupation->canonical_slug));

            $indexState = IndexState::query()->create([
                'occupation_id' => $occupation->id,
                'index_state' => 'indexed',
                'index_eligible' => true,
                'canonical_path' => '/career/jobs/'.$slug,
                'canonical_target' => null,
                'reason_codes' => [
                    'canonical_rollout_batch_promotion',
                    'batch_id:'.$batchId,
                ],
                'changed_at' => $now,
            ]);

            $promoted[$slug] = [
                'occupation_id' => (string) $occupation->id,
                'index_state_id' => (string) $indexState->id,
                'previous_state' => 'indexed',
            ];
        }

        return $promoted;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function createRemediationIndexStates(
        array $slugs,
        string $batchId,
        string $targetState,
        bool $quarantine,
    ): void {
        $now = now()->addSeconds(5);
        $occupations = Occupation::query()->whereIn('canonical_slug', $slugs)->get(['id', 'canonical_slug']);

        foreach ($occupations as $occupation) {
            $slug = strtolower(trim((string) $occupation->canonical_slug));

            IndexState::query()->create([
                'occupation_id' => $occupation->id,
                'index_state' => $targetState,
                'index_eligible' => ! $quarantine,
                'canonical_path' => '/career/jobs/'.$slug,
                'canonical_target' => null,
                'reason_codes' => [
                    'canonical_rollout_batch_remediation',
                    'batch_id:'.$batchId,
                    $quarantine ? 'quarantine' : 'rollback_to_candidate',
                ],
                'changed_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $truth
     * @param  list<array{slug: string, locale: string}>  $expectedRows
     * @return array{persisted: bool, expected: int, found_published: int, missing: list<array{slug: string, locale: string}>, not_published: list<array{slug: string, locale: string, state: string}>}
     */
    private function verifyPromotionPersistence(
        array $projection,
        array $truth,
        array $expectedRows,
    ): array {
        $projectionItems = $this->itemsFromPayload($projection);
        $truthItems = $this->itemsFromPayload($truth);
        $foundPublished = 0;
        $missing = [];
        $notPublished = [];

        foreach ($expectedRows as $expected) {
            $item = $this->itemFor($truthItems, $expected['slug'], $expected['locale']);

            if ($item === null) {
                $missing[] = $expected;

                continue;
            }

            $state = (string) ($item['projection_state'] ?? '');

            if ($state === CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $foundPublished++;
            } else {
                $notPublished[] = [
                    'slug' => $expected['slug'],
                    'locale' => $expected['locale'],
                    'state' => $state,
                ];
            }
        }

        return [
            'persisted' => count($missing) === 0 && count($notPublished) === 0,
            'expected' => count($expectedRows),
            'found_published' => $foundPublished,
            'missing' => $missing,
            'not_published' => $notPublished,
            'projection_items_total' => count($projectionItems),
            'truth_items_total' => count($truthItems),
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @param  list<array{slug: string, locale: string}>  $expectedRows
     * @return array{persisted: bool, expected: int, found_target: int, not_converged: list<array{slug: string, locale: string, state: string}>}
     */
    private function verifyQuarantinePersistence(
        array $projection,
        array $expectedRows,
        bool $quarantineOnFailure,
        string $targetState,
    ): array {
        $truth = $this->truthExporter->buildFromProjectionArray($projection);
        $truthItems = $this->itemsFromPayload($truth);
        $expectedTargetState = $quarantineOnFailure
            ? CareerRuntimePublishProjectionService::STATE_QUARANTINED
            : CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE;
        $foundTarget = 0;
        $notConverged = [];

        foreach ($expectedRows as $expected) {
            $item = $this->itemFor($truthItems, $expected['slug'], $expected['locale']);

            if ($item === null) {
                $notConverged[] = [
                    'slug' => $expected['slug'],
                    'locale' => $expected['locale'],
                    'state' => 'missing',
                ];

                continue;
            }

            $state = (string) ($item['projection_state'] ?? '');

            if ($state === $expectedTargetState) {
                $foundTarget++;
            } else {
                $notConverged[] = [
                    'slug' => $expected['slug'],
                    'locale' => $expected['locale'],
                    'state' => $state,
                ];
            }
        }

        return [
            'persisted' => count($notConverged) === 0,
            'expected' => count($expectedRows),
            'found_target' => $foundTarget,
            'target_state' => $targetState,
            'expected_projection_state' => $expectedTargetState,
            'not_converged' => $notConverged,
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function truthFromProjection(array $projection): array
    {
        return $this->truthExporter->buildFromProjectionArray($projection);
    }

    private function publishedManifest(string $batchId, array $slugs, array $locales, array $rollbackGroup): array
    {
        return [
            'batch_id' => $batchId,
            'slugs' => $slugs,
            'locales' => $locales,
            'rollback_group' => $rollbackGroup,
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
        ];
    }

    /**
     * @param  array{batch_id: string, slugs: list<string>, locales: list<string>, rollback_group: list<string>}  $params
     * @return array{batch_id: string, slugs: list<string>, locales: list<string>, rollback_group: list<string>, rollout_state: string, projection_state: string}
     */
    private function toManifest(array $params): array
    {
        return [
            'batch_id' => trim((string) ($params['batch_id'] ?? '')),
            'slugs' => array_values($this->normalizedSlugs($params['slugs'] ?? [])),
            'locales' => array_values($this->normalizedStrings($params['locales'] ?? [], true)),
            'rollback_group' => array_values($this->normalizedSlugs($params['rollback_group'] ?? [])),
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function itemsFromPayload(array $payload): array
    {
        $items = $payload['items'] ?? [];

        return is_array($items)
            ? array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)))
            : [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemFor(array $items, string $slug, string $locale): ?array
    {
        foreach ($items as $item) {
            if (
                strtolower(trim((string) ($item['slug'] ?? ''))) === $slug
                && strtolower(trim((string) ($item['locale'] ?? ''))) === $locale
            ) {
                return $item;
            }
        }

        return null;
    }

    // ─── Result DTO methods ────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $preStates
     * @param  array<string, mixed>  $promotedStates
     * @param  array<string, mixed>  $releaseGate
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>  $truth
     * @return array<string, mixed>
     */
    private function successResult(
        CanonicalPromotionTransaction $transaction,
        array $preStates,
        array $promotedStates,
        array $releaseGate,
        array $projection,
        array $truth,
    ): array {
        $projectionCounts = is_array($projection['counts'] ?? null) ? $projection['counts'] : [];
        $truthCounts = is_array($truth['counts'] ?? null) ? $truth['counts'] : [];

        return [
            'status' => 'promoted_success',
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => false,
            'writes_database' => true,
            'write_verified' => true,
            'pre_states' => $preStates,
            'promoted_states' => $promotedStates,
            'post_promotion_validation' => [
                'status' => 'pass',
                'projection_counts' => $projectionCounts,
                'truth_counts' => $truthCounts,
            ],
            'release_gate' => $releaseGate,
            'closeout_allowed' => (bool) ($releaseGate['closeout_allowed'] ?? false),
            'rollback_required' => false,
            'quarantine_required' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $planValidation
     * @return array<string, mixed>
     */
    private function dryRunResult(
        CanonicalPromotionTransaction $transaction,
        array $planValidation,
    ): array {
        return [
            'status' => 'planned',
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => true,
            'writes_database' => false,
            'plan_validation' => $planValidation,
            'promotion_plan' => [
                'candidate_rows' => $transaction->expectedLocaleRows(),
                'expected_published_rows' => $transaction->expectedLocaleRows(),
                'rollback_group' => $transaction->rollbackGroup,
            ],
            'failures' => is_array($planValidation['failures'] ?? null) ? $planValidation['failures'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $planValidation
     * @return array<string, mixed>
     */
    private function blockedResult(
        CanonicalPromotionTransaction $transaction,
        array $planValidation,
    ): array {
        return [
            'status' => 'blocked',
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => false,
            'writes_database' => false,
            'plan_validation' => $planValidation,
            'failures' => is_array($planValidation['failures'] ?? null) ? $planValidation['failures'] : [],
        ];
    }

    /**
     * @param  array<string, string>  $preStates
     * @param  array<string, mixed>  $promotedStates
     * @param  array<string, mixed>  $persistenceCheck
     * @return array<string, mixed>
     */
    private function promotionNotPersistedResult(
        CanonicalPromotionTransaction $transaction,
        array $preStates,
        array $promotedStates,
        array $persistenceCheck,
    ): array {
        return [
            'status' => 'promotion_write_not_persisted',
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => false,
            'writes_database' => false,
            'write_verified' => false,
            'persistence_check' => $persistenceCheck,
            'pre_states' => $preStates,
            'promoted_states' => $promotedStates,
            'rollback_required' => false,
            'quarantine_required' => false,
            'failures' => [
                [
                    'reason' => 'promotion_write_not_persisted',
                    'context' => [
                        'expected' => $persistenceCheck['expected'] ?? 0,
                        'found_published' => $persistenceCheck['found_published'] ?? 0,
                        'missing_count' => count($persistenceCheck['missing'] ?? []),
                        'not_published_count' => count($persistenceCheck['not_published'] ?? []),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $preStates
     * @param  array<string, mixed>  $postPromotionValidation
     * @param  array<string, mixed>|null  $releaseGate
     * @return array<string, mixed>
     */
    private function promotionValidationFailedResult(
        CanonicalPromotionTransaction $transaction,
        array $preStates,
        array $promotedStates,
        array $postPromotionValidation,
        ?array $releaseGate,
    ): array {
        return [
            'status' => 'promotion_validated_as_failed',
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => false,
            'writes_database' => false,
            'promotion_rolled_back' => true,
            'pre_states' => $preStates,
            'promoted_states' => $promotedStates,
            'post_promotion_validation' => $postPromotionValidation,
            'release_gate' => $releaseGate,
            'failures' => is_array($postPromotionValidation['failures'] ?? null)
                ? $postPromotionValidation['failures']
                : [],
        ];
    }

    /**
     * @param  array<string, string>  $preStates
     * @param  array<string, mixed>  $quarantineCheck
     * @return array<string, mixed>
     */
    private function remediationNotPersistedResult(
        CanonicalPromotionTransaction $transaction,
        array $preStates,
        bool $quarantineOnFailure,
        array $quarantineCheck,
    ): array {
        $status = $quarantineOnFailure ? 'quarantine_write_not_persisted' : 'rollback_write_not_persisted';

        return [
            'status' => $status,
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => false,
            'writes_database' => false,
            'write_verified' => false,
            'quarantine_check' => $quarantineCheck,
            'pre_states' => $preStates,
            'rollback_required' => false,
            'quarantine_required' => false,
            'failures' => [
                [
                    'reason' => $quarantineOnFailure
                        ? 'quarantine_write_not_persisted'
                        : 'rollback_write_not_persisted',
                    'context' => [
                        'expected' => $quarantineCheck['expected'] ?? 0,
                        'found_target' => $quarantineCheck['found_target'] ?? 0,
                        'expected_projection_state' => $quarantineCheck['expected_projection_state'] ?? null,
                        'not_converged_count' => count($quarantineCheck['not_converged'] ?? []),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $postPromotionValidation
     * @param  array<string, mixed>|null  $releaseGate
     * @param  array<string, string>  $preStates
     * @return array<string, mixed>
     */
    private function rollbackResult(
        CanonicalPromotionTransaction $transaction,
        ?array $postPromotionValidation,
        ?array $releaseGate,
        array $preStates,
        bool $quarantineOnFailure,
    ): array {
        $status = $quarantineOnFailure ? 'failed_and_quarantined' : 'failed_and_rolled_back';

        return [
            'status' => $status,
            'batch_id' => $transaction->batchId,
            'promoted_slugs' => $transaction->slugs,
            'promoted_locale_rows' => count($transaction->expectedLocaleRows()),
            'rollback_group' => $transaction->rollbackGroup,
            'dry_run' => false,
            'writes_database' => true,
            'write_verified' => true,
            'pre_states' => $preStates,
            'post_promotion_validation' => $postPromotionValidation,
            'release_gate' => $releaseGate,
            'rollback_required' => true,
            'quarantine_required' => $quarantineOnFailure,
            'rollback_action' => $quarantineOnFailure ? 'quarantined' : 'rolled_back_to_candidate',
            'failures' => is_array($postPromotionValidation['failures'] ?? null)
                ? $postPromotionValidation['failures']
                : [],
        ];
    }

    // ─── Normalization helpers ─────────────────────────────────────────────

    /**
     * @param  string[]|mixed  $value
     * @return list<string>
     */
    private function normalizedSlugs(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $slugs = [];

        foreach ($values as $item) {
            $slug = strtolower(trim((string) $item));
            if ($slug !== '') {
                $slugs[$slug] = $slug;
            }
        }

        ksort($slugs);

        return array_values($slugs);
    }

    /**
     * @param  string[]|mixed  $value
     * @return list<string>
     */
    private function normalizedStrings(mixed $value, bool $lower = false): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $strings = [];

        foreach ($values as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $s = $lower ? strtolower($s) : $s;
                $strings[$s] = $s;
            }
        }

        ksort($strings);

        return array_values($strings);
    }
}
