<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PublicSurface;

use App\Services\PublicSurface\LandingSurfaceContractService;
use Tests\TestCase;

final class LandingSurfaceContractServiceTest extends TestCase
{
    public function test_it_builds_a_backend_owned_landing_surface_contract(): void
    {
        $service = new LandingSurfaceContractService();

        $contract = $service->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'topic_detail',
            'entry_type' => 'topic_profile',
            'summary_blocks' => [
                [
                    'key' => 'answer_first',
                    'title' => 'Topic summary',
                    'body' => 'Connect articles, tests, and personality content.',
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => ['articles', 'tests', 'personality'],
            'continue_reading_keys' => ['articles', 'personality'],
            'start_test_target' => '/en/tests/mbti-personality-test-16-personality-types',
            'content_continue_target' => '/en/personality',
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => 'Start test',
                    'href' => '/en/tests/mbti-personality-test-16-personality-types',
                    'kind' => 'primary',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_topic_landing',
            'seo_surface_ref' => 'seo.surface.v1:topic_public_detail',
            'surface_family' => 'topic',
        ]);

        $this->assertSame('landing.surface.v1', $contract['landing_contract_version']);
        $this->assertSame('public_indexable_detail', $contract['landing_scope']);
        $this->assertSame('topic_detail', $contract['entry_surface']);
        $this->assertSame('topic_profile', $contract['entry_type']);
        $this->assertSame('indexable', $contract['indexability_state']);
        $this->assertSame('public_topic_landing', $contract['attribution_scope']);
        $this->assertSame('seo.surface.v1:topic_public_detail', $contract['seo_surface_ref']);
        $this->assertSame('topic', $contract['surface_family']);
        $this->assertSame('/en/tests/mbti-personality-test-16-personality-types', $contract['start_test_target']);
        $this->assertSame('/en/personality', $contract['content_continue_target']);
        $this->assertSame('answer_first', $contract['summary_blocks'][0]['key']);
        $this->assertSame('Start test', $contract['cta_bundle'][0]['label']);
        $this->assertIsString($contract['landing_fingerprint']);
        $this->assertNotSame('', $contract['landing_fingerprint']);
    }
}
