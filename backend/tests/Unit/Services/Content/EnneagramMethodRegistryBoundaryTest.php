<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramMethodRegistryBoundaryTest extends TestCase
{
    public function test_method_registry_includes_form_variants_and_boundary_copy(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $entries = collect((array) data_get($loader->loadRegistryPack(), 'method_registry.entries', []));

        $methodKeys = $entries->pluck('method_key')->sort()->values()->all();

        $this->assertSame([
            'close_call_boundary',
            'cross_form_compare_blocked',
            'diffuse_boundary',
            'e105_standard_methodology',
            'fc144_forced_choice_methodology',
            'low_quality_boundary',
            'non_diagnostic_boundary',
            'same_model_not_same_score_space',
            'user_confirmed_type_boundary',
        ], $methodKeys);
        $this->assertStringContainsString('同一 ENNEAGRAM 模型', (string) $entries->firstWhere('method_key', 'same_model_not_same_score_space')['copy']);
        $this->assertStringContainsString('跨 form 对比默认关闭', (string) $entries->firstWhere('method_key', 'cross_form_compare_blocked')['copy']);
        $this->assertStringContainsString('不会覆盖 primary_candidate', (string) $entries->firstWhere('method_key', 'user_confirmed_type_boundary')['copy']);
    }
}
