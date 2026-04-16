<?php

declare(strict_types=1);

namespace App\Domain\Career\Operations;

final class CareerCrosswalkBacklogConvergenceProjectionService
{
    public const SNAPSHOT_FILENAME = 'career-crosswalk-backlog-convergence.json';

    public function __construct(
        private readonly CareerCrosswalkBacklogConvergenceService $convergenceService,
    ) {}

    /**
     * @return array{career-crosswalk-backlog-convergence.json: array<string, mixed>}
     */
    public function build(): array
    {
        return [
            self::SNAPSHOT_FILENAME => $this->convergenceService->build()->toArray(),
        ];
    }
}
