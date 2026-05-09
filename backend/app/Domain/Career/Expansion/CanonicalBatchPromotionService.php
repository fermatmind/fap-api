<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;

final class CanonicalBatchPromotionService
{
    public function __construct(
        private readonly CanonicalPromotionRollbackGate $rollbackGate,
        private readonly CanonicalRolloutBatchStateMachine $stateMachine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(array $manifestPayload, ?array $truth = null, ?array $projection = null): array
    {
        $manifest = $this->manifest($manifestPayload);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest, dryRun: true);
        $validation = $this->rollbackGate->validatePromotionPlan($manifest, $truth, $projection);
        $expectedPublishedManifest = $this->publishedManifest($manifest);

        return (new CanonicalPromotionResultDTO(
            status: $validation['status'] === 'pass' ? 'planned' : 'blocked',
            transaction: $transaction,
            dryRun: true,
            promotionPlan: [
                'candidate_rows' => $transaction->expectedLocaleRows(),
                'expected_published_rows' => $transaction->expectedLocaleRows(),
                'required_validations' => CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS,
                'rollback_group' => $transaction->rollbackGroup,
                'expected_published_manifest' => $expectedPublishedManifest,
            ],
            auditLog: [
                $this->auditEvent('promotion_plan_built', [
                    'batch_id' => $transaction->batchId,
                    'candidate_locale_rows' => count($transaction->expectedLocaleRows()),
                    'status' => $validation['status'],
                ]),
            ],
            failures: is_array($validation['failures'] ?? null) ? $validation['failures'] : [],
            updatedManifest: $expectedPublishedManifest,
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function promote(
        array $manifestPayload,
        array $postPromotionTruth,
        ?array $postPromotionProjection = null,
        string $failureMode = 'rollback',
    ): array {
        $manifest = $this->manifest($manifestPayload);
        $transaction = CanonicalPromotionTransaction::fromManifest($manifest, dryRun: false);
        $planValidation = $this->rollbackGate->validatePromotionPlan($manifest);
        if (($planValidation['status'] ?? null) !== 'pass') {
            return (new CanonicalPromotionResultDTO(
                status: 'blocked',
                transaction: $transaction,
                dryRun: false,
                promotionPlan: [
                    'candidate_rows' => $transaction->expectedLocaleRows(),
                    'expected_published_rows' => $transaction->expectedLocaleRows(),
                    'required_validations' => CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS,
                    'rollback_group' => $transaction->rollbackGroup,
                ],
                auditLog: [
                    $this->auditEvent('promotion_precondition_failed', [
                        'batch_id' => $transaction->batchId,
                    ]),
                ],
                failures: is_array($planValidation['failures'] ?? null) ? $planValidation['failures'] : [],
            ))->toArray();
        }

        $expectedPublishedManifest = $this->publishedManifest($manifest);
        $postPromotionValidation = $this->rollbackGate->validatePostPromotion(
            $expectedPublishedManifest,
            $postPromotionTruth,
            $postPromotionProjection,
        );

        if (($postPromotionValidation['status'] ?? null) === 'pass') {
            return (new CanonicalPromotionResultDTO(
                status: 'promoted',
                transaction: $transaction,
                dryRun: false,
                promotionPlan: [
                    'candidate_rows' => $transaction->expectedLocaleRows(),
                    'expected_published_rows' => $transaction->expectedLocaleRows(),
                    'required_validations' => CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS,
                    'rollback_group' => $transaction->rollbackGroup,
                ],
                postPromotionValidation: $postPromotionValidation,
                auditLog: [
                    $this->auditEvent('promotion_validation_passed', [
                        'batch_id' => $transaction->batchId,
                    ]),
                ],
                updatedManifest: $expectedPublishedManifest,
            ))->toArray();
        }

        $rollback = $this->rollbackGate->rollback(
            $expectedPublishedManifest,
            $failureMode,
            is_array($postPromotionValidation['failures'] ?? null) ? $postPromotionValidation['failures'] : [],
        )->toArray();

        return (new CanonicalPromotionResultDTO(
            status: (string) ($rollback['status'] ?? 'rolled_back'),
            transaction: $transaction,
            dryRun: false,
            promotionPlan: [
                'candidate_rows' => $transaction->expectedLocaleRows(),
                'expected_published_rows' => $transaction->expectedLocaleRows(),
                'required_validations' => CanonicalPromotionRollbackGate::REQUIRED_POST_PROMOTION_FIELDS,
                'rollback_group' => $transaction->rollbackGroup,
            ],
            postPromotionValidation: $postPromotionValidation,
            rollback: $rollback,
            auditLog: [
                $this->auditEvent('promotion_validation_failed', [
                    'batch_id' => $transaction->batchId,
                    'failure_mode' => $failureMode,
                    'rollback_status' => $rollback['status'] ?? null,
                ]),
            ],
            failures: is_array($postPromotionValidation['failures'] ?? null) ? $postPromotionValidation['failures'] : [],
            updatedManifest: is_array($rollback['updated_manifest'] ?? null) ? $rollback['updated_manifest'] : null,
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedManifest(array $manifest): array
    {
        $transition = $this->stateMachine->transition(
            $manifest,
            CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
            ['status' => 'pass'],
        );

        $updatedManifest = $transition['updated_manifest'] ?? null;

        return is_array($updatedManifest)
            ? $updatedManifest
            : array_merge($manifest, [
                'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
                'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(array $manifestPayload): array
    {
        $manifest = $manifestPayload['manifest'] ?? $manifestPayload;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function auditEvent(string $event, array $context): array
    {
        return [
            'event' => $event,
            'context' => $context,
            'writes_database' => false,
        ];
    }
}
