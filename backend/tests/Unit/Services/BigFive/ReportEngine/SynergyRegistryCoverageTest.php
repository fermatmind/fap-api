<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use Tests\TestCase;

final class SynergyRegistryCoverageTest extends TestCase
{
    private const SYNERGY_IDS = [
        'n_high_x_e_low',
        'o_high_x_c_low',
        'o_high_x_n_high',
        'c_high_x_n_high',
        'e_high_x_a_low',
    ];

    public function test_registry_contains_exactly_five_complete_synergy_rules(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $synergies = (array) $registry['synergies'];

        $this->assertSame(self::SYNERGY_IDS, array_keys($synergies));

        foreach (self::SYNERGY_IDS as $synergyId) {
            $rule = (array) $synergies[$synergyId];
            $this->assertSame($synergyId, $rule['synergy_id']);
            foreach (['trigger', 'priority_weight_formula', 'mutex_group', 'mutual_excludes', 'max_show', 'section_targets', 'copy'] as $key) {
                $this->assertArrayHasKey($key, $rule, "{$synergyId} missing {$key}");
            }
            $this->assertNotSame('', trim((string) $rule['mutex_group']));
            $this->assertContains((int) $rule['max_show'], [1, 2]);

            foreach ((array) $rule['section_targets'] as $target) {
                $this->assertContains((string) $target['section_key'], ['core_portrait', 'action_plan']);
            }

            foreach (['headline', 'body', 'strength_sentence', 'risk_sentence', 'action_hook'] as $copyField) {
                $this->assertNotSame('', trim((string) data_get($rule, "copy.{$copyField}")));
            }
        }
    }
}
