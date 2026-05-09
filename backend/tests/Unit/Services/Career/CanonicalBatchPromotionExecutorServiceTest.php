<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Expansion\CanonicalBatchPromotionExecutorService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use Tests\TestCase;

final class CanonicalBatchPromotionExecutorServiceTest extends TestCase
{
    public function test_dry_run_does_not_write_database(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries']),
        );

        $this->assertSame('planned', $result['status']);
        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['writes_database']);
    }

    public function test_dry_run_rejects_blocked_rows(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->blockedProjection(['actuaries']),
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['writes_database']);
    }

    public function test_dry_run_rejects_software_developers(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['software-developers']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['software-developers']),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('software_developers_cannot_be_promoted', $reasons);
    }

    public function test_dry_run_rejects_cn_proxy_slugs(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['cn-aerospace-engineers']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['cn-aerospace-engineers']),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('cn_proxy_cannot_be_promoted', $reasons);
    }

    public function test_dry_run_rejects_non_candidate_state(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->publishedProjection(['actuaries']),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('candidate_truth_state_mismatch', $reasons);
    }

    public function test_dry_run_rejects_partial_rollback_group(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: [
                'batch_id' => 'batch-001',
                'slugs' => ['actuaries', 'economists'],
                'locales' => ['en', 'zh'],
                'rollback_group' => ['actuaries'],
            ],
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries', 'economists']),
        );

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['failures'], 'reason');
        $this->assertContains('partial_promotion_rejected', $reasons);
    }

    public function test_dry_run_plan_has_correct_expected_rows(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries'], ['en', 'zh']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries']),
        );

        $this->assertSame('planned', $result['status']);
        $this->assertCount(2, $result['promotion_plan']['expected_published_rows']);
    }

    public function test_plan_handles_multiple_candidate_slugs(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries', 'economists', 'web-developers'], ['en', 'zh']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries', 'economists', 'web-developers']),
        );

        $this->assertSame('planned', $result['status']);
        $this->assertCount(6, $result['promotion_plan']['expected_published_rows']);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{batcH_id: string, slugs: list<string>, locales: list<string>, rollback_group: list<string>}
     */
    private function batchParams(array $slugs = ['actuaries'], array $locales = ['en', 'zh']): array
    {
        return [
            'batch_id' => 'batch-001-test',
            'slugs' => $slugs,
            'locales' => $locales,
            'rollback_group' => $slugs,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function candidateProjection(array $slugs): array
    {
        $items = [];

        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[] = $this->projectionItem($slug, $locale, CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE);
            }
        }

        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function blockedProjection(array $slugs): array
    {
        $items = [];

        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[] = $this->projectionItem($slug, $locale, CareerRuntimePublishProjectionService::STATE_BLOCKED);
            }
        }

        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function publishedProjection(array $slugs): array
    {
        $items = [];

        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[] = $this->projectionItem($slug, $locale, CareerRuntimePublishProjectionService::STATE_PUBLISHED);
            }
        }

        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectionItem(string $slug, string $locale, string $state): array
    {
        $isCandidate = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE;
        $isPublished = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED;

        return [
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => 'public_canonical_job',
            'runtime_publish_state' => $state,
            'detail_route_enabled' => $isPublished,
            'dataset_visible' => $isPublished,
            'search_visible' => $isPublished,
            'sitemap_live' => $isPublished,
            'llms_live' => $isPublished,
            'llms_full_live' => $isPublished,
            'canonical_url' => $isPublished ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug : null,
            'canonical_self' => $isPublished,
            'robots_indexable' => $isPublished,
            'release_gate_pass' => $isPublished,
            'blockers' => [],
        ];
    }
}
