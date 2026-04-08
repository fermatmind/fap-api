<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerJobDetailBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_explicit_job_detail_bundle_from_authority_rows(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation']);

        $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug('backend-architect');

        $this->assertNotNull($bundle);
        $payload = $bundle?->toArray() ?? [];

        $this->assertSame('career.protocol.job_detail.v1', $payload['bundle_version']);
        $this->assertSame($chain['occupation']->id, data_get($payload, 'identity.occupation_uuid'));
        $this->assertSame($chain['occupation']->canonical_title_en, data_get($payload, 'titles.canonical_en'));
        $this->assertSame($chain['trustManifest']->content_version, data_get($payload, 'trust_manifest.content_version'));
        $this->assertArrayHasKey('fit_score', (array) data_get($payload, 'score_bundle'));
        $this->assertArrayHasKey('allow_strong_claim', (array) data_get($payload, 'claim_permissions'));
        $this->assertArrayHasKey('metadata_contract_version', (array) data_get($payload, 'seo_contract'));
        $this->assertArrayHasKey('compiler_version', (array) data_get($payload, 'provenance_meta'));
    }

    public function test_it_returns_null_when_only_mutable_occupation_exists_without_compiled_authority_snapshot(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain();

        $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug('backend-architect');

        $this->assertNull($bundle);
    }
}
