<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Operations\CareerCrosswalkReviewQueueService;
use Tests\TestCase;

final class CareerCrosswalkReviewQueueServiceTest extends TestCase
{
    public function test_it_builds_review_queue_items_for_local_heavy_family_proxy_and_functional_equivalent(): void
    {
        $queue = app(CareerCrosswalkReviewQueueService::class)->build(
            subjects: [
                [
                    'canonical_slug' => 'registered-nurses',
                    'crosswalk_mode' => 'local_heavy_interpretation',
                    'readiness_status' => 'blocked_override_eligible',
                    'blocked_governance_status' => null,
                ],
                [
                    'canonical_slug' => 'software-developers',
                    'crosswalk_mode' => 'family_proxy',
                    'readiness_status' => 'publish_ready',
                    'blocked_governance_status' => null,
                ],
                [
                    'canonical_slug' => 'data-scientists',
                    'crosswalk_mode' => 'functional_equivalent',
                    'readiness_status' => 'candidate_review',
                    'blocked_governance_status' => 'blocked_not_safely_remediable',
                ],
                [
                    'canonical_slug' => 'management-analysts',
                    'crosswalk_mode' => 'exact',
                    'readiness_status' => 'publish_ready',
                    'blocked_governance_status' => null,
                ],
            ],
            approvedPatchesBySlug: [
                'software-developers' => [
                    'patch_key' => 'patch-software',
                    'target_kind' => 'family',
                    'target_slug' => 'software-engineering-family',
                    'crosswalk_mode_override' => 'trust_inheritance',
                ],
            ],
            batchContextBySlug: [
                'registered-nurses' => [
                    'batch_origin' => 'batch-1',
                    'publish_track' => 'hold',
                    'family_slug' => 'healthcare-support',
                ],
                'software-developers' => [
                    'batch_origin' => 'batch-2',
                    'publish_track' => 'candidate',
                    'family_slug' => 'software-engineering-family',
                ],
            ],
        )->toArray();

        $this->assertSame('career_crosswalk_review_queue', $queue['queue_kind']);
        $this->assertSame('career.crosswalk.review_queue.v1', $queue['queue_version']);
        $this->assertSame(3, data_get($queue, 'counts.total'));
        $this->assertSame(1, data_get($queue, 'counts.local_heavy_interpretation'));
        $this->assertSame(1, data_get($queue, 'counts.family_proxy'));
        $this->assertSame(1, data_get($queue, 'counts.functional_equivalent'));

        $items = collect($queue['items'] ?? [])->keyBy('subject_slug');

        $localHeavy = $items->get('registered-nurses');
        $this->assertIsArray($localHeavy);
        $this->assertContains('local_heavy_requires_editorial_patch', $localHeavy['queue_reason']);
        $this->assertContains('not_publish_ready', $localHeavy['blocking_flags']);
        $this->assertContains('approved_patch_missing', $localHeavy['blocking_flags']);
        $this->assertSame('batch-1', $localHeavy['batch_origin']);
        $this->assertSame('hold', $localHeavy['publish_track']);

        $familyProxy = $items->get('software-developers');
        $this->assertIsArray($familyProxy);
        $this->assertSame('family', $familyProxy['candidate_target_kind']);
        $this->assertSame('software-engineering-family', $familyProxy['candidate_target_slug']);
        $this->assertNotContains('approved_patch_missing', $familyProxy['blocking_flags']);

        $functional = $items->get('data-scientists');
        $this->assertIsArray($functional);
        $this->assertContains('functional_equivalent_requires_editorial_review', $functional['queue_reason']);
        $this->assertContains('governance_blocked', $functional['blocking_flags']);
    }
}
