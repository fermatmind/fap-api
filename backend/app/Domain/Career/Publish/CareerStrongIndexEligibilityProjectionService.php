<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerStrongIndexEligibilityProjectionService
{
    public const SNAPSHOT_FILENAME = 'career-strong-index-eligibility.json';

    public function __construct(
        private readonly CareerStrongIndexEligibilityService $strongIndexEligibilityService,
    ) {}

    /**
     * @return array{career-strong-index-eligibility.json: array<string, mixed>}
     */
    public function build(): array
    {
        return [
            self::SNAPSHOT_FILENAME => $this->strongIndexEligibilityService->build()->toArray(),
        ];
    }
}
