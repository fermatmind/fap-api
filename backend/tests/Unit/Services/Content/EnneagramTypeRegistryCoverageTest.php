<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramTypeRegistryCoverageTest extends TestCase
{
    private const REQUIRED_DEEP_DIVE_KEYS = [
        'core_desire',
        'core_fear',
        'defense_pattern',
        'misread_by_others',
        'self_misread',
        'work_mechanism',
        'relationship_script',
        'conflict_pattern',
        'stress_signal',
        'recovery_action',
        'growth_principle',
        'thirty_day_experiment',
    ];

    private const UNSUPPORTED_SNIPPETS = [
        '临床诊断',
        '临床判断',
        '招聘筛选',
        '准确率',
        '效度验证',
        '外部效度',
        'health level',
        '健康层级判定',
        '子类型判定',
        '翼型判定',
        '箭头判定',
    ];

    public function test_type_registry_covers_1_to_9_at_p0_ready_level(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();

        $entries = (array) data_get($pack, 'type_registry.entries', []);
        $typeIds = collect($entries)->pluck('type_id')->sort()->values()->all();

        $this->assertCount(9, $entries);
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9'], $typeIds);
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => trim((string) ($entry['seven_day_question'] ?? '')) === ''));
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => trim((string) ($entry['hero_summary'] ?? '')) === ''));
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => trim((string) ($entry['healthy_expression'] ?? '')) === ''));
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => trim((string) ($entry['blind_spot_copy'] ?? '')) === ''));
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => ($entry['content_maturity'] ?? null) !== 'p0_ready'));
        $this->assertFalse(collect($entries)->contains(fn ($entry): bool => ($entry['evidence_level'] ?? null) !== 'theory_based'));
        foreach ($entries as $entry) {
            $deepDive = (array) ($entry['deep_dive'] ?? []);
            foreach (self::REQUIRED_DEEP_DIVE_KEYS as $key) {
                $this->assertNotSame('', trim((string) ($deepDive[$key] ?? '')), sprintf('type %s missing deep_dive.%s', (string) ($entry['type_id'] ?? ''), $key));
                foreach (self::UNSUPPORTED_SNIPPETS as $snippet) {
                    $this->assertStringNotContainsString($snippet, (string) ($deepDive[$key] ?? ''), sprintf('type %s deep_dive.%s contains unsupported snippet %s', (string) ($entry['type_id'] ?? ''), $key, $snippet));
                }
            }
        }
    }
}
