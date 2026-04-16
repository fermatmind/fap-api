<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerLaunchGovernanceClosureProjectionService
{
    public const SNAPSHOT_FILENAME = 'career-launch-governance-closure.json';

    public function __construct(
        private readonly CareerLaunchGovernanceClosureService $closureService,
    ) {}

    /**
     * @return array{career-launch-governance-closure.json: array<string, mixed>}
     */
    public function build(): array
    {
        return [
            self::SNAPSHOT_FILENAME => $this->closureService->build()->toArray(),
        ];
    }
}
