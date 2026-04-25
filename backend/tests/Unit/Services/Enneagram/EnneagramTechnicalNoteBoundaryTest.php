<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram;

use App\Services\Enneagram\EnneagramTechnicalNoteService;
use Tests\TestCase;

final class EnneagramTechnicalNoteBoundaryTest extends TestCase
{
    public function test_technical_note_includes_safe_boundaries_without_unsupported_claims(): void
    {
        $service = app(EnneagramTechnicalNoteService::class);

        $contract = $service->contract();
        $disclaimerKeys = collect((array) data_get($contract, 'technical_note_v1.disclaimers', []))
            ->map(static fn (array $entry): string => (string) ($entry['key'] ?? ''))
            ->all();

        $this->assertSame([
            'not_diagnostic',
            'not_clinical',
            'not_hiring_screening',
            'no_hard_theory_judgement',
            'no_cross_form_numeric_compare',
            'user_confirmed_type_boundary',
        ], $disclaimerKeys);

        $body = collect((array) data_get($contract, 'technical_note_v1.sections', []))
            ->map(static fn (array $entry): string => (string) ($entry['body'] ?? ''))
            ->implode("\n");

        $this->assertStringNotContainsString('准确率', $body);
        $this->assertStringNotContainsString('临床效度已验证', $body);
        $this->assertStringNotContainsString('招聘筛选推荐', $body);
        $this->assertContains('clinical_validity', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));
        $this->assertContains('hiring_screening_suitability', (array) data_get($contract, 'technical_note_v1.data_status_summary.not_claimed', []));
        $this->assertContains('close_call_rate', (array) data_get($contract, 'technical_note_v1.data_status_summary.metrics.currently_operational', []));
        $this->assertContains('top1_resonance_rate', (array) data_get($contract, 'technical_note_v1.data_status_summary.metrics.collecting_data', []));
    }
}
