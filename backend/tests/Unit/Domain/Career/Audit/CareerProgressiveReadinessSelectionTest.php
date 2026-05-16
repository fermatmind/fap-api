<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerProgressiveCohortDeltaPlanner;
use App\Domain\Career\Audit\CareerProgressiveReadinessSelector;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use PHPUnit\Framework\TestCase;

final class CareerProgressiveReadinessSelectionTest extends TestCase
{
    public function test_80_to_300_selects_220_delta_slugs(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 80, 300);

        $this->assertSame('career_progressive_readiness_selection.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(220, $payload['selected_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame($delta, $payload['selected_slugs']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_300_to_800_selects_500_delta_slugs(): void
    {
        $baseline = $this->slugs('current', 300);
        $delta = $this->slugs('delta', 500);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 300, 800);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(500, $payload['delta_slug_count']);
        $this->assertSame(500, $payload['selected_count']);
        $this->assertSame(1000, $payload['expected_delta_locale_rows']);
    }

    public function test_800_to_2786_selects_1986_delta_slugs(): void
    {
        $baseline = $this->slugs('current', 800);
        $delta = $this->slugs('delta', 1986);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 800, 2786);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(1986, $payload['delta_slug_count']);
        $this->assertSame(1986, $payload['selected_count']);
        $this->assertSame(3972, $payload['expected_delta_locale_rows']);
    }

    public function test_excludes_baseline_slugs_from_delta_selection(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($delta, $payload['selected_slugs']);
        $this->assertSame([], array_values(array_intersect($baseline, $payload['selected_slugs'])));
        $this->assertSame(80, $payload['excluded']['excluded_by_reason']['already_public_baseline']);
    }

    public function test_duplicate_source_slugs_block(): void
    {
        $payload = $this->select(['career-001'], ['career-001', 'career-002', 'career-002'], 1, 2);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('duplicate_source_slugs', array_column($payload['blockers'], 'reason'));
    }

