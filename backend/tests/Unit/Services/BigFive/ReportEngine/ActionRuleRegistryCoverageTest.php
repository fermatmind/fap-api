<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Registry\RegistryValidator;
use Tests\TestCase;

final class ActionRuleRegistryCoverageTest extends TestCase
{
    public function test_action_rule_registry_has_four_scenarios_and_twenty_eight_rules(): void
    {
        $registry = app(RegistryLoader::class)->load();

        $this->assertSame(['workplace', 'relationships', 'stress_recovery', 'personal_growth'], array_keys($registry['action_rules']));
        $this->assertCount(8, $registry['action_rules']['workplace']['rules']);
        $this->assertCount(8, $registry['action_rules']['relationships']['rules']);
        $this->assertCount(6, $registry['action_rules']['stress_recovery']['rules']);
        $this->assertCount(6, $registry['action_rules']['personal_growth']['rules']);

        $total = array_sum(array_map(
            static fn (array $pack): int => count($pack['rules']),
            $registry['action_rules']
        ));
        $this->assertSame(28, $total);
        $this->assertSame([], app(RegistryValidator::class)->validate($registry));
    }

    public function test_each_action_rule_has_required_user_facing_fields(): void
    {
        $registry = app(RegistryLoader::class)->load();

        foreach ($registry['action_rules'] as $scenario => $pack) {
            foreach ($pack['rules'] as $rule) {
                foreach (['scenario_tags', 'bucket', 'difficulty_level', 'time_horizon', 'title', 'body'] as $field) {
                    $this->assertArrayHasKey($field, $rule, "Missing {$field} on {$rule['rule_id']}");
                }
                $this->assertContains($rule['bucket'], ['continue', 'start', 'stop', 'observe']);
                $this->assertContains($rule['trait_code'], ['O', 'C', 'E', 'A', 'N']);
                $this->assertContains($scenario, $rule['scenario_tags']);
                $this->assertNotSame('', trim((string) $rule['title']));
                $this->assertNotSame('', trim((string) $rule['body']));
            }
        }
    }
}
