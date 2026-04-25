<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramTypeRegistryCoverageTest extends TestCase
{
    private const REQUIRED_WORK_PACK_COUNTS = [
        'work_strengths' => 4,
        'work_friction_points' => 4,
        'ideal_environment' => 3,
        'collaboration_manual' => 3,
        'managed_by_others' => 2,
        'leadership_pattern' => 2,
        'workplace_trigger_points' => 2,
    ];

    private const REQUIRED_GROWTH_PACK_COUNTS = [
        'growth_strengths' => 4,
        'growth_costs' => 4,
        'early_warning_signs' => 4,
        'recovery_protocol' => 3,
        'small_experiments' => 3,
    ];

    private const REQUIRED_RELATIONSHIP_PACK_COUNTS = [
        'relationship_strengths' => 4,
        'relationship_traps' => 4,
        'communication_manual' => 3,
        'conflict_trigger_points' => 3,
        'repair_language' => 3,
        'partner_facing_notes' => 2,
    ];

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
            $this->assertPack($entry, 'work_pack', self::REQUIRED_WORK_PACK_COUNTS);
            $this->assertPack($entry, 'growth_pack', self::REQUIRED_GROWTH_PACK_COUNTS);
            $this->assertPack($entry, 'relationship_pack', self::REQUIRED_RELATIONSHIP_PACK_COUNTS);
            foreach (['stable_expression', 'default_expression', 'strained_expression'] as $stateKey) {
                $this->assertNotSame('', trim((string) data_get($entry, 'growth_pack.state_spectrum_copy.'.$stateKey, '')), sprintf('type %s missing growth_pack.state_spectrum_copy.%s', (string) ($entry['type_id'] ?? ''), $stateKey));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,int>  $requiredCounts
     */
    private function assertPack(array $entry, string $packKey, array $requiredCounts): void
    {
        $pack = (array) ($entry[$packKey] ?? []);
        $typeId = (string) ($entry['type_id'] ?? '');

        $this->assertNotSame([], $pack, sprintf('type %s missing %s', $typeId, $packKey));

        foreach ($requiredCounts as $field => $count) {
            $items = (array) ($pack[$field] ?? []);
            $this->assertGreaterThanOrEqual($count, count($items), sprintf('type %s %s.%s must include at least %d items', $typeId, $packKey, $field, $count));
            foreach ($items as $index => $item) {
                $this->assertIsArray($item, sprintf('type %s %s.%s[%d] must be object', $typeId, $packKey, $field, $index));
                $title = trim((string) (($item['title'] ?? null)));
                $body = trim((string) (($item['body'] ?? null)));
                $this->assertNotSame('', $title, sprintf('type %s %s.%s[%d] missing title', $typeId, $packKey, $field, $index));
                $this->assertNotSame('', $body, sprintf('type %s %s.%s[%d] missing body', $typeId, $packKey, $field, $index));
                foreach (self::UNSUPPORTED_SNIPPETS as $snippet) {
                    $this->assertStringNotContainsString($snippet, $title, sprintf('type %s %s.%s[%d].title contains unsupported snippet %s', $typeId, $packKey, $field, $index, $snippet));
                    $this->assertStringNotContainsString($snippet, $body, sprintf('type %s %s.%s[%d].body contains unsupported snippet %s', $typeId, $packKey, $field, $index, $snippet));
                }
            }
        }
    }
}
