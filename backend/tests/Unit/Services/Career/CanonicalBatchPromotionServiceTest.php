<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Expansion\CanonicalBatchPromotionService;
use App\Domain\Career\Expansion\CanonicalExpansionManifestService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Tests\TestCase;

final class CanonicalBatchPromotionServiceTest extends TestCase
{
    public function test_dry_run_promotion_plan_does_not_mutate_state(): void
    {
        $manifest = $this->manifest();
        $result = app(CanonicalBatchPromotionService::class)->plan($manifest);

        $this->assertSame('planned', $result['status']);
        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['writes_database']);
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE, $manifest['rollout_state']);
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED, data_get($result, 'updated_manifest.rollout_state'));
    }

    public function test_successful_atomic_promotion_requires_published_visibility(): void
    {
        $result = app(CanonicalBatchPromotionService::class)->promote(
            $this->manifest(),
            ['items' => [
                $this->publishedTruthItem('actors', 'en'),
                $this->publishedTruthItem('actors', 'zh'),
            ]],
        );

        $this->assertSame('promoted', $result['status']);
        $this->assertSame('pass', data_get($result, 'post_promotion_validation.status'));
        $this->assertNull($result['rollback']);
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED, data_get($result, 'updated_manifest.rollout_state'));
    }

    public function test_failed_post_promotion_validation_rolls_back_to_candidate(): void
    {
        $result = app(CanonicalBatchPromotionService::class)->promote(
            $this->manifest(),
            ['items' => [
                $this->publishedTruthItem('actors', 'en'),
                $this->publishedTruthItem('actors', 'zh', [
                    'final_200' => false,
                    'fully_live' => false,
                ]),
            ]],
        );

        $this->assertSame('rolled_back', $result['status']);
        $this->assertSame('rollback', data_get($result, 'rollback.strategy'));
        $this->assertSame(['actors'], data_get($result, 'rollback.rollback_group'));
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE, data_get($result, 'rollback.updated_manifest.rollout_state'));
    }

    public function test_failed_post_promotion_validation_can_quarantine_batch(): void
    {
        $result = app(CanonicalBatchPromotionService::class)->promote(
            $this->manifest(),
            ['items' => [
                $this->publishedTruthItem('actors', 'en'),
                $this->publishedTruthItem('actors', 'zh', [
                    'sitemap_live' => false,
                    'fully_live' => false,
                ]),
            ]],
            null,
            'quarantine',
        );

        $this->assertSame('quarantined', $result['status']);
        $this->assertSame('quarantine', data_get($result, 'rollback.strategy'));
        $this->assertSame(CanonicalExpansionManifestService::ROLLOUT_STATE_QUARANTINED, data_get($result, 'rollback.updated_manifest.rollout_state'));
        $this->assertSame(CareerRuntimePublishProjectionService::STATE_QUARANTINED, data_get($result, 'rollback.updated_manifest.projection_state'));
    }

    public function test_partial_promotion_is_rejected_and_rolled_back(): void
    {
        $result = app(CanonicalBatchPromotionService::class)->promote(
            $this->manifest(),
            ['items' => [
                $this->publishedTruthItem('actors', 'en'),
                $this->candidateTruthItem('actors', 'zh'),
            ]],
        );

        $this->assertSame('rolled_back', $result['status']);
        $this->assertContains('partial_promotion_detected', array_column($result['failures'], 'reason'));
    }

    public function test_blocked_rows_cannot_be_promoted(): void
    {
        $result = app(CanonicalBatchPromotionService::class)->plan($this->manifest([
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED,
            'projection_state' => CareerRuntimePublishProjectionService::STATE_BLOCKED,
        ]));

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('promotion_requires_published_candidate_state', array_column($result['failures'], 'reason'));
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
            'locales' => ['en', 'zh'],
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            'release_gate_required' => true,
            'surface_equality_required' => true,
            'rollback_group' => ['actors'],
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED_CANDIDATE,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publishedTruthItem(string $slug, string $locale, array $overrides = []): array
    {
        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
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
     * @return array<string, mixed>
     */
    private function candidateTruthItem(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            'route_exists' => false,
            'final_200' => false,
            'robots_indexable' => false,
            'canonical_self' => false,
            'dataset_visible' => false,
            'search_visible' => false,
            'sitemap_live' => false,
            'llms_live' => false,
            'llms_full_live' => false,
            'release_gate_pass' => false,
            'fully_live' => false,
            'candidate_pre_route_expected' => true,
        ];
    }
}
