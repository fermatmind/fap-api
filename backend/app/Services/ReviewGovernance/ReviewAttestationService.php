<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

use App\DTO\ReviewGovernance\ReviewTarget;
use App\DTO\ReviewGovernance\ReviewTargetSet;
use App\DTO\ReviewGovernance\ValidatedReviewAttestation;
use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use Illuminate\Support\Facades\DB;

final readonly class ReviewAttestationService
{
    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
        private ReviewAttestationFingerprintBuilder $fingerprintBuilder,
        private ReviewAttestationValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $attestation
     * @param  list<array<string, mixed>|ReviewTarget>  $targets
     * @return array<string, mixed>
     */
    public function preflight(
        array $attestation,
        array $targets,
        ?string $expectedPackageSha256 = null,
    ): array {
        return $this->validate($attestation, $targets, $expectedPackageSha256)->publicPreflight();
    }

    /**
     * @param  array<string, mixed>  $attestation
     * @param  list<array<string, mixed>|ReviewTarget>  $targets
     */
    public function bind(
        array $attestation,
        array $targets,
        ?string $expectedPackageSha256 = null,
    ): ReviewAttestation {
        $validated = $this->validate($attestation, $targets, $expectedPackageSha256);

        return DB::transaction(function () use ($validated): ReviewAttestation {
            $record = ReviewAttestation::query()->createOrFirst(
                ['evidence_sha256' => $validated->payload['evidence_sha256']],
                ['schema_version' => $validated->payload['schema_version'],
                    'review_mode' => $validated->payload['review_mode'],
                    'review_source' => $validated->payload['review_source'],
                    'scope_type' => $validated->payload['scope_type'],
                    'scope_identity' => $validated->payload['scope_identity'],
                    'decision' => $validated->payload['decision'],
                    'target_count' => $validated->payload['target_count'],
                    'target_set_sha256' => $validated->payload['target_set_sha256'],
                    'package_sha256' => $validated->payload['package_sha256'],
                    'exceptions_json' => $validated->payload['exceptions'],
                    'statement_version' => $validated->payload['statement_version'],
                    'attested_by_admin_user_id' => $validated->payload['attested_by_admin_user_id'],
                    'attested_at' => $validated->payload['attested_at'],
                    'canonical_evidence_json' => $validated->payload],
            );
            if (! $record->wasRecentlyCreated) {
                $this->assertIdempotentReadback($record, $validated);

                return $record->load('targetEvidences');
            }

            foreach ($validated->targetSet->targets as $target) {
                $exception = $validated->exceptionsByTarget[$target->identity] ?? null;
                $targetDecision = $this->targetDecision((string) $validated->payload['decision'], $exception !== null);
                ReviewAttestationTargetEvidence::query()->create([
                    'review_attestation_id' => $record->id,
                    'target_identity' => $target->identity,
                    'target_sha256' => $target->sha256,
                    'target_decision' => $targetDecision,
                    'exception_json' => $exception,
                    'evidence_sha256' => $this->fingerprintBuilder->targetEvidenceSha256(
                        (string) $validated->payload['evidence_sha256'],
                        $target->identity,
                        $target->sha256,
                        $targetDecision,
                        $exception,
                    ),
                ]);
            }

            $record->load('targetEvidences');
            $this->assertIdempotentReadback($record, $validated);

            return $record;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attestation
     * @param  list<array<string, mixed>|ReviewTarget>  $targets
     */
    private function validate(
        array $attestation,
        array $targets,
        ?string $expectedPackageSha256,
    ): ValidatedReviewAttestation {
        $targetSet = ReviewTargetSet::fromArray($targets, $this->canonicalizer);

        return $this->validator->validate($attestation, $targetSet, $expectedPackageSha256);
    }

    private function targetDecision(string $decision, bool $exception): string
    {
        if ($decision === 'rejected') {
            return 'rejected';
        }

        return $exception ? 'excepted' : 'approved';
    }

    private function assertIdempotentReadback(
        ReviewAttestation $record,
        ValidatedReviewAttestation $validated,
    ): void {
        $record->loadMissing('targetEvidences');
        if ((int) $record->target_count !== $validated->targetSet->count()
            || ! hash_equals((string) $record->target_set_sha256, $validated->targetSet->sha256)
            || ! hash_equals((string) $record->evidence_sha256, (string) $validated->payload['evidence_sha256'])
            || $this->canonicalizer->encode($record->canonical_evidence_json) !== $this->canonicalizer->encode($validated->payload)
            || $record->targetEvidences->count() !== $validated->targetSet->count()) {
            throw new ReviewAttestationValidationException('Bound attestation readback does not match the exact target set.');
        }

        $actual = $record->targetEvidences
            ->mapWithKeys(static fn (ReviewAttestationTargetEvidence $evidence): array => [
                (string) $evidence->target_identity => [
                    'target_sha256' => (string) $evidence->target_sha256,
                    'target_decision' => (string) $evidence->target_decision,
                    'exception' => $evidence->exception_json,
                    'evidence_sha256' => (string) $evidence->evidence_sha256,
                ],
            ])
            ->all();
        $expected = [];
        foreach ($validated->targetSet->targets as $target) {
            $exception = $validated->exceptionsByTarget[$target->identity] ?? null;
            $targetDecision = $this->targetDecision((string) $validated->payload['decision'], $exception !== null);
            $expected[$target->identity] = [
                'target_sha256' => $target->sha256,
                'target_decision' => $targetDecision,
                'exception' => $exception,
                'evidence_sha256' => $this->fingerprintBuilder->targetEvidenceSha256(
                    (string) $validated->payload['evidence_sha256'],
                    $target->identity,
                    $target->sha256,
                    $targetDecision,
                    $exception,
                ),
            ];
        }
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ReviewAttestationValidationException('Bound target evidence contains missing, extra, or drifted targets.');
        }
    }
}
