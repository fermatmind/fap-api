<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final readonly class Platform11MissionRequestData
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
        Platform11MissionValidator $validator,
        SeoRegistryHasher $hasher,
    ): self {
        $payload = $validator->validate($input);

        return new self($payload, $callerType, $hasher->hash($payload), $hasher->hash($payload['evidence_bundle_refs']));
    }
}
