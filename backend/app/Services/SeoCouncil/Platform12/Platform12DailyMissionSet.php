<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Fixed backend routing; neither callers nor Catalog entries choose PHP classes. */
final class Platform12DailyMissionSet
{
    public const IDS = [
        'seo.platform12.daily_gsc_core_runtime',
        'seo.platform12.daily_url_truth_reconciliation',
        'seo.platform12.daily_private_policy_evidence_drift',
    ];

    public function __construct(private readonly Platform12ContractRegistry $contracts) {}

    public function missions(): array
    {
        $missions = [];
        foreach ($this->contracts->missionCatalog()['missions'] as $mission) {
            if (in_array($mission['mission_id'], self::IDS, true)) {
                if ($mission['cadence'] !== 'daily' || $mission['timezone'] !== 'Asia/Shanghai'
                    || array_sum($mission['budgets']) !== 0 || $mission['timeout_seconds'] !== 120
                    || $mission['max_attempts'] !== 2
                    || $mission['failure_policy'] !== [
                        'terminal_state' => 'HOLD', 'retry_strategy' => 'none',
                        'initial_backoff_seconds' => 0, 'max_backoff_seconds' => 0,
                    ]) {
                    throw new InvalidArgumentException('DAILY_MISSION_SCOPE_DRIFT');
                }
                $missions[] = $mission;
            }
        }
        if (array_column($missions, 'mission_id') !== self::IDS) {
            throw new InvalidArgumentException('DAILY_MISSION_SET_DRIFT');
        }

        return $missions;
    }

    /**
     * At most three slots per date. The caller persists missed dates as HOLD,
     * advancing its durable delivery cursor without replaying historical work.
     */
    public function slots(CarbonImmutable $date, CarbonImmutable $activatedAt, CarbonImmutable $now): array
    {
        $slots = [];
        foreach ($this->missions() as $mission) {
            $time = substr($mission['natural_slot'], strlen('daily:ALL:'));
            $scheduled = $date->setTimezone('Asia/Shanghai')->startOfDay()->setTimeFromTimeString($time);
            if ($scheduled->lt($activatedAt) || $scheduled->gt($now)) {
                continue;
            }
            $slots[] = [
                'mission_id' => $mission['mission_id'],
                'slot_key' => $mission['mission_id'].':'.$scheduled->format('Y-m-d'),
                'scheduled_for' => $scheduled->utc()->format('Y-m-d\TH:i:s\Z'),
                'trigger_mode' => $scheduled->format('Y-m-d') !== $now->setTimezone('Asia/Shanghai')->format('Y-m-d')
                    ? 'missed' : ($now->gte($scheduled->addMinute()) ? 'catch_up' : 'scheduled'),
            ];
        }

        return $slots;
    }

    public function nextRun(array $mission, CarbonImmutable $now): string
    {
        $slot = $now->setTimezone('Asia/Shanghai')->startOfDay()
            ->setTimeFromTimeString(substr($mission['natural_slot'], strlen('daily:ALL:')));

        return ($slot->lte($now) ? $slot->addDay() : $slot)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
