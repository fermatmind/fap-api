<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerRecommendationDetailBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_recommendation_bundle_from_compiled_snapshot_and_subject_meta(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $chain['childProjection']->update([
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                [
                    'recommendation_subject_meta' => [
                        'type_code' => 'INTJ-A',
                        'canonical_type_code' => 'INTJ',
                        'display_title' => 'INTJ-A Career Match',
                    ],
                ],
            ),
        ]);

        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);

        $bundle = app(CareerRecommendationDetailBundleBuilder::class)->buildByType('intj');

        $this->assertNotNull($bundle);
        $payload = $bundle?->toArray() ?? [];

        $this->assertSame('career.protocol.recommendation_detail.v1', $payload['bundle_version']);
        $this->assertSame('INTJ-A', data_get($payload, 'recommendation_subject_meta.type_code'));
        $this->assertArrayHasKey('fit_score', (array) data_get($payload, 'score_bundle'));
        $this->assertArrayHasKey('allow_strong_claim', (array) data_get($payload, 'claim_permissions'));
        $this->assertSame('career_recommendation_detail_bundle', data_get($payload, 'seo_contract.surface_type'));
        $this->assertArrayHasKey('compile_refs', (array) data_get($payload, 'provenance_meta'));
    }

    public function test_it_returns_null_without_explicit_subject_meta(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);

        $bundle = app(CareerRecommendationDetailBundleBuilder::class)->buildByType('INTJ');

        $this->assertNull($bundle);
    }
}
