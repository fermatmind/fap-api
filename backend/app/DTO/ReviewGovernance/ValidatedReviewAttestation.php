<?php

declare(strict_types=1);

namespace App\DTO\ReviewGovernance;

final readonly class ValidatedReviewAttestation
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{target_identity: string, reason: string}>  $exceptionsByTarget
     */
    public function __construct(
        public array $payload,
        public ReviewTargetSet $targetSet,
        public array $exceptionsByTarget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publicPreflight(): array
    {
        return [
            'status' => 'PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT',
            'schema_version' => $this->payload['schema_version'],
            'review_mode' => $this->payload['review_mode'],
            'review_source' => $this->payload['review_source'],
            'scope_type' => $this->payload['scope_type'],
            'scope_identity' => $this->payload['scope_identity'],
            'decision' => $this->payload['decision'],
            'target_count' => $this->targetSet->count(),
            'target_set_sha256' => $this->targetSet->sha256,
            'package_sha256' => $this->payload['package_sha256'],
            'exception_count' => count($this->exceptionsByTarget),
            'evidence_sha256' => $this->payload['evidence_sha256'],
            'database_writes' => 0,
            'production_execution_authorized' => false,
        ];
    }
}