    public function test_target_less_than_or_equal_to_current_blocks(): void
    {
        $payload = $this->select(['career-001'], ['career-001'], 1, 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_not_greater_than_current', array_column($payload['blockers'], 'reason'));
    }

    public function test_blocks_when_insufficient_source_ready_slugs(): void
    {
        $payload = $this->select(['career-001'], ['career-001', 'career-002'], 1, 300);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('insufficient_source_ready_delta_slugs', array_column($payload['blockers'], 'reason'));
    }

    public function test_excludes_cn_proxy_rows_from_canonical_rollout_selection(): void
    {
        $baseline = $this->slugs('current', 80);
        $cnProxy = $this->slugs('cn-1-01', 120);
        $replacementDelta = $this->slugs('delta', 220);

        $payload = $this->select($baseline, [...$baseline, ...$cnProxy, ...$replacementDelta], 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(220, $payload['selected_count']);
        $this->assertSame($replacementDelta, $payload['selected_slugs']);
        $this->assertSame(120, $payload['excluded']['excluded_by_reason']['cn_proxy_excluded_from_canonical_rollout']);
        $this->assertSame([], array_values(array_filter(
            $payload['selected_slugs'],
            static fn (string $slug): bool => str_starts_with($slug, 'cn-'),
        )));
    }

    public function test_cn_proxy_public_type_rows_are_excluded_even_without_cn_slug_prefix(): void
    {
        $baseline = $this->slugs('current', 80);
        $replacementDelta = $this->slugs('delta', 220);
        $rows = [
            ...$this->sourceRows($baseline),
            [
                'canonical_slug' => 'china-proxy-001',
                'canonical_public_type' => 'public_cn_proxy_page',
            ],
            [
                'canonical_slug' => 'china-proxy-002',
                'recommended_resolution' => 'public_cn_proxy_page_candidate',
            ],
            ...$this->sourceRows($replacementDelta),
        ];

        $payload = $this->selectFromRows($baseline, $rows, 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($replacementDelta, $payload['selected_slugs']);
        $this->assertSame(2, $payload['excluded']['excluded_by_reason']['cn_proxy_excluded_from_canonical_rollout']);
    }

    public function test_excludes_audit_policy_blocked_and_ineligible_rows_from_progressive_selection(): void
    {
        $baseline = $this->slugs('current', 80);
        $replacementDelta = $this->slugs('delta', 220);
        $rows = [
            ...$this->sourceRows($baseline),
            [
                'canonical_slug' => 'blocked-audit-policy',
                'audit_policy_blockers' => ['editorial_review_required'],
            ],
            [
                'canonical_slug' => 'blocked-rollout-eligibility',
                'rollout_candidate_eligible' => false,
            ],
            [
                'canonical_slug' => 'blocked-readiness-status',
                'readiness_status' => 'review_required',
            ],
            ...$this->sourceRows($replacementDelta),
        ];

        $payload = $this->selectFromRows($baseline, $rows, 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($replacementDelta, $payload['selected_slugs']);
        $this->assertSame(1, $payload['excluded']['excluded_by_reason']['audit_policy_blockers']);
        $this->assertSame(1, $payload['excluded']['excluded_by_reason']['rollout_candidate_not_eligible']);
        $this->assertSame(1, $payload['excluded']['excluded_by_reason']['readiness_status_review_required']);
    }

    public function test_blocks_when_cn_proxy_exclusion_leaves_insufficient_non_cn_candidates(): void
    {
        $baseline = $this->slugs('current', 80);
        $cnProxy = $this->slugs('cn-1-01', 120);
        $replacementDelta = $this->slugs('delta', 219);

        $payload = $this->select($baseline, [...$baseline, ...$cnProxy, ...$replacementDelta], 80, 300);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(219, $payload['selected_count']);
        $this->assertContains('insufficient_source_ready_delta_slugs', array_column($payload['blockers'], 'reason'));
        $this->assertSame(120, $payload['excluded']['excluded_by_reason']['cn_proxy_excluded_from_canonical_rollout']);
        $this->assertSame(219, $payload['blockers'][0]['evidence']['source_ready_delta_count']);
        $this->assertSame(219, $payload['blockers'][0]['evidence']['selectable_candidate_count']);
    }

    public function test_excludes_software_developers_manual_hold_from_progressive_readiness_selection(): void
    {
        $baseline = $this->slugs('current', 300);
        $replacementDelta = $this->slugs('delta', 500);

        $payload = $this->select(
            $baseline,
            [...$baseline, 'software-developers', ...$replacementDelta],
            300,
            800,
        );

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(500, $payload['selected_count']);
        $this->assertSame($replacementDelta, $payload['selected_slugs']);
        $this->assertNotContains('software-developers', $payload['selected_slugs']);
        $this->assertSame(
            1,
            $payload['excluded']['excluded_by_reason']['software_developers_manual_hold_excluded_from_canonical_rollout'],
        );
    }

    public function test_2786_public_owner_plan_counts_cn_proxy_partition_without_selecting_it_for_rollout(): void
    {
        $baseline = $this->slugs('current', 800);
        $canonical = $this->slugs('canonical', 322);
        $cnProxy = $this->prefixedSlugs('cn-policy', 1663, 'cn-');

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$canonical, ...$cnProxy, 'software-developers'],
            currentTotal: 800,
            targetTotal: 2786,
            cnProxyPublicOwnerPlan: $this->cnProxyPublicOwnerPlan(1663),
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(1986, $payload['delta_slug_count']);
        $this->assertSame(323, $payload['canonical_delta_slug_count']);
        $this->assertSame(1663, $payload['public_owner_delta_slug_count']);
        $this->assertSame(322, $payload['selected_count']);
        $this->assertSame(2785, $payload['final_public_accounted_count']);
        $this->assertSame(1, $payload['final_public_shortfall']);
        $this->assertSame('progressive_source_ready_after_closeout_baseline_with_public_owner_partition', $payload['selection']['strategy']);
        $this->assertTrue($payload['cn_proxy_public_owner_plan']['ready']);
        $this->assertSame(1663, $payload['cn_proxy_public_owner_plan']['public_owner_count']);
        $this->assertSame(1663, $payload['excluded']['excluded_by_reason']['cn_proxy_excluded_from_canonical_rollout']);
        $this->assertSame(1, $payload['excluded']['excluded_by_reason']['software_developers_manual_hold_excluded_from_canonical_rollout']);
        $this->assertContains('insufficient_final_public_partition_authority', array_column($payload['blockers'], 'reason'));
        $this->assertNotContains('software-developers', $payload['selected_slugs']);
        $this->assertSame([], array_values(array_filter(
            $payload['selected_slugs'],
            static fn (string $slug): bool => str_starts_with($slug, 'cn-'),
        )));
    }

    public function test_2786_public_owner_plan_allows_final_accounting_when_canonical_partition_covers_shortfall(): void
    {
        $baseline = $this->slugs('current', 800);
        $canonical = $this->slugs('canonical', 323);
        $cnProxy = $this->prefixedSlugs('cn-policy', 1663, 'cn-');

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$canonical, ...$cnProxy],
            currentTotal: 800,
            targetTotal: 2786,
            cnProxyPublicOwnerPlan: $this->cnProxyPublicOwnerPlan(1663),
        );

        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['readiness_pass']);
        $this->assertSame(323, $payload['canonical_delta_slug_count']);
        $this->assertSame(1663, $payload['public_owner_delta_slug_count']);
        $this->assertSame(323, $payload['selected_count']);
        $this->assertSame(2786, $payload['final_public_accounted_count']);
        $this->assertSame(0, $payload['final_public_shortfall']);
        $this->assertSame($canonical, $payload['canonical_rollout_slugs']);
        $this->assertSame([], $payload['blockers']);
    }

