<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Expansion\CanonicalPostPromotionReleaseGateValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use PHPUnit\Framework\TestCase;

final class CanonicalPostPromotionReleaseGateValidatorTest extends TestCase
{
    public function test_validator_passes_for_published_candidate_closeout_payload(): void
    {
        $validator = new CanonicalPostPromotionReleaseGateValidator;
        $result = $validator->validate($this->manifest(), $this->truth(), $this->projection());

        $this->assertSame('pass', $result['status']);
        $this->assertSame(0, (int) data_get($result, 'counts.failures'));
    }

    public function test_validator_blocks_non_published_state_rows(): void
    {
        $validator = new CanonicalPostPromotionReleaseGateValidator;
        $result = $validator->validate(
            $this->manifest(),
            $this->truth([$this->publishedTruthItem(['projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE])]),
            $this->projection([$this->publishedProjectionItem(['runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE])]),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('post_promotion_state_not_published', $reasons);
        $this->assertContains('post_promotion_projection_state_not_published', $reasons);
    }

    public function test_validator_blocks_non_canonical_rows(): void
    {
        $validator = new CanonicalPostPromotionReleaseGateValidator;
        $result = $validator->validate(
            $this->manifest(),
            $this->truth([$this->publishedTruthItem(['public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT])]),
            $this->projection([$this->publishedProjectionItem(['public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT])]),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('post_promotion_non_canonical_public_type', $reasons);
        $this->assertContains('post_promotion_projection_non_canonical_public_type', $reasons);
    }

    private function manifest(array $overrides = []): array
    {
        return array_merge([
            'batch_id' => 'batch-001',
            'batch_size' => 1,
            'slugs' => ['actors'],
            'locales' => ['en'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'rollout_state' => 'published',
            'rollback_group' => ['actors'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>|null  $items
     * @return array<string, mixed>
     */
    private function truth(?array $items = null): array
    {
        return ['items' => $items ?: [$this->publishedTruthItem()]];
    }

    /**
     * @param  array<string, mixed>|null  $items
     * @return array<string, mixed>
     */
    private function projection(?array $items = null): array
    {
        return ['items' => $items ?: [$this->publishedProjectionItem()]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedTruthItem(array $overrides = []): array
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
     * @param  array<string, mixed>  $overrides
     */
    private function publishedProjectionItem(array $overrides = []): array
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
        ], $overrides);
    }
}
