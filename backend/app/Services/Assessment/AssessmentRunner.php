<?php

declare(strict_types=1);

namespace App\Services\Assessment;

final class AssessmentRunner
{
    public function __construct(
        private AssessmentEngine $engine,
    ) {
    }

    public function run(
        string $scaleCode,
        int $orgId,
        string $packId,
        string $dirVersion,
        array $answers,
        array $scoreContext
    ): array {
        return $this->engine->score([
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
        ], $answers, $scoreContext);
    }
}
