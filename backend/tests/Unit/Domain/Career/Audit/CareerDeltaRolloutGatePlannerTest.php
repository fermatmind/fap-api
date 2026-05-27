<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerDeltaRolloutGatePlanner;
use Tests\TestCase;

final class CareerDeltaRolloutGatePlannerTest extends TestCase
{
    public function test_accepts_29_baseline_plus_51_delta_accounting(): void
    {
        $result = (new CareerDeltaRolloutGatePlanner)->plan($this->manifest())->toArray();

        $this->assertSame('career_delta_rollout_gate.v1', $result['schema_version']);
        $this->assertSame('pass', $result['status']);
        $this->assertSame(80, $result['target_public_total']);
        $this->assertSame(29, $result['published_baseline_count']);
        $this->assertSame(51, $result['delta_slug_count']);
        $this->assertSame(102, $result['expected_delta_locale_rows']);
        $this->assertSame('career_80_delta_canonical_001', $result['batch_id']);
        $this->assertSame($this->slugs('delta', 51), $result['delta_slugs']);
        $this->assertSame($result['delta_slugs'], $result['rollback_group']);
        $this->assertSame('explicit_delta_slug_list', $result['validation']['rollback_group_type']);
        $this->assertTrue($result['future_rollout_dry_run']['allowed']);
        $this->assertFalse($result['future_rollout_dry_run']['apply_allowed']);
        $this->assertFalse($result['writes_database']);
    }

    public function test_rejects_baseline_slug_in_delta_list(): void
    {
        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $this->manifest(baseline: ['shared-slug'], delta: ['shared-slug'], target: 2)
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('baseline_slug_in_delta_rollout', array_column($result['blockers'], 'reason'));
        $this->assertFalse($result['future_rollout_dry_run']['allowed']);
    }

    public function test_rejects_missing_rollback_group(): void
    {
        $manifest = $this->manifest(baseline: ['baseline-001'], delta: ['delta-001'], target: 2);
        $manifest['rollback_group'] = [];

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 2,
            expectedDeltaCount: 1,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('rollback_group_missing', array_column($result['blockers'], 'reason'));
    }

    public function test_rejects_rollback_group_that_does_not_match_delta_only_slugs(): void
    {
        $manifest = $this->manifest(baseline: ['baseline-001'], delta: ['delta-001', 'delta-002'], target: 3);
        $manifest['rollback_group'] = ['delta-001'];

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 3,
            expectedDeltaCount: 2,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('rollback_group_must_match_delta_slugs', array_column($result['blockers'], 'reason'));
    }

    public function test_validates_expected_delta_locale_rows(): void
    {
        $manifest = $this->manifest(baseline: ['baseline-001'], delta: ['delta-001', 'delta-002'], target: 3, locales: ['en', 'zh', 'es']);

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 3,
            expectedDeltaCount: 2,
        )->toArray();

