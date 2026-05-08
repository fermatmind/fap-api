<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Expansion\CanonicalExpansionManifestService;
use App\Domain\Career\Expansion\CanonicalRolloutGovernanceValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Tests\TestCase;

final class CanonicalRolloutGovernanceValidatorTest extends TestCase
{
    public function test_it_passes_for_published_batch_when_truth_and_surfaces_are_equal(): void
    {
        $result = app(CanonicalRolloutGovernanceValidator::class)->validate(
            $this->manifest(['rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED]),
            $this->truth([$this->truthItem()]),
            $this->projection([$this->projectionItem()]),
        );

        $this->assertSame('pass', $result['status']);
        $this->assertSame(0, data_get($result, 'counts.failures'));
    }

    public function test_it_blocks_projection_surface_mismatch_and_forbidden_leakage(): void
    {
        $result = app(CanonicalRolloutGovernanceValidator::class)->validate(
            $this->manifest(['rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED]),
            $this->truth([$this->truthItem(['llms_live' => false, 'llms_full_live' => false, 'fully_live' => false])]),
            $this->projection([
                $this->projectionItem(),
                $this->projectionItem([
                    'slug' => 'software-developers',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'dataset_visible' => true,
                ]),
                $this->projectionItem([
                    'slug' => 'cn-2-06-03-00',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CN_PROXY_PAGE,
                    'sitemap_live' => true,
                ]),
                $this->projectionItem([
                    'slug' => 'technology',
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB,
                    'llms_live' => true,
                ]),
            ]),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('projection_only', $reasons);
        $this->assertContains('published_manifest_row_not_fully_live', $reasons);
        $this->assertContains('software_leakage', $reasons);
        $this->assertContains('cn_leakage', $reasons);
        $this->assertContains('family_leakage', $reasons);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifest(array $overrides = []): array
    {
        return array_merge([
            'batch_id' => 'batch-001',
            'batch_size' => 1,
            'slugs' => ['actors'],
            'locales' => ['en'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            'release_gate_required' => true,
            'surface_equality_required' => true,
            'rollback_group' => ['actors'],
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function truth(array $items): array
    {
        return [
            'truth_kind' => 'career_canonical_runtime_truth',
            'truth_version' => 'career.canonical_runtime_truth.v1',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function truthItem(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'actors',
            'locale' => 'en',
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'route_exists' => true,
            'final_200' => true,
            'robots_indexable' => true,
            'canonical_self' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'release_gate_pass' => true,
            'fully_live' => true,
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function projection(array $items): array
    {
        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectionItem(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'actors',
            'locale' => 'en',
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'detail_route_enabled' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
            'canonical_url' => 'https://fermatmind.com/en/career/jobs/actors',
            'canonical_self' => true,
            'robots_indexable' => true,
            'release_gate_pass' => true,
        ], $overrides);
    }
}
