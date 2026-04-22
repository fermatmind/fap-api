<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Rules;

use App\Services\BigFive\ReportEngine\Contracts\SynergyMatch;

final class MutexResolver
{
    /**
     * @param  list<SynergyMatch>  $matches
     * @return list<SynergyMatch>
     */
    public function resolve(array $matches, int $maxShow): array
    {
        if ($maxShow <= 0) {
            return [];
        }

        usort(
            $matches,
            static fn (SynergyMatch $left, SynergyMatch $right): int => $right->priorityWeight <=> $left->priorityWeight
        );

        $selected = [];
        $usedMutexGroups = [];
        $excluded = [];

        foreach ($matches as $match) {
            if (in_array($match->synergyId, $excluded, true)) {
                continue;
            }
            foreach ($match->mutualExcludes as $mutualExclude) {
                if (in_array($mutualExclude, array_map(static fn (SynergyMatch $selected): string => $selected->synergyId, $selected), true)) {
                    continue 2;
                }
            }
            if ($match->mutexGroup !== '' && in_array($match->mutexGroup, $usedMutexGroups, true)) {
                continue;
            }

            $selected[] = $match;
            if ($match->mutexGroup !== '') {
                $usedMutexGroups[] = $match->mutexGroup;
            }
            foreach ($match->mutualExcludes as $mutualExclude) {
                $excluded[] = $mutualExclude;
            }

            if (count($selected) >= $maxShow) {
                break;
            }
        }

        return $selected;
    }
}