        $this->assertSame('pass', $result['status']);
        $this->assertSame(6, $result['expected_delta_locale_rows']);
        $this->assertSame(6, $result['validation']['expected_delta_locale_rows']);
    }

    public function test_accepts_progressive_manifest_with_explicit_rollback_group(): void
    {
        $manifest = $this->manifest(
            baseline: $this->slugs('current', 80),
            delta: $this->slugs('delta', 220),
            target: 300,
        );
        $manifest['target'] = 'career_80_to_300_delta';
        $manifest['batch_id'] = 'career_80_to_300_canonical_001';

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 300,
            expectedDeltaCount: 220,
        )->toArray();

        $this->assertSame('pass', $result['status']);
        $this->assertSame('career_80_to_300_delta', $result['target']);
        $this->assertSame(220, $result['delta_slug_count']);
        $this->assertSame(440, $result['expected_delta_locale_rows']);
        $this->assertSame(220, $result['validation']['rollback_group_count']);
        $this->assertSame($result['delta_slugs'], $result['rollback_group']);
        $this->assertSame('PROGRESSIVE_ROLLOUT_DRY_RUN', $result['next_required_action']);
        $this->assertFalse($result['rollout_apply_allowed']);
    }

    public function test_accepts_detail_ready_1048_manifest_with_explicit_rollback_group(): void
    {
        $manifest = $this->manifest(
            baseline: $this->slugs('public', 30),
            delta: $this->slugs('ready', 1018),
            target: 1048,
        );
        $manifest['target'] = 'detail_ready_1048';
        $manifest['target_key'] = 'detail_ready_1048';
        $manifest['batch_id'] = 'career_detail_ready_1048_canonical_001';

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 1048,
            expectedDeltaCount: 1018,
        )->toArray();

        $this->assertSame('pass', $result['status']);
        $this->assertSame('detail_ready_1048', $result['target']);
        $this->assertSame(30, $result['published_baseline_count']);
        $this->assertSame(1018, $result['delta_slug_count']);
        $this->assertSame(2036, $result['expected_delta_locale_rows']);
        $this->assertSame('detail_ready_1048', $result['target_authority']['target_key']);
        $this->assertSame('DETAIL_READY_1048_ROLLOUT_DRY_RUN', $result['next_required_action']);
        $this->assertFalse($result['rollout_apply_allowed']);
    }

    public function test_detail_ready_1048_rejects_manual_hold_cn_proxy_and_unready_members(): void
    {
        $delta = $this->slugs('ready', 1016);
        $delta[] = 'software-developers';
        $delta[] = 'cn-proxy-sample';
        sort($delta);

        $manifest = $this->manifest(
            baseline: $this->slugs('public', 30),
            delta: $delta,
            target: 1048,
        );
        $manifest['target'] = 'detail_ready_1048';
        $manifest['target_key'] = 'detail_ready_1048';
        $manifest['batches'] = [[
            'members' => [
                ['slug' => 'ready-001', 'source_ready' => false, 'reasons' => ['review_needed']],
                ['slug' => 'cn-proxy-sample', 'public_resolution_type' => 'public_cn_proxy_page'],
            ],
        ]];

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 1048,
            expectedDeltaCount: 1018,
        )->toArray();

        $reasons = array_column($result['blockers'], 'reason');

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('detail_ready_1048_delta_contains_manual_hold_policy_slugs', $reasons);
        $this->assertContains('detail_ready_1048_rollback_group_contains_manual_hold_policy_slugs', $reasons);
        $this->assertContains('detail_ready_1048_unready_manifest_member', $reasons);
        $this->assertContains('detail_ready_1048_cn_proxy_manifest_member_forbidden', $reasons);
        $this->assertFalse($result['future_rollout_dry_run']['allowed']);
    }

    public function test_rejects_total_public_target_mismatch(): void
    {
        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $this->manifest(baseline: ['baseline-001'], delta: ['delta-001'], target: 80),
            targetPublicTotal: 80,
            expectedDeltaCount: 1,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('target_accounting_mismatch', array_column($result['blockers'], 'reason'));
    }

    public function test_blocks_when_manifest_would_allow_apply(): void
    {
        $manifest = $this->manifest(baseline: ['baseline-001'], delta: ['delta-001'], target: 2);
        $manifest['apply_allowed'] = true;

        $result = (new CareerDeltaRolloutGatePlanner)->plan(
            $manifest,
            targetPublicTotal: 2,
            expectedDeltaCount: 1,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('delta_manifest_apply_must_remain_false', array_column($result['blockers'], 'reason'));
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @param  list<string>|null  $baseline
     * @param  list<string>|null  $delta
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function manifest(?array $baseline = null, ?array $delta = null, int $target = 80, array $locales = ['en', 'zh']): array
    {
        $baseline ??= $this->slugs('baseline', 29);
        $delta ??= $this->slugs('delta', 51);
        sort($baseline);
        sort($delta);
        sort($locales);

        return [
            'schema_version' => 'career_delta_rollout_manifest.v1',
            'status' => 'pass',
            'target' => 'career_80_delta',
            'target_public_total' => $target,
            'published_baseline_count' => count($baseline),
            'delta_slug_count' => count($delta),
            'selected_count' => count($delta),
            'expected_delta_locale_rows' => count($delta) * count($locales),
            'batch_id' => 'career_80_delta_canonical_001',
            'locales' => $locales,
            'published_baseline_slugs' => $baseline,
            'slugs' => $delta,
            'rollback_group' => $delta,
            'read_only' => true,
            'writes_database' => false,
            'rollout_allowed' => false,
            'dry_run_allowed' => true,
            'apply_allowed' => false,
            'rollout_dry_run_executed' => false,
            'rollout_apply_executed' => false,
        ];
    }
}
