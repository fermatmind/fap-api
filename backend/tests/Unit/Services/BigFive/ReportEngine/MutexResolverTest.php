<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\SynergyMatch;
use App\Services\BigFive\ReportEngine\Rules\MutexResolver;
use Tests\TestCase;

final class MutexResolverTest extends TestCase
{
    public function test_same_stress_activation_group_keeps_only_highest_weight_match(): void
    {
        $selected = (new MutexResolver)->resolve([
            $this->match('o_high_x_n_high', 70, 'stress_activation', ['n_high_x_e_low']),
            $this->match('n_high_x_e_low', 78, 'stress_activation', ['o_high_x_n_high']),
            $this->match('o_high_x_c_low', 81, 'execution_friction', []),
        ], 2);

        $this->assertSame(['o_high_x_c_low', 'n_high_x_e_low'], array_map(static fn (SynergyMatch $match): string => $match->synergyId, $selected));
        $this->assertCount(1, array_filter($selected, static fn (SynergyMatch $match): bool => $match->mutexGroup === 'stress_activation'));
    }

    private function match(string $id, float $weight, string $mutexGroup, array $mutualExcludes): SynergyMatch
    {
        return new SynergyMatch($id, $id, $weight, $mutexGroup, $mutualExcludes, 2, [], []);
    }
}
