<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Entrypoints;

use App\Services\SeoCouncil\MissionSubmissionService;

final class CliMissionAdapter
{
    public function __construct(private readonly MissionSubmissionService $missions) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(array $input): array
    {
        return $this->missions->submit($input, 'cli');
    }
}
