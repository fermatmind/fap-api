<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Contracts;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final readonly class MissionRequestData
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public array $payload,
        public string $callerType,
        public string $requestHash,
        public string $evidenceHash,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(
        array $input,
        string $callerType,
        CouncilContractValidator $validator,
        SeoRegistryHasher $hasher,
    ): self {
        $payload = $validator->missionRequest($input);

        return new self(
            $payload,
            $callerType,
            $hasher->hash($payload),
            $hasher->hash($payload['evidence_bundle_refs']),
        );
    }

    public function missionId(): string
    {
        return (string) $this->payload['mission_id'];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->payload['idempotency_key'];
    }
}
