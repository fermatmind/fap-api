<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

use App\DTO\ReviewGovernance\ReviewTargetSet;
use App\DTO\ReviewGovernance\ValidatedReviewAttestation;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class ReviewAttestationValidator
{
    public function __construct(
        private ReviewAttestationFingerprintBuilder $fingerprintBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(
        array $payload,
        ReviewTargetSet $targetSet,
        ?string $expectedPackageSha256 = null,
    ): ValidatedReviewAttestation {
        $this->assertExactFields($payload);
        $this->assertConfiguredContract($payload);
        $this->assertScope($payload);
        $this->assertTargetSet($payload, $targetSet);
        $this->assertPackage($payload, $expectedPackageSha256);
        $exceptionsByTarget = $this->exceptionsByTarget($payload, $targetSet);
        $this->assertDecision($payload, $exceptionsByTarget, $targetSet->count());
        $this->assertTimestamp($payload);
        $this->assertEvidenceFingerprint($payload);
        $payload['exceptions'] = array_values($exceptionsByTarget);

        return new ValidatedReviewAttestation($payload, $targetSet, $exceptionsByTarget);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertExactFields(array $payload): void
    {
        $missing = array_values(array_diff(ReviewAttestationSchema::REQUIRED_FIELDS, array_keys($payload)));
        $extra = array_values(array_diff(array_keys($payload), ReviewAttestationSchema::REQUIRED_FIELDS));
        if ($missing !== [] || $extra !== []) {
            throw new ReviewAttestationValidationException(sprintf(
                'Attestation fields do not match the v1 schema; missing=[%s] extra=[%s].',
                implode(',', $missing),
                implode(',', $extra),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertConfiguredContract(array $payload): void
    {
        if (config('review_governance.mode') !== ReviewAttestationSchema::REVIEW_MODE
            || $payload['review_mode'] !== ReviewAttestationSchema::REVIEW_MODE
            || $payload['schema_version'] !== config('review_governance.attestation.schema_version')
            || $payload['review_source'] !== config('review_governance.attestation.review_source')
            || $payload['statement_version'] !== config('review_governance.attestation.statement_version')) {
            throw new ReviewAttestationValidationException('Attestation governance mode, schema, source, or statement version is not configured for solo-owner review.');
        }

        $configuredAdminUserId = (int) config('review_governance.solo_owner_admin_user_id');
        if ($configuredAdminUserId <= 0 || $payload['attested_by_admin_user_id'] !== $configuredAdminUserId) {
            throw new ReviewAttestationValidationException('Attestation actor is not the configured solo owner.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertScope(array $payload): void
    {
        foreach (['scope_type', 'scope_identity'] as $field) {
            $value = $payload[$field];
            if (! is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 191) {
                throw new ReviewAttestationValidationException('Attestation '.$field.' must be a non-empty trimmed string.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertTargetSet(array $payload, ReviewTargetSet $targetSet): void
    {
        if (! is_int($payload['target_count']) || $payload['target_count'] !== $targetSet->count()) {
            throw new ReviewAttestationValidationException('Attestation target count drifted from the exact computed target set.');
        }
        if (! is_string($payload['target_set_sha256'])
            || preg_match('/^[0-9a-f]{64}$/', $payload['target_set_sha256']) !== 1
            || ! hash_equals($targetSet->sha256, $payload['target_set_sha256'])) {
            throw new ReviewAttestationValidationException('Attestation target set SHA-256 drifted from the exact computed target set.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPackage(array $payload, ?string $expectedPackageSha256): void
    {
        $packageSha256 = $payload['package_sha256'];
        if ($packageSha256 !== null
            && (! is_string($packageSha256) || preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1)) {
            throw new ReviewAttestationValidationException('Attestation package SHA-256 must be null or an exact lowercase hash.');
        }
        if ($expectedPackageSha256 !== null) {
            if (preg_match('/^[0-9a-f]{64}$/', $expectedPackageSha256) !== 1
                || ! is_string($packageSha256)
                || ! hash_equals($expectedPackageSha256, $packageSha256)) {
                throw new ReviewAttestationValidationException('Attestation package SHA-256 drifted from the exact package.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{target_identity: string, reason: string}>
     */
    private function exceptionsByTarget(array $payload, ReviewTargetSet $targetSet): array
    {
        if (! is_array($payload['exceptions']) || ! array_is_list($payload['exceptions'])) {
            throw new ReviewAttestationValidationException('Attestation exceptions must be a list.');
        }

        $known = array_fill_keys($targetSet->identities(), true);
        $normalized = [];
        foreach ($payload['exceptions'] as $index => $exception) {
            if (! is_array($exception)
                || array_values(array_diff(array_keys($exception), ['target_identity', 'reason'])) !== []
                || array_values(array_diff(['target_identity', 'reason'], array_keys($exception))) !== []) {
                throw new ReviewAttestationValidationException('Attestation exception at index '.$index.' has an invalid schema.');
            }
            $identity = $exception['target_identity'];
            $reason = $exception['reason'];
            if (! is_string($identity) || ! isset($known[$identity]) || isset($normalized[$identity])) {
                throw new ReviewAttestationValidationException('Attestation exception target is unknown or duplicated.');
            }
            if (! is_string($reason) || trim($reason) !== $reason || $reason === '' || strlen($reason) > 500) {
                throw new ReviewAttestationValidationException('Attestation exception reason must be a non-empty private note of at most 500 characters.');
            }
            $normalized[$identity] = [
                'target_identity' => $identity,
                'reason' => $reason,
            ];
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{target_identity: string, reason: string}>  $exceptionsByTarget
     */
    private function assertDecision(array $payload, array $exceptionsByTarget, int $targetCount): void
    {
        $decision = $payload['decision'];
        if (! is_string($decision) || ! in_array($decision, ReviewAttestationSchema::DECISIONS, true)) {
            throw new ReviewAttestationValidationException('Attestation decision is invalid.');
        }
        if (($decision === 'approved_all' || $decision === 'rejected') && $exceptionsByTarget !== []) {
            throw new ReviewAttestationValidationException('Approved-all and rejected attestations cannot contain exceptions.');
        }
        if ($decision === 'approved_with_exceptions'
            && ($exceptionsByTarget === [] || count($exceptionsByTarget) >= $targetCount)) {
            throw new ReviewAttestationValidationException('Approved-with-exceptions must identify a non-empty proper subset of exact targets.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertTimestamp(array $payload): void
    {
        if (! is_string($payload['attested_at'])
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['attested_at']) !== 1) {
            throw new ReviewAttestationValidationException('Attestation timestamp must be an exact UTC second timestamp.');
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $payload['attested_at'], 'UTC');
            if ($parsed->format('Y-m-d\TH:i:s\Z') !== $payload['attested_at']) {
                throw new ReviewAttestationValidationException('Attestation timestamp is not a valid UTC timestamp.');
            }
        } catch (Throwable) {
            throw new ReviewAttestationValidationException('Attestation timestamp is not a valid UTC timestamp.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertEvidenceFingerprint(array $payload): void
    {
        $evidenceSha256 = $payload['evidence_sha256'];
        if (! is_string($evidenceSha256)
            || preg_match('/^[0-9a-f]{64}$/', $evidenceSha256) !== 1
            || ! hash_equals($this->fingerprintBuilder->evidenceSha256($payload), $evidenceSha256)) {
            throw new ReviewAttestationValidationException('Attestation canonical evidence SHA-256 is invalid or drifted.');
        }
    }
}
