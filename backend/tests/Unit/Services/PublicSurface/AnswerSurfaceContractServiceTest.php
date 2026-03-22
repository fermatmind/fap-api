<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PublicSurface;

use App\Services\PublicSurface\AnswerSurfaceContractService;
use Tests\TestCase;

final class AnswerSurfaceContractServiceTest extends TestCase
{
    public function test_it_builds_a_backend_owned_answer_surface_contract(): void
    {
        $service = new AnswerSurfaceContractService();

        $contract = $service->build([
            'answer_scope' => 'public_indexable_detail',
            'surface_type' => 'personality_public_detail',
            'summary_blocks' => [
                [
                    'key' => 'summary_intro',
                    'title' => 'Quick summary',
                    'body' => 'INTJ profiles usually prefer strategic planning and long-range leverage.',
                ],
            ],
            'faq_blocks' => [
                [
                    'key' => 'faq_what',
                    'question' => 'What does INTJ optimize for?',
                    'answer' => 'Long-range clarity and structured execution.',
                ],
            ],
            'compare_blocks' => [
                [
                    'key' => 'ei',
                    'title' => 'Energy direction',
                    'body' => 'This profile leans inward before acting.',
                ],
            ],
            'next_step_blocks' => [
                [
                    'key' => 'start_test',
                    'title' => 'Start MBTI test',
                    'href' => '/en/tests/mbti-personality-test-16-personality-types',
                ],
            ],
            'evidence_refs' => [
                'mbti_public_projection_v1',
                'landing_surface_v1',
            ],
            'seo_surface_ref' => 'seo.surface.v1',
            'landing_surface_ref' => 'landing.surface.v1',
            'primary_content_ref' => 'personality:intj',
        ]);

        $this->assertSame('answer.surface.v1', $contract['answer_contract_version']);
        $this->assertSame('public_indexable_detail', $contract['answer_scope']);
        $this->assertSame('personality_public_detail', $contract['surface_type']);
        $this->assertSame('indexable', $contract['indexability_state']);
        $this->assertSame('public_indexable', $contract['public_safety_state']);
        $this->assertSame('public_answer_surface', $contract['attribution_scope']);
        $this->assertSame('seo.surface.v1', $contract['seo_surface_ref']);
        $this->assertSame('landing.surface.v1', $contract['landing_surface_ref']);
        $this->assertSame('personality:intj', $contract['primary_content_ref']);
        $this->assertCount(1, $contract['summary_blocks']);
        $this->assertCount(1, $contract['faq_blocks']);
        $this->assertCount(1, $contract['compare_blocks']);
        $this->assertCount(1, $contract['next_step_blocks']);
        $this->assertSame(['mbti_public_projection_v1', 'landing_surface_v1'], $contract['evidence_refs']);
        $this->assertIsString($contract['answer_fingerprint']);
        $this->assertNotSame('', $contract['answer_fingerprint']);
    }

    public function test_it_defaults_share_safe_answer_surfaces_to_noindex(): void
    {
        $service = new AnswerSurfaceContractService();

        $contract = $service->build([
            'answer_scope' => 'public_share_safe',
            'surface_type' => 'mbti_share_public_safe',
            'summary_blocks' => [
                [
                    'key' => 'share_summary',
                    'body' => 'This share page keeps only the lightweight public-safe summary visible.',
                ],
            ],
        ]);

        $this->assertSame('public_share_safe', $contract['public_safety_state']);
        $this->assertSame('noindex', $contract['indexability_state']);
        $this->assertSame('share_public_surface', $contract['attribution_scope']);
        $this->assertNull($contract['runtime_artifact_ref']);
    }
}
