<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyGscCoreRuntimeEvaluator;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailySecurityDriftEvaluator;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyUrlTruthEvaluator;

/** Internal Orchestrator dependency, never a scheduler or API entrypoint. */
final readonly class Platform12DailyEvaluator
{
    public function __construct(
        private Platform12DailyGscCoreRuntimeEvaluator $gsc,
        private Platform12DailyUrlTruthEvaluator $truth,
        private Platform12DailySecurityDriftEvaluator $security,
    ) {}

    public function evaluate(Platform12FrozenMission $mission): array
    {
        $evidence = $mission->envelope['evidence']['input'];
        if (isset($evidence['drift']) && is_array($evidence['drift'])) {
            $evidence['drift'] = array_replace(array_fill_keys(['role', 'binding', 'policy', 'tool', 'schema', 'prompt'], 'UNAVAILABLE'), $evidence['drift']);
        }

        return match ($mission->envelope['slot']['mission_id']) {
            Platform12DailyMissionSet::IDS[0] => $this->gsc->evaluate($evidence),
            Platform12DailyMissionSet::IDS[1] => $this->truth->evaluate($evidence),
            Platform12DailyMissionSet::IDS[2] => $this->security->evaluate($evidence),
        };
    }
}
