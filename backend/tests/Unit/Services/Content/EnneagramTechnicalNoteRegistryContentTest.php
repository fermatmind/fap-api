<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramTechnicalNoteRegistryContentTest extends TestCase
{
    public function test_technical_note_registry_contains_public_safe_v0_1_sections(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $entries = collect((array) data_get($loader->loadRegistryPack(), 'technical_note_registry.entries', []));

        $this->assertCount(13, $entries);
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['body'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => ! is_array($entry['metric_refs'] ?? null)));
        $this->assertFalse($entries->contains(fn ($entry): bool => str_contains((string) ($entry['body'] ?? ''), '准确率 92%')));
        $dominanceGap = (string) ($entries->firstWhere('section_key', 'dominance_gap')['body'] ?? '');
        $this->assertStringContainsString('同一 form、同一 score space', $dominanceGap);
        $this->assertStringContainsString('不能被当成跨表单、跨人群或跨时间的统一尺子', $dominanceGap);
        $this->assertStringContainsString('不是测量误差范围、统计置信区间、常模排名、外部效度证明或人格确定性', $dominanceGap);
        $this->assertSame('planned', (string) ($entries->firstWhere('section_key', 'retake_stability')['data_status'] ?? ''));
        $this->assertSame('collecting', (string) ($entries->firstWhere('section_key', 'resonance_feedback')['data_status'] ?? ''));
    }
}
