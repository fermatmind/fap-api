<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use PHPUnit\Framework\TestCase;

final class RiasecResultContentScienceAuditTest extends TestCase
{
    private const AUDIT_PATH = __DIR__.'/../../../../docs/riasec/riasec-result-content-science-audit-2026-07-02.json';

    public function test_science_audit_inventory_and_top3_matrix_are_complete(): void
    {
        $audit = $this->audit();

        $this->assertSame('fap.riasec.result_content_science_audit.v1', $audit['schema_version']);
        $this->assertSame('RIASEC', $audit['scope']['scale_code']);
        $this->assertSame('zh-CN', $audit['scope']['locale']);
        $this->assertContains('riasec_60', $audit['scope']['forms']);
        $this->assertContains('riasec_140', $audit['scope']['forms']);

        $this->assertGreaterThanOrEqual(5, count($audit['science_sources']));
        $sourceIds = array_column($audit['science_sources'], 'id');
        foreach (['holland_1997', 'nauta_2010', 'onet_interest_profiler', 'onet_interest_profiler_manual', 'holland_hexagon_coefficients_2021'] as $sourceId) {
            $this->assertContains($sourceId, $sourceIds);
        }

        $runtimePaths = array_column($audit['runtime_asset_inventory'], 'path');
        foreach ([
            'backend/content_assets/riasec/professional_method_boundary_v1.zh-CN.json',
            'backend/content_assets/riasec/dimension_deep_copy_v1.zh-CN.r3.json',
            'backend/content_assets/riasec/pair_blend_15_pairs_v1.zh-CN.jsonl',
            'backend/content_assets/riasec/top3_code_chain_strategy_v1.zh-CN.jsonl',
            'backend/content_assets/riasec/activity_task_examples_v1.zh-CN.jsonl',
            'backend/content_assets/riasec/occupation_examples_boundary_v1.zh-CN.jsonl',
            'backend/content_assets/riasec/feedback_action_lab_v1.zh-CN.jsonl',
        ] as $path) {
            $this->assertContains($path, $runtimePaths);
        }

        $this->assertContains('backend/tests/Fixtures/Riasec/pair_blend_15_pairs_v7_3_preflight.jsonl', $audit['fixture_mirror_inventory']);
        $this->assertContains('backend/tests/Fixtures/Riasec/professional_method_boundary_v1.zh-CN.json', $audit['fixture_mirror_inventory']);

        $fallbackPaths = array_column($audit['service_fallback_inventory'], 'path');
        $this->assertContains('backend/app/Services/Riasec/RiasecDeepCopySlotRegistry.php', $fallbackPaths);
        $this->assertContains('backend/app/Services/Riasec/RiasecActivityExplorerService.php', $fallbackPaths);
        $this->assertContains('backend/app/Services/Riasec/RiasecPublicProjectionService.php', $fallbackPaths);

        $matrix = $audit['top3_ordered_review_matrix'];
        $this->assertCount(20, $matrix);
        foreach ($matrix as $unorderedTop3Key => $orderedCodes) {
            $this->assertMatchesRegularExpression('/^[RIASEC]_[RIASEC]_[RIASEC]$/', $unorderedTop3Key);
            $this->assertCount(3, $orderedCodes);
            foreach ($orderedCodes as $orderedCode) {
                $this->assertMatchesRegularExpression('/^[RIASEC]{3}$/', $orderedCode);
                $this->assertSame(
                    $this->sortedLetters($unorderedTop3Key),
                    $this->sortedLetters($orderedCode),
                    $orderedCode.' must belong to '.$unorderedTop3Key
                );
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function audit(): array
    {
        $this->assertFileExists(self::AUDIT_PATH);
        $decoded = json_decode((string) file_get_contents(self::AUDIT_PATH), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function sortedLetters(string $value): array
    {
        $letters = str_split(str_replace('_', '', $value));
        sort($letters);

        return $letters;
    }
}
