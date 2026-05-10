<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Expansion\CanonicalBatchPromotionExecutorService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CanonicalBatchPromotionExecutorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'test-family',
            'title_en' => 'Test Family',
            'title_zh' => '测试族',
        ]);

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'actuaries',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'global_standard',
            'canonical_title_en' => 'Actuaries',
            'canonical_title_zh' => '精算师',
            'search_h1_zh' => '精算师',
        ]);

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'economists',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'global_standard',
            'canonical_title_en' => 'Economists',
            'canonical_title_zh' => '经济学家',
            'search_h1_zh' => '经济学家',
        ]);

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'web-developers',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'global_standard',
            'canonical_title_en' => 'Web Developers',
            'canonical_title_zh' => '网页开发者',
            'search_h1_zh' => '网页开发者',
        ]);

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'software-developers',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'global_standard',
            'canonical_title_en' => 'Software Developers',
            'canonical_title_zh' => '软件开发者',
            'search_h1_zh' => '软件开发者',
        ]);

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'cn-aerospace-engineers',
            'entity_level' => 'market_child',
            'truth_market' => 'CN',
            'display_market' => 'CN',
            'crosswalk_mode' => 'global_standard',
            'canonical_title_en' => 'CN Aerospace Engineers',
            'canonical_title_zh' => '航空航天工程师',
            'search_h1_zh' => '航空航天工程师',
        ]);
    }

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

    public function test_entity_gate_blocks_missing_occupations(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries', 'compliance-officers']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries', 'compliance-officers']),
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('canonical_occupation_records_missing', $result['reason'] ?? null);
        $this->assertSame('missing_occupation_records', $result['entity_authority_status'] ?? null);
        $this->assertContains('compliance-officers', $result['missing_occupation_slugs'] ?? []);
        $this->assertFalse($result['writes_database']);
    }

    public function test_entity_gate_passes_when_all_occupations_exist(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries', 'economists', 'web-developers']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries', 'economists', 'web-developers']),
        );

        $this->assertSame('planned', $result['status']);
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

    public function test_blocked_result_has_failures_array(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['software-developers']),
            dryRun: false,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['software-developers']),
        );

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['writes_database']);
        $this->assertIsArray($result['failures']);
        $this->assertNotEmpty($result['failures']);
    }

    public function test_dry_run_result_includes_expected_published_rows(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries'], ['en', 'zh']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries']),
        );

        $expected = $result['promotion_plan']['expected_published_rows'];
        $this->assertCount(2, $expected);
        $this->assertSame('actuaries', $expected[0]['slug']);
        $this->assertSame('en', $expected[0]['locale']);
    }

    public function test_dry_run_plan_validation_status_is_pass_for_candidates(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries'], ['en', 'zh']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries']),
        );

        $this->assertSame('pass', $result['plan_validation']['status']);
    }

    public function test_dry_run_has_occupation_count_in_result(): void
    {
        $result = app(CanonicalBatchPromotionExecutorService::class)->execute(
            params: $this->batchParams(['actuaries', 'economists'], ['en', 'zh']),
            dryRun: true,
            quarantineOnFailure: false,
            prePromotionProjection: $this->candidateProjection(['actuaries', 'economists']),
        );

        $this->assertSame(2, $result['occupation_count'] ?? 0);
        $this->assertSame([], $result['missing_occupation_slugs'] ?? null);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

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
        return $this->buildProjection($slugs, CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function blockedProjection(array $slugs): array
    {
        return $this->buildProjection($slugs, CareerRuntimePublishProjectionService::STATE_BLOCKED);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function publishedProjection(array $slugs): array
    {
        return $this->buildProjection($slugs, CareerRuntimePublishProjectionService::STATE_PUBLISHED);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function buildProjection(array $slugs, string $state): array
    {
        $items = [];

        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $items[] = $this->projectionItem($slug, $locale, $state);
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
