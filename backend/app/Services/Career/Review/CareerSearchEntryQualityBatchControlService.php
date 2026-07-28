<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Models\CareerSearchEntryQualityBatchOperation;
use App\Models\ReviewAttestation;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exact, append-only control plane for the first Career search-entry batch.
 *
 * Review evidence and production apply remain separate transitions. Neither
 * transition publishes, changes indexability, warms caches, queues work, or
 * submits URLs to a search provider.
 *
 * @review-surface career_trust_manifest
 */
final readonly class CareerSearchEntryQualityBatchControlService
{
    public const OPERATION_SCHEMA_VERSION = 'career.search_entry_quality_batch.operation.v1';

    public const CONTROL_SCHEMA_VERSION = 'career.search_entry_quality_batch.control.v1';

    public const OPERATION_APPLY = 'apply';

    public const OPERATION_ROLLBACK = 'rollback';

    private const EXPECTED_CANDIDATE_COUNT = 50;

    private const EXPECTED_BILINGUAL_URL_COUNT = 100;

    private const EXPECTED_REVIEW_TARGET_COUNT = 300;

    public function __construct(
        private CareerSearchEntryQualityBatchPlanner $planner,
        private CareerSeoReviewAttestationService $reviews,
        private ReviewAttestationCanonicalizer $canonicalizer,
    ) {}

    /** @return array<string,mixed> */
    public function reviewPreflight(string $expectedPackagePath, int $actorAdminUserId): array
    {
        $authority = $this->authority($expectedPackagePath);
        $this->assertActor($actorAdminUserId);
        $review = $this->approvedReview($authority);

        return [
            ...$this->safeAuthority($authority),
            'status' => 'PASS_REVIEW_PREFLIGHT',
            'actor_admin_user_id' => $actorAdminUserId,
            'review_state' => $review instanceof ReviewAttestation
                ? 'approved_all_exact'
                : 'awaiting_exact_approved_all_binding',
            'review_evidence_sha256' => $review?->evidence_sha256,
            'review_write_count' => 0,
            'production_write_execution' => false,
            'preflight_state_sha256' => $this->stateSha(
                $authority,
                $actorAdminUserId,
                $review?->evidence_sha256,
                'review',
            ),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /** @return array<string,mixed> */
    public function bindReview(string $expectedPackagePath, int $actorAdminUserId): array
    {
        $authority = $this->authority($expectedPackagePath);
        $this->assertActor($actorAdminUserId);
        $review = $this->approvedReview($authority)
            ?? $this->reviews->createAndBindReview(
                surfaceId: CareerPilotReviewEvidenceBridge::SURFACE_ID,
                scopeType: CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
                scopeIdentity: (string) $authority['review_scope_identity'],
                decision: 'approved_all',
                authoritativeTargets: $authority['review_targets'],
                actorAdminUserId: $actorAdminUserId,
                packageSha256: (string) $authority['review_package_sha256'],
            );
        $review->loadMissing('targetEvidences');
        if ((int) $review->target_count !== self::EXPECTED_REVIEW_TARGET_COUNT
            || $review->targetEvidences->count() !== self::EXPECTED_REVIEW_TARGET_COUNT
            || ! hash_equals(
                (string) $authority['target_set_sha256'],
                (string) $review->target_set_sha256,
            )) {
            throw new RuntimeException('Bound Career batch review evidence failed exact readback.');
        }

        return [
            ...$this->safeAuthority($authority),
            'status' => $review->wasRecentlyCreated
                ? 'PASS_REVIEW_BOUND'
                : 'PASS_REVIEW_ALREADY_BOUND',
            'actor_admin_user_id' => $actorAdminUserId,
            'review_state' => 'approved_all_exact',
            'review_attestation_id' => (int) $review->id,
            'review_evidence_sha256' => (string) $review->evidence_sha256,
            'review_target_evidence_count' => $review->targetEvidences->count(),
            'review_write_count' => $review->wasRecentlyCreated
                ? self::EXPECTED_REVIEW_TARGET_COUNT + 1
                : 0,
            'production_write_execution' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function operationPreflight(string $expectedPackagePath, array $options): array
    {
        [$authority, $review, $validated] = $this->operationAuthority(
            $expectedPackagePath,
            $options,
        );
        $active = $this->activeApply($authority, $review);

        return [
            ...$this->safeAuthority($authority),
            ...$validated,
            'status' => 'PASS_APPLY_PREFLIGHT',
            'review_state' => 'approved_all_exact',
            'review_attestation_id' => (int) $review->id,
            'review_evidence_sha256' => (string) $review->evidence_sha256,
            'search_entry_tier_before' => $active instanceof CareerSearchEntryQualityBatchOperation
                ? 'eligible_by_exact_apply'
                : CareerSearchEntryTierResolver::TIER_INELIGIBLE,
            'active_apply_receipt_sha256' => $active?->receipt_sha256,
            'operation_write_count' => 0,
            'production_write_execution' => false,
            'preflight_state_sha256' => $this->stateSha(
                $authority,
                (int) $validated['actor_admin_user_id'],
                (string) $review->evidence_sha256,
                'apply',
                $validated,
            ),
            'publication_unchanged_receipt_sha256' => $this->publicationReceiptSha($authority),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function apply(string $expectedPackagePath, array $options): array
    {
        [$authority, $review, $validated] = $this->operationAuthority(
            $expectedPackagePath,
            $options,
        );

        $operation = DB::transaction(function () use (
            $expectedPackagePath,
            $options,
            $validated,
        ): CareerSearchEntryQualityBatchOperation {
            [$lockedAuthority, $lockedReview] = $this->operationAuthority(
                $expectedPackagePath,
                $options,
                true,
            );
            $existingActive = $this->activeApply($lockedAuthority, $lockedReview, true);
            if ($existingActive instanceof CareerSearchEntryQualityBatchOperation
                && (string) $existingActive->operation_id !== $validated['operation_id']) {
                throw new RuntimeException('An exact Career batch apply is already active under another operation ID.');
            }

            $receipt = $this->canonicalOperationReceipt(
                self::OPERATION_APPLY,
                $lockedAuthority,
                $lockedReview,
                $validated,
                null,
            );
            $model = CareerSearchEntryQualityBatchOperation::query()->createOrFirst(
                [
                    'operation_id' => $validated['operation_id'],
                    'operation_type' => self::OPERATION_APPLY,
                ],
                [
                    ...$this->operationColumns(
                        self::OPERATION_APPLY,
                        $lockedAuthority,
                        $lockedReview,
                        $validated,
                        null,
                    ),
                    'receipt_sha256' => $receipt['receipt_sha256'],
                    'canonical_receipt_json' => $receipt,
                ],
            );
            $this->assertOperationMatches($model, $receipt);

            return $model;
        }, 3);
        $this->assertOperationMatches($operation, $operation->canonical_receipt_json);

        return [
            ...$this->safeAuthority($authority),
            ...$validated,
            'status' => $operation->wasRecentlyCreated
                ? 'PASS_APPLY_COMMITTED'
                : 'PASS_APPLY_ALREADY_COMMITTED',
            'review_state' => 'approved_all_exact',
            'review_attestation_id' => (int) $review->id,
            'review_evidence_sha256' => (string) $review->evidence_sha256,
            'operation_receipt_sha256' => (string) $operation->receipt_sha256,
            'rollback_authorization_sha256' => $this->rollbackAuthorizationSha($operation),
            'operation_write_count' => $operation->wasRecentlyCreated ? 1 : 0,
            'production_write_execution' => true,
            'publication_unchanged_receipt_sha256' => $this->publicationReceiptSha($authority),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function readback(string $expectedPackagePath, array $options): array
    {
        [$authority, $review, $validated] = $this->operationAuthority(
            $expectedPackagePath,
            $options,
        );
        $active = $this->activeApply($authority, $review);
        if (! $active instanceof CareerSearchEntryQualityBatchOperation) {
            throw new RuntimeException('Career batch apply receipt is not active during readback.');
        }
        $this->assertOperationMatches($active, $active->canonical_receipt_json);
        $projectionReadback = $this->projectionReadback($authority);

        return [
            ...$this->safeAuthority($authority),
            ...$validated,
            'status' => 'PASS_APPLY_READBACK',
            'review_state' => 'approved_all_exact',
            'review_attestation_id' => (int) $review->id,
            'review_evidence_sha256' => (string) $review->evidence_sha256,
            'operation_receipt_sha256' => (string) $active->receipt_sha256,
            'rollback_authorization_sha256' => $this->rollbackAuthorizationSha($active),
            'search_entry_tier_readback' => 'exact_50_eligible',
            ...$projectionReadback,
            'operation_write_count' => 0,
            'production_write_execution' => false,
            'publication_unchanged_receipt_sha256' => $this->publicationReceiptSha($authority),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function rollback(string $expectedPackagePath, array $options): array
    {
        [$authority, $review, $validated] = $this->operationAuthority(
            $expectedPackagePath,
            $options,
        );
        $expectedApplyReceipt = $this->requiredHash(
            $options['expected_apply_receipt_sha256'] ?? null,
            'expected apply receipt',
        );
        $apply = $this->activeApply($authority, $review);
        if (! $apply instanceof CareerSearchEntryQualityBatchOperation
            || ! hash_equals($expectedApplyReceipt, (string) $apply->receipt_sha256)
            || ! hash_equals(
                $this->requiredHash(
                    $options['expected_rollback_authorization_sha256'] ?? null,
                    'expected rollback authorization',
                ),
                $this->rollbackAuthorizationSha($apply),
            )) {
            throw new RuntimeException('Exact active apply or rollback authorization does not match.');
        }

        $rollback = DB::transaction(function () use (
            $authority,
            $review,
            $validated,
            $apply,
        ): CareerSearchEntryQualityBatchOperation {
            $lockedApply = CareerSearchEntryQualityBatchOperation::query()
                ->whereKey($apply->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedApply instanceof CareerSearchEntryQualityBatchOperation
                || $this->hasRollback($lockedApply, true)) {
                throw new RuntimeException('Exact Career batch apply is no longer rollback-eligible.');
            }
            $receipt = $this->canonicalOperationReceipt(
                self::OPERATION_ROLLBACK,
                $authority,
                $review,
                $validated,
                (string) $lockedApply->receipt_sha256,
            );
            $model = CareerSearchEntryQualityBatchOperation::query()->createOrFirst(
                [
                    'operation_id' => $validated['operation_id'],
                    'operation_type' => self::OPERATION_ROLLBACK,
                ],
                [
                    ...$this->operationColumns(
                        self::OPERATION_ROLLBACK,
                        $authority,
                        $review,
                        $validated,
                        (string) $lockedApply->receipt_sha256,
                    ),
                    'receipt_sha256' => $receipt['receipt_sha256'],
                    'canonical_receipt_json' => $receipt,
                ],
            );
            $this->assertOperationMatches($model, $receipt);

            return $model;
        }, 3);

        return [
            ...$this->safeAuthority($authority),
            ...$validated,
            'status' => $rollback->wasRecentlyCreated
                ? 'PASS_ROLLBACK_COMMITTED'
                : 'PASS_ROLLBACK_ALREADY_COMMITTED',
            'apply_receipt_sha256' => (string) $apply->receipt_sha256,
            'rollback_receipt_sha256' => (string) $rollback->receipt_sha256,
            'search_entry_tier_readback' => CareerSearchEntryTierResolver::TIER_INELIGIBLE,
            'operation_write_count' => $rollback->wasRecentlyCreated ? 1 : 0,
            'production_write_execution' => true,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{0:array<string,mixed>,1:ReviewAttestation,2:array<string,mixed>}
     */
    private function operationAuthority(
        string $expectedPackagePath,
        array $options,
        bool $lockReview = false,
    ): array {
        $authority = $this->authority($expectedPackagePath);
        $validated = $this->validatedOperationOptions($options);
        $review = $this->approvedReview($authority, $lockReview);
        if (! $review instanceof ReviewAttestation
            || ! hash_equals(
                (string) $review->evidence_sha256,
                $this->requiredHash(
                    $options['expected_review_evidence_sha256'] ?? null,
                    'expected review evidence',
                ),
            )) {
            throw new RuntimeException('Exact approved-all review evidence is missing or drifted.');
        }

        return [$authority, $review, $validated];
    }

    /** @return array<string,mixed> */
    private function authority(string $expectedPackagePath): array
    {
        $expected = $this->readPackage($expectedPackagePath);
        $package = $this->planner->verify($expected);
        if (($package['candidate_count'] ?? null) !== self::EXPECTED_CANDIDATE_COUNT
            || ($package['bilingual_url_count'] ?? null) !== self::EXPECTED_BILINGUAL_URL_COUNT
            || ($package['target_count'] ?? null) !== self::EXPECTED_REVIEW_TARGET_COUNT
            || count($package['slugs'] ?? []) !== self::EXPECTED_CANDIDATE_COUNT
            || count($package['canonical_urls'] ?? []) !== self::EXPECTED_BILINGUAL_URL_COUNT) {
            throw new RuntimeException('Career batch exact 50/100/300 boundary failed closed.');
        }

        $targets = [];
        $tracks = [];
        foreach ($package['candidates'] as $candidate) {
            if (! is_array($candidate)
                || count($candidate['review_targets'] ?? []) !== 6
                || ! in_array($candidate['publish_track'] ?? null, ['stable', 'candidate'], true)
                || ($candidate['content_quality_tier'] ?? null)
                    !== CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE) {
                throw new RuntimeException('Career batch candidate authority failed closed.');
            }
            $targets = [...$targets, ...$candidate['review_targets']];
            $tracks[(string) $candidate['canonical_slug']] = (string) $candidate['publish_track'];
        }
        usort($targets, static fn (array $left, array $right): int => strcmp(
            (string) $left['identity'],
            (string) $right['identity'],
        ));
        $reviewPackage = app(CareerPilotReviewEvidenceBridge::class)->buildPackage(
            $package['slugs'],
        );
        foreach ([
            'scope_identity' => 'review_scope_identity',
            'target_count' => 'target_count',
            'target_set_sha256' => 'target_set_sha256',
            'package_sha256' => 'package_sha256',
            'targets' => null,
        ] as $reviewField => $qualityField) {
            $expectedValue = $qualityField === null ? $targets : $package[$qualityField];
            if ($reviewPackage[$reviewField] !== $expectedValue) {
                throw new RuntimeException('Career batch review authority drifted from the exact quality package.');
            }
        }

        return [
            ...$package,
            'review_package_sha256' => (string) $package['package_sha256'],
            'review_targets' => $targets,
            'publish_track_by_slug' => $tracks,
        ];
    }

    /** @param array<string,mixed> $authority */
    private function approvedReview(array $authority, bool $lock = false): ?ReviewAttestation
    {
        $review = $this->reviews->approvedAllEvidence(
            CareerPilotReviewEvidenceBridge::SURFACE_ID,
            $authority['review_targets'],
            (string) $authority['review_package_sha256'],
            CareerPilotReviewEvidenceBridge::SCOPE_TYPE,
            (string) $authority['review_scope_identity'],
        );
        if ($lock && $review instanceof ReviewAttestation) {
            return ReviewAttestation::query()->whereKey($review->id)->lockForUpdate()->first();
        }

        return $review;
    }

    /**
     * @param  array<string,mixed>  $authority
     */
    private function activeApply(
        array $authority,
        ReviewAttestation $review,
        bool $lock = false,
    ): ?CareerSearchEntryQualityBatchOperation {
        $query = CareerSearchEntryQualityBatchOperation::query()
            ->where('operation_type', self::OPERATION_APPLY)
            ->where('review_attestation_id', $review->id)
            ->where('review_evidence_sha256', (string) $review->evidence_sha256)
            ->where('quality_package_sha256', (string) $authority['quality_package_sha256'])
            ->where('review_package_sha256', (string) $authority['review_package_sha256'])
            ->where('target_set_sha256', (string) $authority['target_set_sha256'])
            ->latest('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $apply = $query->first();
        if (! $apply instanceof CareerSearchEntryQualityBatchOperation
            || $this->hasRollback($apply, $lock)) {
            return null;
        }
        $this->assertOperationMatches($apply, $apply->canonical_receipt_json);

        return $apply;
    }

    private function hasRollback(
        CareerSearchEntryQualityBatchOperation $apply,
        bool $lock = false,
    ): bool {
        $query = CareerSearchEntryQualityBatchOperation::query()
            ->where('operation_type', self::OPERATION_ROLLBACK)
            ->where('apply_receipt_sha256', (string) $apply->receipt_sha256);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function validatedOperationOptions(array $options): array
    {
        $activeReleaseSha = strtolower(trim((string) ($options['active_release_sha'] ?? '')));
        $activeReleaseName = trim((string) ($options['active_release_name'] ?? ''));
        $operationId = trim((string) ($options['operation_id'] ?? ''));
        $rollbackIdentifier = trim((string) ($options['rollback_identifier'] ?? ''));
        $actorAdminUserId = filter_var(
            $options['actor_admin_user_id'] ?? null,
            FILTER_VALIDATE_INT,
        );
        if (preg_match('/^[0-9a-f]{40}$/', $activeReleaseSha) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $activeReleaseName) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $operationId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/', $rollbackIdentifier) !== 1
            || ! is_int($actorAdminUserId)
            || $actorAdminUserId <= 0) {
            throw new RuntimeException('Career batch operation identity is invalid.');
        }
        $this->assertActor($actorAdminUserId);

        return [
            'active_release_sha' => $activeReleaseSha,
            'active_release_name' => $activeReleaseName,
            'operation_id' => $operationId,
            'rollback_identifier' => $rollbackIdentifier,
            'actor_admin_user_id' => $actorAdminUserId,
        ];
    }

    private function assertActor(int $actorAdminUserId): void
    {
        if (! $this->reviews->isConfiguredSoloOwner($actorAdminUserId)) {
            throw new RuntimeException('Career batch actor is not the configured solo owner.');
        }
    }

    /** @return array<string,mixed> */
    private function readPackage(string $path): array
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Expected Career batch package is missing or unreadable.');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Expected Career batch package JSON is invalid.');
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $authority
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    private function canonicalOperationReceipt(
        string $operationType,
        array $authority,
        ReviewAttestation $review,
        array $validated,
        ?string $applyReceiptSha256,
    ): array {
        $receipt = [
            'schema_version' => self::OPERATION_SCHEMA_VERSION,
            'task_id' => CareerSearchEntryQualityBatchManifestReader::TASK_ID,
            'operation_id' => $validated['operation_id'],
            'operation_type' => $operationType,
            'active_release_sha' => $validated['active_release_sha'],
            'active_release_name' => $validated['active_release_name'],
            'quality_package_sha256' => $authority['quality_package_sha256'],
            'review_package_sha256' => $authority['review_package_sha256'],
            'target_set_sha256' => $authority['target_set_sha256'],
            'candidate_count' => self::EXPECTED_CANDIDATE_COUNT,
            'bilingual_url_count' => self::EXPECTED_BILINGUAL_URL_COUNT,
            'review_attestation_id' => (int) $review->id,
            'review_evidence_sha256' => (string) $review->evidence_sha256,
            'actor_admin_user_id' => $validated['actor_admin_user_id'],
            'rollback_identifier' => $validated['rollback_identifier'],
            'apply_receipt_sha256' => $applyReceiptSha256,
            'allowed_write_boundary' => $operationType === self::OPERATION_APPLY
                ? 'one_append_only_apply_receipt'
                : 'one_append_only_rollback_receipt',
            'prohibited_operations' => array_keys($this->negativeGuarantees()),
        ];
        $receipt['receipt_sha256'] = hash('sha256', $this->canonicalizer->encode($receipt));

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $authority
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    private function operationColumns(
        string $operationType,
        array $authority,
        ReviewAttestation $review,
        array $validated,
        ?string $applyReceiptSha256,
    ): array {
        return [
            'schema_version' => self::OPERATION_SCHEMA_VERSION,
            'task_id' => CareerSearchEntryQualityBatchManifestReader::TASK_ID,
            'active_release_sha' => $validated['active_release_sha'],
            'active_release_name' => $validated['active_release_name'],
            'quality_package_sha256' => $authority['quality_package_sha256'],
            'review_package_sha256' => $authority['review_package_sha256'],
            'target_set_sha256' => $authority['target_set_sha256'],
            'candidate_count' => self::EXPECTED_CANDIDATE_COUNT,
            'bilingual_url_count' => self::EXPECTED_BILINGUAL_URL_COUNT,
            'review_attestation_id' => (int) $review->id,
            'review_evidence_sha256' => (string) $review->evidence_sha256,
            'actor_admin_user_id' => $validated['actor_admin_user_id'],
            'rollback_identifier' => $validated['rollback_identifier'],
            'apply_receipt_sha256' => $applyReceiptSha256,
        ];
    }

    /** @param array<string,mixed>|null $expected */
    private function assertOperationMatches(
        CareerSearchEntryQualityBatchOperation $operation,
        ?array $expected,
    ): void {
        if (! is_array($expected)) {
            throw new RuntimeException('Career batch operation receipt is missing.');
        }
        $receipt = $expected;
        $claimedSha = $receipt['receipt_sha256'] ?? null;
        unset($receipt['receipt_sha256']);
        $computedSha = hash('sha256', $this->canonicalizer->encode($receipt));
        if (! is_string($claimedSha)
            || ! hash_equals($computedSha, $claimedSha)
            || ! hash_equals((string) $operation->receipt_sha256, $claimedSha)) {
            throw new RuntimeException('Career batch operation receipt authentication failed.');
        }
        foreach ($this->operationColumns(
            (string) $operation->operation_type,
            [
                'quality_package_sha256' => $operation->quality_package_sha256,
                'review_package_sha256' => $operation->review_package_sha256,
                'target_set_sha256' => $operation->target_set_sha256,
            ],
            $operation->reviewAttestation()->firstOrFail(),
            [
                'active_release_sha' => $operation->active_release_sha,
                'active_release_name' => $operation->active_release_name,
                'operation_id' => $operation->operation_id,
                'rollback_identifier' => $operation->rollback_identifier,
                'actor_admin_user_id' => $operation->actor_admin_user_id,
            ],
            $operation->apply_receipt_sha256,
        ) as $field => $value) {
            if ((string) ($operation->{$field} ?? '') !== (string) ($value ?? '')) {
                throw new RuntimeException('Career batch operation receipt column drift detected.');
            }
        }
    }

    private function rollbackAuthorizationSha(
        CareerSearchEntryQualityBatchOperation $apply,
    ): string {
        return hash('sha256', $this->canonicalizer->encode([
            'schema_version' => self::CONTROL_SCHEMA_VERSION,
            'action' => 'rollback_exact_career_search_entry_batch_apply',
            'apply_receipt_sha256' => (string) $apply->receipt_sha256,
            'rollback_identifier' => (string) $apply->rollback_identifier,
            'active_release_sha' => (string) $apply->active_release_sha,
        ]));
    }

    /** @param array<string,mixed> $authority @return array<string,mixed> */
    private function safeAuthority(array $authority): array
    {
        return [
            'control_schema_version' => self::CONTROL_SCHEMA_VERSION,
            'task_id' => CareerSearchEntryQualityBatchManifestReader::TASK_ID,
            'quality_package_sha256' => $authority['quality_package_sha256'],
            'review_package_sha256' => $authority['review_package_sha256'],
            'target_set_sha256' => $authority['target_set_sha256'],
            'candidate_count' => self::EXPECTED_CANDIDATE_COUNT,
            'bilingual_url_count' => self::EXPECTED_BILINGUAL_URL_COUNT,
            'review_target_count' => self::EXPECTED_REVIEW_TARGET_COUNT,
            'held_slug_count' => 0,
            'unknown_target_count' => 0,
            'target_drift_count' => 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $authority
     * @param  array<string,mixed>  $extra
     */
    private function stateSha(
        array $authority,
        int $actorAdminUserId,
        mixed $reviewEvidenceSha256,
        string $phase,
        array $extra = [],
    ): string {
        return hash('sha256', $this->canonicalizer->encode([
            'control_schema_version' => self::CONTROL_SCHEMA_VERSION,
            'phase' => $phase,
            'quality_package_sha256' => $authority['quality_package_sha256'],
            'review_package_sha256' => $authority['review_package_sha256'],
            'target_set_sha256' => $authority['target_set_sha256'],
            'candidate_count' => self::EXPECTED_CANDIDATE_COUNT,
            'bilingual_url_count' => self::EXPECTED_BILINGUAL_URL_COUNT,
            'review_target_count' => self::EXPECTED_REVIEW_TARGET_COUNT,
            'actor_admin_user_id' => $actorAdminUserId,
            'review_evidence_sha256' => $reviewEvidenceSha256,
            'operation' => $extra,
        ]));
    }

    /** @param array<string,mixed> $authority */
    private function publicationReceiptSha(array $authority): string
    {
        return hash('sha256', $this->canonicalizer->encode([
            'schema_version' => self::CONTROL_SCHEMA_VERSION,
            'state' => 'cms_publication_unchanged',
            'quality_package_sha256' => $authority['quality_package_sha256'],
            'current_content_and_seo' => array_map(
                static fn (array $candidate): array => [
                    'canonical_slug' => $candidate['canonical_slug'],
                    'publish_track' => $candidate['publish_track'],
                    'current_content_sha256_by_locale' => $candidate['current_content_sha256_by_locale'],
                    'current_seo_sha256_by_locale' => $candidate['current_seo_sha256_by_locale'],
                ],
                $authority['candidates'],
            ),
            'cms_writes' => 0,
            'publication_writes' => 0,
            'indexability_writes' => 0,
        ]));
    }

    /**
     * @param  array<string,mixed>  $authority
     * @return array<string,int>
     */
    private function projectionReadback(array $authority): array
    {
        $bridge = app(CareerPilotReviewEvidenceBridge::class);
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $detailCount = 0;
        $indexCount = 0;
        foreach ($authority['candidates'] as $candidate) {
            $slug = (string) $candidate['canonical_slug'];
            $expectedTier = (string) $candidate['target_search_entry_tier'];
            foreach (['en', 'zh-CN'] as $locale) {
                $payload = $bridge->projectDetailPayload(
                    $slug,
                    $cache->jobDetailPayload($slug, $locale),
                );
                $canonicalPrefix = $locale === 'en' ? '/en/' : '/zh/';
                if (($payload['search_entry_tier'] ?? null) !== $expectedTier
                    || data_get($payload, 'trust_manifest.review_state') !== 'approved'
                    || data_get($payload, 'seo_contract.index_eligible') !== true
                    || ! str_starts_with(
                        (string) data_get($payload, 'seo_contract.canonical_path', ''),
                        $canonicalPrefix,
                    )
                    || ! $this->robotsAllowIndex(data_get($payload, 'seo_contract.robots_policy'))) {
                    throw new RuntimeException('Career batch detail projection readback failed closed.');
                }
                $detailCount++;
            }
        }
        foreach (['en', 'zh-CN'] as $locale) {
            $index = $bridge->projectJobIndexPayload($cache->jobIndexPayload($locale), $locale);
            $items = is_array($index['items'] ?? null) ? $index['items'] : [];
            $bySlug = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $bySlug[(string) data_get($item, 'identity.canonical_slug', '')] = $item;
                }
            }
            foreach ($authority['candidates'] as $candidate) {
                $item = $bySlug[(string) $candidate['canonical_slug']] ?? null;
                if (! is_array($item)
                    || ($item['search_entry_tier'] ?? null)
                        !== $candidate['target_search_entry_tier']) {
                    throw new RuntimeException('Career batch index projection readback failed closed.');
                }
                $indexCount++;
            }
        }

        return [
            'cache_backed_detail_readback_count' => $detailCount,
            'cache_backed_index_readback_count' => $indexCount,
            'canonical_readback_count' => $detailCount,
            'robots_readback_count' => $detailCount,
            'indexability_readback_count' => $detailCount,
        ];
    }

    private function robotsAllowIndex(mixed $policy): bool
    {
        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', is_string($policy) ? $policy : ''),
        )));

        return in_array('index', $tokens, true)
            && in_array('follow', $tokens, true)
            && ! in_array('noindex', $tokens, true)
            && ! in_array('nofollow', $tokens, true);
    }

    private function requiredHash(mixed $value, string $label): string
    {
        $value = strtolower(trim((string) $value));
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new RuntimeException('Career batch '.$label.' SHA-256 is invalid.');
        }

        return $value;
    }

    /** @return array<string,int> */
    private function negativeGuarantees(): array
    {
        return [
            'cms_writes' => 0,
            'cache_writes' => 0,
            'queue_dispatches' => 0,
            'publication_writes' => 0,
            'indexability_writes' => 0,
            'sitemap_writes' => 0,
            'llms_writes' => 0,
            'search_channel_actions' => 0,
            'url_submissions' => 0,
            'deploys' => 0,
            'held_slug_releases' => 0,
            'non_target_writes' => 0,
        ];
    }
}
