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

    public function test_only_diffuse_canonical_fields_override_legacy_english_copy(): void
    {
        $legacyPath = base_path('content_assets/public_content_53/enneagram_public_copy.en.json');
        $legacyBytes = file_get_contents($legacyPath);
        $legacy = json_decode($legacyBytes, true, 512, JSON_THROW_ON_ERROR);
        foreach (['zh-CN', 'en'] as $locale) {
            $pack = app(\App\Services\Content\EnneagramPackLoader::class)->loadRegistryPack(locale: $locale);
            $note = app(EnneagramTechnicalNoteService::class)->contract($locale)['technical_note_v1'];
            $canonical = collect($pack['technical_note_registry']['entries'])->firstWhere('section_key', 'diffuse');
            $actual = collect($note['sections'])->firstWhere('section_key', 'diffuse');
            $this->assertSame($canonical['body'], $actual['body']);
            $method = collect($pack['method_registry']['entries'])->firstWhere('method_key', 'diffuse_boundary');
            $this->assertSame($method['copy'], $note['method_boundaries']['diffuse_boundary']['copy']);
            foreach ($note['method_boundaries'] as $entry) {
                $this->assertNotSame('', $entry['label']);
            }
            if ($locale === 'en') {
                foreach ($note['sections'] as $section) {
                    $this->assertSame($legacy['sections'][$section['section_key']]['title'], $section['title']);
                    if ($section['section_key'] !== 'diffuse') {
                        $this->assertSame($legacy['sections'][$section['section_key']]['body'], $section['body']);
                    }
                }
                foreach ($note['method_boundaries'] as $key => $entry) {
                    if ($key !== 'diffuse_boundary') {
                        $this->assertSame($legacy['method_boundaries'][$key]['copy'], $entry['copy']);
                    }
                }
                $this->assertSame(hash('sha256', $legacyBytes), $note['public_copy_sha256']);
            }
        }
        $this->assertSame($legacyBytes, file_get_contents($legacyPath));
    }
}
