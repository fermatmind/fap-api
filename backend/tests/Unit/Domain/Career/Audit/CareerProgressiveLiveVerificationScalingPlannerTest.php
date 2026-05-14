<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerProgressiveLiveVerificationScalingPlanner;
use Tests\TestCase;

final class CareerProgressiveLiveVerificationScalingPlannerTest extends TestCase
{
    public function test_plans_300_chunked_live_verification(): void
    {
        $result = (new CareerProgressiveLiveVerificationScalingPlanner)->plan(
            targetPublicTotal: 300,
            slugs: $this->slugs(300),
            chunkSize: 100,
        )->toArray();

        $this->assertSame('career_progressive_live_verification_scaling_plan.v1', $result['schema_version']);
        $this->assertSame('planned', $result['status']);
        $this->assertSame(300, $result['target_public_total']);
        $this->assertSame(600, $result['expected_locale_rows']);
        $this->assertSame(3, $result['chunk_count']);
        $this->assertSame(200, $result['chunks'][1]['expected_locale_rows']);
        $this->assertFalse($result['writes_database']);
        $this->assertFalse($result['live_crawl_executed']);
        $this->assertSame(['GET', 'HEAD'], $result['request_policy']['methods']);
    }

    public function test_plans_800_chunked_live_verification(): void
    {
        $result = (new CareerProgressiveLiveVerificationScalingPlanner)->plan(
            targetPublicTotal: 800,
            slugs: $this->slugs(800),
            chunkSize: 250,
        )->toArray();

        $this->assertSame(1600, $result['expected_locale_rows']);
        $this->assertSame(4, $result['chunk_count']);
    }

    public function test_plans_2786_chunked_live_verification(): void
    {
        $result = (new CareerProgressiveLiveVerificationScalingPlanner)->plan(
            targetPublicTotal: 2786,
            slugs: $this->slugs(2786),
            chunkSize: 500,
        )->toArray();

        $this->assertSame(5572, $result['expected_locale_rows']);
        $this->assertSame(6, $result['chunk_count']);
        $this->assertSame(572, $result['chunks'][5]['expected_locale_rows']);
    }

    public function test_resume_marks_previous_chunks_without_running_http(): void
    {
        $result = (new CareerProgressiveLiveVerificationScalingPlanner)->plan(
            targetPublicTotal: 300,
            slugs: $this->slugs(300),
            chunkSize: 100,
            resumeFromChunk: 3,
            partial: ['completed_chunks' => [1]],
        )->toArray();

        $this->assertSame('completed_from_partial', $result['chunks'][0]['status']);
        $this->assertSame('resume_skipped', $result['chunks'][1]['status']);
        $this->assertSame('planned', $result['chunks'][2]['status']);
        $this->assertSame(1, $result['resume_completed_chunk_count']);
        $this->assertFalse($result['request_policy']['live_http_execution']);
    }

    public function test_blocks_when_request_guard_is_exceeded(): void
    {
        $result = (new CareerProgressiveLiveVerificationScalingPlanner)->plan(
            targetPublicTotal: 300,
            slugs: $this->slugs(300),
            requestRatePerSecond: 2.0,
            timeoutSeconds: 25,
            retries: 2,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $reasons = array_column($result['blockers'], 'reason');
        $this->assertContains('request_rate_exceeds_guard', $reasons);
        $this->assertContains('timeout_exceeds_guard', $reasons);
        $this->assertContains('retries_exceed_guard', $reasons);
    }

    public function test_blocks_when_slug_count_does_not_match_target(): void
    {
        $result = (new CareerProgressiveLiveVerificationScalingPlanner)->plan(
            targetPublicTotal: 300,
            slugs: $this->slugs(299),
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('slug_count_target_mismatch', array_column($result['blockers'], 'reason'));
    }

    /**
     * @return list<string>
     */
    private function slugs(int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('career-%04d', $i);
        }

        return $slugs;
    }
}
