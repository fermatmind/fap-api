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

        return DB::transaction(function () use (
            $slugs,
            $locales,
            $rollbackGroup,
            $batchId,
            $transaction,
            $quarantineOnFailure,
            $preStates,
        ) {
            $promotedStates = $this->createPromotionIndexStates($slugs, $batchId);

            $postLedger = $this->ledgerService->build();
            $postProjection = $this->projectionService->buildFromLedgerArray($postLedger->toArray());
            $postTruth = $this->truthExporter->buildFromProjectionArray($postProjection);

            $postPromotionValidation = $this->rollbackGate->validatePostPromotion(
                $this->publishedManifest($batchId, $slugs, $locales, $rollbackGroup),
                $postTruth,
                $postProjection,
            );

            if (($postPromotionValidation['status'] ?? null) === 'pass') {
                $releaseGate = $this->releaseGateService->evaluate(
                    $this->publishedManifest($batchId, $slugs, $locales, $rollbackGroup),
                    $postTruth,
                    $postProjection,
                );

                $closeoutAllowed = (bool) ($releaseGate['closeout_allowed'] ?? false);

                if ($closeoutAllowed) {
                    return $this->successResult(
                        $transaction,
                        $preStates,
                        $promotedStates,
                        $releaseGate,
                        $postProjection,
                        $postTruth,
                    );
                }

                $this->rollbackPromotion($slugs, $preStates, $batchId, 'release_gate_failed', $quarantineOnFailure);
                DB::rollBack();

                return $this->rollbackResult(
                    $transaction,
                    $postPromotionValidation,
                    $releaseGate,
                    $preStates,
                    $quarantineOnFailure,
                );
            }

            $this->rollbackPromotion($slugs, $preStates, $batchId, 'post_promotion_validation_failed', $quarantineOnFailure);
            DB::rollBack();

            return $this->rollbackResult(
                $transaction,
                $postPromotionValidation,
                null,
                $preStates,
                $quarantineOnFailure,
            );
        });
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
                'changed_at' => now(),
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
     * @param  array<string, string>  $preStates
     */
    private function rollbackPromotion(
        array $slugs,
        array $preStates,
        string $batchId,
        string $reason,
        bool $quarantine,
    ): void {
        $targetState = $quarantine ? 'noindex' : 'promotion_candidate';
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
                    'canonical_rollout_batch_'.$reason,
                    'batch_id:'.$batchId,
                    $quarantine ? 'quarantine' : 'rollback',
                ],
                'changed_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function truthFromProjection(array $projection): array
    {
        return $this->truthExporter->buildFromProjectionArray($projection);
    }

    /**
     * @param  list<string>  $params
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
     * @param  array<string, mixed>  $postPromotionValidation
     * @param  array<string, mixed>|null  $releaseGate
     * @param  array<string, string>  $preStates
     * @return array<string, mixed>
     */
    private function rollbackResult(
        CanonicalPromotionTransaction $transaction,
        array $postPromotionValidation,
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
