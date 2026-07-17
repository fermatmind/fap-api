<?php

declare(strict_types=1);

namespace App\Services\ReviewGovernance;

final readonly class ReviewAttestationFingerprintBuilder
{
    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function evidenceSha256(array $payload): string
    {
        unset($payload['evidence_sha256']);
        if (isset($payload['exceptions']) && is_array($payload['exceptions']) && array_is_list($payload['exceptions'])) {
            usort($payload['exceptions'], static function (mixed $left, mixed $right): int {
                $leftIdentity = is_array($left) ? (string) ($left['target_identity'] ?? '') : '';
                $rightIdentity = is_array($right) ? (string) ($right['target_identity'] ?? '') : '';

                return $leftIdentity <=> $rightIdentity;
            });
        }

        return hash('sha256', $this->canonicalizer->encode($payload));
    }

    /**
     * @param  array<string, mixed>|null  $exception
     */
    public function targetEvidenceSha256(
        string $attestationEvidenceSha256,
        string $targetIdentity,
        string $targetSha256,
        string $targetDecision,
        ?array $exception,
    ): string {
        return hash('sha256', $this->canonicalizer->encode([
            'attestation_evidence_sha256' => $attestationEvidenceSha256,
            'exception' => $exception,
            'target_decision' => $targetDecision,
            'target_identity' => $targetIdentity,
            'target_sha256' => $targetSha256,
        ]));
    }
}
