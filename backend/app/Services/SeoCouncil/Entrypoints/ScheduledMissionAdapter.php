<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Entrypoints;

use App\Services\SeoCouncil\MissionSubmissionService;
use App\Services\SeoCouncil\Platform12\Platform12FrozenMission;
use App\Services\SeoCouncil\SeoCouncilOrchestrator;

final class ScheduledMissionAdapter
{
    public function __construct(private readonly MissionSubmissionService $missions) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(array $input): array
    {
        return $this->missions->submit($input, 'scheduler');
    }

    /** Frozen internal request; calculation is intentionally not persisted here. */
    public function submitFrozen(Platform12FrozenMission $mission): array
    {
        return app(SeoCouncilOrchestrator::class)->run($mission->request, $mission);
    }
}
