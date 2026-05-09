<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Expansion\CanonicalExpansionManifestService;
use App\Domain\Career\Expansion\CanonicalPromotionRollbackGate;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use PHPUnit\Framework\TestCase;

final class CanonicalPromotionRollbackGateTest extends TestCase
{
    public function test_it_accepts_hidden_published_candidate_pre_route_inventory(): void
    {
        $result = (new CanonicalPromotionRollbackGate)->validatePromotionPlan(
            $this->manifest(),
            ['items' => [
                $this->candidateTruthItem('actors', 'en'),
                $this->candidateTruthItem('actors', 'zh'),
            ]],
            ['items' => [
                $this->candidateProjectionItem('actors', 'en'),
                $this->candidateProjectionItem('actors', 'zh'),
            ]],
        );

        $this->assertSame('pass', $result['status']);
        $this->assertSame(2, data_get($result, 'counts.candidate_locale_rows'));
        $this->assertSame('expected_pre_route', $result['candidate_pre_route_semantics']);
    }

    public function test_it_rejects_candidate_public_route_or_surface_exposure(): void
    {
        $result = (new CanonicalPromotionRollbackGate)->validatePromotionPlan(
            $this->manifest(),
            ['items' => [
                $this->candidateTruthItem('actors', 'en', [
                    'route_exists' => true,
                    'final_200' => true,
                    'dataset_visible' => true,
                    'candidate_pre_route_expected' => false,
                ]),
            ]],
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('candidate_unexpected_route_exposure', $reasons);
        $this->assertContains('candidate_unexpected_api_exposure', $reasons);
        $this->assertContains('candidate_unexpected_dataset_exposure', $reasons);
    }

    public function test_it_rejects_software_developers_and_cn_proxy_candidates(): void
    {
        $result = (new CanonicalPromotionRollbackGate)->validatePromotionPlan($this->manifest([
            'slugs' => ['software-developers', 'cn-software-engineers'],
            'rollback_group' => ['software-developers', 'cn-software-engineers'],
        ]));

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('software_developers_cannot_be_promoted', $reasons);
        $this->assertContains('cn_proxy_cannot_be_promoted', $reasons);
    }

    public function test_it_rejects_blocked_rows_and_partial_rollback_groups(): void
    {
        $result = (new CanonicalPromotionRollbackGate)->validatePromotionPlan($this->manifest([
            'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_BLOCKED,
            'projection_state' => CareerRuntimePublishProjectionService::STATE_BLOCKED,
            'slugs' => ['actors', 'registered-nurses'],
            'rollback_group' => ['actors'],
        ]));

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('promotion_requires_published_candidate_state', $reasons);
        $this->assertContains('promotion_requires_candidate_projection_state', $reasons);
        $this->assertContains('partial_promotion_rejected', $reasons);
    }

    public function test_it_requires_fully_live_published_rows_after_promotion(): void
    {
        $result = (new CanonicalPromotionRollbackGate)->validatePostPromotion(
            array_merge($this->manifest(), [
                'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
                'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            ]),
            ['items' => [
                $this->publishedTruthItem('actors', 'en'),
                $this->publishedTruthItem('actors', 'zh'),
            ]],
            ['items' => [
                $this->publishedProjectionItem('actors', 'en'),
                $this->publishedProjectionItem('actors', 'zh'),
            ]],
        );

        $this->assertSame('pass', $result['status']);
    }

    public function test_it_rejects_partial_post_promotion_state(): void
    {
        $result = (new CanonicalPromotionRollbackGate)->validatePostPromotion(
            array_merge($this->manifest(), [
                'rollout_state' => CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
                'projection_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            ]),
            ['items' => [
                $this->publishedTruthItem('actors', 'en'),
                $this->candidateTruthItem('actors', 'zh'),
            ]],
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('partial_promotion_detected', $reasons);
        $this->assertContains('post_promotion_state_not_published', $reasons);
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
    private function candidateTruthItem(string $slug, string $locale, array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedTruthItem(string $slug, string $locale): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateProjectionItem(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
            'detail_route_enabled' => false,
            'dataset_visible' => false,
            'search_visible' => false,
            'sitemap_live' => false,
            'llms_live' => false,
            'llms_full_live' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedProjectionItem(string $slug, string $locale): array
    {
        return [
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'runtime_publish_state' => CareerRuntimePublishProjectionService::STATE_PUBLISHED,
            'detail_route_enabled' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'sitemap_live' => true,
            'llms_live' => true,
            'llms_full_live' => true,
        ];
    }
}