    public function test_invalid_2786_public_owner_plan_does_not_count_cn_proxy_partition(): void
    {
        $baseline = $this->slugs('current', 800);
        $canonical = $this->slugs('canonical', 323);
        $cnProxy = $this->prefixedSlugs('cn-policy', 1663, 'cn-');
        $invalidPlan = [
            ...$this->cnProxyPublicOwnerPlan(1663),
            'public_route_allowed' => true,
        ];

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$canonical, ...$cnProxy],
            currentTotal: 800,
            targetTotal: 2786,
            cnProxyPublicOwnerPlan: $invalidPlan,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(0, $payload['public_owner_delta_slug_count']);
        $this->assertFalse($payload['cn_proxy_public_owner_plan']['ready']);
        $this->assertContains('public_route_allowed', $payload['cn_proxy_public_owner_plan']['failed_requirements']);
        $this->assertContains('cn_proxy_public_owner_plan_invalid', array_column($payload['blockers'], 'reason'));
    }

    public function test_preserves_deterministic_source_order(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $source = [$delta[2], ...$baseline, $delta[0], $delta[1], ...array_slice($delta, 3)];
        $payload = $this->select($baseline, $source, 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($source[0], $payload['selected_slugs'][0]);
        $this->assertSame($delta[0], $payload['selected_slugs'][1]);
        $this->assertSame($delta[1], $payload['selected_slugs'][2]);
    }

    public function test_output_can_feed_progressive_target_delta_planner(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $selection = $this->select($baseline, [...$baseline, ...$delta], 80, 300);

        $deltaPlan = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(80),
            currentPublicSlugs: $baseline,
            targetSelection: $selection,
            targetPublicTotal: 300,
            locales: ['en', 'zh'],
        )->toArray();

        $this->assertSame('pass', $deltaPlan['status']);
        $this->assertSame(220, $deltaPlan['delta_slug_count']);
        $this->assertSame($delta, $deltaPlan['recommended_rollout_delta_slugs']);
    }

    public function test_entity_context_occupation_exists_filter_replaces_missing_occupations(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 225);
        $missing = array_slice($delta, 0, 5);
        $occupationExisting = array_values(array_diff($delta, $missing));

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$delta],
            currentTotal: 80,
            targetTotal: 300,
            occupationExistingSlugs: $occupationExisting,
        );

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(220, $payload['selected_count']);
        $this->assertSame($occupationExisting, $payload['selected_slugs']);
        $this->assertSame(225, $payload['source_ready_count']);
        $this->assertSame(220, $payload['candidate_count']);
        $this->assertSame(5, $payload['excluded']['excluded_by_reason']['occupation_missing']);
        $this->assertTrue($payload['entity_context']['required_for_selection']);
        $this->assertSame(220, $payload['entity_context']['occupation_exists_count']);
        $this->assertSame(5, $payload['entity_context']['occupation_missing_excluded_count']);
    }

    public function test_entity_context_blocks_when_not_enough_occupation_present_candidates(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $occupationExisting = array_slice($delta, 0, 219);

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$delta],
            currentTotal: 80,
            targetTotal: 300,
            occupationExistingSlugs: $occupationExisting,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(219, $payload['selected_count']);
        $this->assertContains('insufficient_source_ready_delta_slugs', array_column($payload['blockers'], 'reason'));
        $this->assertSame(1, $payload['blockers'][0]['evidence']['occupation_missing_excluded_count']);
        $this->assertSame(219, $payload['blockers'][0]['evidence']['selectable_candidate_count']);
    }

    public function test_readiness_selection_does_not_require_rollout_candidate_runtime_state(): void
    {
        $payload = $this->select(['career-001'], ['career-001', 'career-002'], 1, 300);

        $this->assertSame('blocked', $payload['status']);
        $this->assertNotContains('insufficient_rollout_candidate_eligible_slugs', array_column($payload['blockers'], 'reason'));
        $this->assertSame(1, $payload['candidate_count']);
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $sourceSlugs
     * @param  list<string>|null  $occupationExistingSlugs
     * @return array<string, mixed>
     */
    private function select(
        array $baseline,
        array $sourceSlugs,
        int $currentTotal,
        int $targetTotal,
        ?array $occupationExistingSlugs = null,
        ?array $cnProxyPublicOwnerPlan = null,
    ): array {
        return (new CareerProgressiveReadinessSelector)->select(
            sourcePlan: $this->sourcePlan($sourceSlugs),
            currentCloseout: $this->closeout($currentTotal),
            currentPublicSlugs: $baseline,
            currentPublicTotal: $currentTotal,
            targetPublicTotal: $targetTotal,
            locales: ['en', 'zh'],
            occupationExistingSlugs: $occupationExistingSlugs,
            cnProxyPublicOwnerPlan: $cnProxyPublicOwnerPlan,
        )->toArray();
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function selectFromRows(array $baseline, array $rows, int $currentTotal, int $targetTotal): array
    {
        return (new CareerProgressiveReadinessSelector)->select(
            sourcePlan: new CareerPublicResolutionPlan('/tmp/synthetic-career-source-plan.json', null, array_map(
                static fn (array $row, int $index): CareerPublicResolutionPlanRow => CareerPublicResolutionPlanRow::fromRaw([
                    'row_number' => $index + 1,
                    'status' => 'ready_for_pilot',
                    'canonical_public_type' => 'public_canonical_job',
                    'locales' => ['en', 'zh'],
                    ...$row,
                ]),
                $rows,
                array_keys($rows),
            )),
            currentCloseout: $this->closeout($currentTotal),
            currentPublicSlugs: $baseline,
            currentPublicTotal: $currentTotal,
            targetPublicTotal: $targetTotal,
            locales: ['en', 'zh'],
        )->toArray();
    }

    /**
     * @param  list<string>  $slugs
     */
    private function sourcePlan(array $slugs): CareerPublicResolutionPlan
    {
        return new CareerPublicResolutionPlan(
            '/tmp/synthetic-career-source-plan.json',
            null,
            array_map(
                static fn (array $row): CareerPublicResolutionPlanRow => CareerPublicResolutionPlanRow::fromRaw($row),
                $this->sourceRows($slugs),
            ),
        );
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array<string, mixed>>
     */
    private function sourceRows(array $slugs): array
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = [
                'row_number' => $index + 1,
                'canonical_slug' => $slug,
                'status' => 'ready_for_pilot',
                'canonical_public_type' => 'public_canonical_job',
                'locales' => ['en', 'zh'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%04d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @return list<string>
     */
    private function prefixedSlugs(string $name, int $count, string $prefix): array
    {
        return array_map(
            static fn (string $slug): string => $prefix.$slug,
            $this->slugs($name, $count),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cnProxyPublicOwnerPlan(int $count): array
    {
        return [
            'schema_version' => 'career_2786_cn_proxy_public_owner_plan.v1',
            'status' => 'validated',
            'dry_run' => true,
            'did_write' => false,
            'cn_proxy_rows' => $count,
            'public_cn_proxy_page_rows' => $count,
            'reviewed_trust_manifest_complete' => true,
            'public_owner_plan_ready' => true,
            'route_owner_enabled' => false,
            'public_route_allowed' => false,
            'public_pages_exposed' => 0,
            'noindex_default' => true,
            'indexable_CN_proxy_rows' => 0,
            'sitemap_CN_urls' => 0,
            'llms_CN_urls' => 0,
            'llms_full_CN_urls' => 0,
            'guarded_public_owner_state' => 'reviewed_noindex_public_cn_proxy_page_ready_for_separate_owner_train',
            'blockers' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function closeout(int $total): array
    {
        return [
            'schema_version' => 'career_progressive_cohort_closeout.v1',
            'status' => 'complete',
            'accepted' => true,
            'target_public_total' => $total,
            'total_slug_count' => $total,
        ];
    }
}
