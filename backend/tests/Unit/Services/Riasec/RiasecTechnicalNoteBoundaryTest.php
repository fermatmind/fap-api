<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecTechnicalNoteService;
use Tests\TestCase;

final class RiasecTechnicalNoteBoundaryTest extends TestCase
{
    public function test_technical_note_includes_safe_method_boundaries_without_overclaims(): void
    {
        $contract = app(RiasecTechnicalNoteService::class)->contract();

        $disclaimerKeys = collect((array) data_get($contract, 'technical_note_v1.disclaimers', []))
            ->map(static fn (array $entry): string => (string) ($entry['key'] ?? ''))
            ->all();

        $this->assertSame([
            'not_ability_or_personality',
            'not_hiring_screening',
            'no_cross_form_raw_delta',
            'riasec_140_not_more_accurate',
            'examples_not_matches',
            'feedback_overlay_boundary',
        ], $disclaimerKeys);

        $body = collect((array) data_get($contract, 'technical_note_v1.sections', []))
            ->map(static fn (array $entry): string => (string) ($entry['body'] ?? ''))
            ->implode("\n");
        $boundaryCopy = collect((array) data_get($contract, 'technical_note_v1.method_boundaries', []))
            ->map(static fn (array $entry): string => (string) ($entry['copy'] ?? ''))
            ->implode("\n");
        $text = $body."\n".$boundaryCopy;

        $this->assertStringContainsString('不默认比较 raw score delta', $text);
        $this->assertStringContainsString('不能称为更准确答案', $text);
        $this->assertStringContainsString('content_example_not_registry_match', $text);
        $this->assertStringContainsString('不会覆盖 measured_holland_code', $text);
        $this->assertStringContainsString('minimal_answer_completion_only', $text);
        $this->assertStringNotContainsString('岗位匹配度', $text);
        $this->assertStringNotContainsString('职业成功预测', $text);
        $this->assertStringNotContainsString('更准确答案。140Q', $text);

        $this->assertContains('career_success_probability', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));
        $this->assertContains('job_fit', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));
        $this->assertContains('cross_form_raw_score_delta', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));
    }
}
