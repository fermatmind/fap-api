<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\Career2786PublicResolutionPartitionPlanner;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use PHPUnit\Framework\TestCase;

final class Career2786PublicResolutionPartitionPlannerTest extends TestCase
{
    public function test_partitions_final_2786_source_without_allowing_final_readiness(): void
    {
        $baseline = $this->slugs('baseline', 800);
        $canonical = $this->slugs('canonical', 85);
        $occupationMissing = $this->slugs('missing-occupation', 237);
        $cnProxy = $this->slugs('cn-policy', 1663, 'cn-');
        $software = ['software-developers'];

        $payload = $this->partition(
            sourceSlugs: [...$baseline, ...$canonical, ...$occupationMissing, ...$cnProxy, ...$software],
            baselineSlugs: $baseline,
            occupationExistingSlugs: [...$baseline, ...$canonical, ...$cnProxy, ...$software],
        );

        $this->assertSame('career_2786_public_resolution_partition.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['partition_pass']);
        $this->assertSame('partitioned', $payload['partition_status']);
        $this->assertFalse($payload['readiness_pass']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
        $this->assertFalse($payload['rollout_allowed']);
        $this->assertFalse($payload['candidate_prep_allowed']);
        $this->assertSame(800, $payload['partition_counts']['already_public_baseline']);
        $this->assertSame(85, $payload['partition_counts']['canonical_rollout_candidate']);
        $this->assertSame(237, $payload['partition_counts']['occupation_missing_remediation']);
        $this->assertSame(1663, $payload['partition_counts']['cn_proxy_policy_asset']);
        $this->assertSame(1, $payload['partition_counts']['software_manual_hold']);
        $this->assertSame(85, $payload['canonical_rollout_candidate_count']);
        $this->assertSame(885, $payload['canonical_rollout_possible_total']);
        $this->assertSame(1901, $payload['canonical_rollout_shortfall']);
        $this->assertFalse($payload['canonical_rollout_can_reach_target']);
        $this->assertContains('DO_NOT_RUN_2786_CANONICAL_CANDIDATE_PREP', $payload['next_required_actions']);
        $this->assertContains('2786_OCCUPATION_ENTITY_REMEDIATION_1', $payload['next_required_actions']);
        $this->assertContains('CN_PROXY_AUTHORITY_POLICY_DECISION_1', $payload['next_required_actions']);
        $this->assertContains('SOFTWARE_MANUAL_HOLD_FINAL_POLICY_DECISION_1', $payload['next_required_actions']);
    }

    public function test_cn_proxy_and_software_manual_hold_are_not_rollout_candidates(): void
    {
        $baseline = $this->slugs('baseline', 800);
        $canonical = $this->slugs('canonical', 1984);
        $cnProxy = ['cn-special-policy'];
        $software = ['software-developers'];

        $payload = $this->partition(
            sourceSlugs: [...$baseline, ...$canonical, ...$cnProxy, ...$software],
            baselineSlugs: $baseline,
            occupationExistingSlugs: [...$baseline, ...$canonical, ...$cnProxy, ...$software],
        );

        $policyRows = array_values(array_filter(
            $payload['rows'],
            static fn (array $row): bool => in_array($row['slug'], ['cn-special-policy', 'software-developers'], true),
        ));

        $this->assertCount(2, $policyRows);
        foreach ($policyRows as $row) {
            $this->assertFalse($row['rollout_candidate']);
            $this->assertFalse($row['canonical_rollout_candidate']);
            $this->assertFalse($row['candidate_prep_allowed']);
            $this->assertTrue($row['requires_policy_decision']);
        }
    }

    public function test_blocks_when_source_plan_is_not_complete_2786(): void
    {
        $baseline = $this->slugs('baseline', 800);
        $payload = $this->partition(
            sourceSlugs: [...$baseline, ...$this->slugs('canonical', 100)],
            baselineSlugs: $baseline,
            occupationExistingSlugs: [...$baseline, ...$this->slugs('canonical', 100)],
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('source_plan_row_count_mismatch', array_column($payload['blockers'], 'reason'));
    }

    /**
     * @param  list<string>  $sourceSlugs
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $occupationExistingSlugs
     * @return array<string, mixed>
     */
    private function partition(array $sourceSlugs, array $baselineSlugs, array $occupationExistingSlugs): array
    {
        return (new Career2786PublicResolutionPartitionPlanner)->partition(
            sourcePlan: new CareerPublicResolutionPlan(
                sourcePath: '/tmp/synthetic-career-2786-source.json',
                checksum: null,
                rows: $this->sourceRows($sourceSlugs),
            ),
            currentCloseout: [
                'schema_version' => 'career_progressive_closeout.v1',
                'status' => 'complete',
                'accepted' => true,
                'target_public_total' => 800,
                'total_slug_count' => 800,
            ],
            currentPublicSlugs: $baselineSlugs,
            currentPublicTotal: 800,
            targetPublicTotal: 2786,
            locales: ['en', 'zh'],
            occupationExistingSlugs: $occupationExistingSlugs,
        )->toArray();
    }

    /**
     * @param  list<string>  $slugs
     * @return list<CareerPublicResolutionPlanRow>
     */
    private function sourceRows(array $slugs): array
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = CareerPublicResolutionPlanRow::fromRaw([
                'row_number' => $index + 1,
                'canonical_slug' => $slug,
                'public_resolution_state' => str_starts_with($slug, 'cn-') ? 'CN_proxy_hold' : 'ready_for_pilot',
                'canonical_public_type' => str_starts_with($slug, 'cn-') ? 'public_cn_proxy_page_candidate' : 'canonical_career_page',
                'content_status' => 'approved',
                'release_status' => 'ready_for_pilot',
                'locales' => ['en', 'zh'],
                'title_en' => 'Career '.$slug,
                'title_zh' => '职业 '.$slug,
                'o_net_code' => '00-0000.00',
            ]);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $name, int $count, string $prefix = ''): array
    {
        $slugs = [];
        for ($index = 1; $index <= $count; $index++) {
            $slugs[] = sprintf('%s%s-%04d', $prefix, $name, $index);
        }

        return $slugs;
    }
}
