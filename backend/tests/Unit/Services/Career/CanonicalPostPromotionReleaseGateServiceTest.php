<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Expansion\CanonicalPostPromotionReleaseGateService;
use App\Domain\Career\Expansion\CanonicalPostPromotionReleaseGateValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use PHPUnit\Framework\TestCase;

final class CanonicalPostPromotionReleaseGateServiceTest extends TestCase
{
    public function test_closeout_allows_when_all_post_promotion_checks_pass(): void
    {
        $service = $this->service();
        $result = $service->evaluate(
            $this->manifest(),
            $this->truth([$this->publishedTruthItem()]),
            $this->projection([$this->publishedProjectionItem()]),
        );

        $this->assertSame('batch-001', $result['batch_id']);
        $this->assertTrue($result['closeout_allowed']);
        $this->assertFalse($result['rollback_required']);
        $this->assertEquals(1, $result['release_gate_pass_count']);
        $this->assertEquals(0, $result['release_gate_blocked_count']);
        $this->assertSame([], $result['failed_slugs']);
        $this->assertSame([], $result['failure_reasons']);
    }

    public function test_closeout_blocks_when_post_promotion_check_fails(): void
    {
        $service = $this->service();
        $result = $service->evaluate(
            $this->manifest(),
            $this->truth([$this->publishedTruthItem([
                'route_exists' => false,
                'final_200' => false,
                'fully_live' => false,
            ])]),
            $this->projection([$this->publishedProjectionItem(['sitemap_live' => false])]),
        );

        $this->assertFalse($result['closeout_allowed']);
        $this->assertTrue($result['rollback_required']);
        $this->assertEquals(0, $result['release_gate_pass_count']);
        $this->assertEquals(1, $result['release_gate_blocked_count']);
        $this->assertContains('post_promotion_route_exists_missing', $result['failure_reasons']);
        $this->assertNotEmpty($result['failure_reasons']);
    }

    private function service(): CanonicalPostPromotionReleaseGateService
    {
        return new CanonicalPostPromotionReleaseGateService(new CanonicalPostPromotionReleaseGateValidator);
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function truth(array $items): array
    {
        return ['items' => $items];
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
     * @param  array<string, mixed>|null  $items
     * @return array<string, mixed>
     */
    private function projection(array $items): array
    {
        return ['items' => $items];
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
