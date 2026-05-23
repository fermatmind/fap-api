<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Audit\CareerBatchLiveAcceptanceV2Auditor;
use App\Domain\Career\Audit\CareerBatchLiveAcceptanceV2Issue;
use App\Domain\Career\Audit\CareerCanonicalEligibilityCheckProtocol;
use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerSurfaceReadinessAuditor;
use App\Domain\Career\Audit\CareerSurfaceReadinessIssue;
use Tests\TestCase;

final class CareerReadinessAuditorsFailClosedTest extends TestCase
{
    public function test_failed_check_state_is_an_immediate_stop(): void
    {
        $this->assertFalse(CareerCanonicalEligibilityCheckProtocol::isImmediateStop(CareerCanonicalEligibilityCheckProtocol::STATE_PENDING));
        $this->assertFalse(CareerCanonicalEligibilityCheckProtocol::isImmediateStop(CareerCanonicalEligibilityCheckProtocol::STATE_GREEN));
        $this->assertTrue(CareerCanonicalEligibilityCheckProtocol::isImmediateStop(CareerCanonicalEligibilityCheckProtocol::STATE_FAILED));
    }

    public function test_surface_readiness_keeps_api_hard_failures_blocked_when_live_html_is_unverified(): void
    {
        $result = (new CareerSurfaceReadinessAuditor)->auditSlugs(
            slugs: ['software-developers'],
            locales: ['en'],
            apiArtifact: [[
                'canonical_slug' => 'software-developers',
                'locale' => 'en',
                'api_canonical_path' => '/en/career/jobs/wrong',
                'api_indexable' => true,
            ]],
            includeLiveHtml: true,
            baseUrl: null,
        )->toArray();

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result['status']);
        $this->assertSame(1, $result['blocked_rows']);
        $this->assertSame(0, $result['unverified_rows']);
        $this->assertContains(CareerSurfaceReadinessIssue::API_CANONICAL_NOT_SELF, array_keys($result['by_reason']));
        $this->assertContains(CareerSurfaceReadinessIssue::VALIDATOR_CONTEXT_MISSING, array_keys($result['by_reason']));
    }

    public function test_surface_readiness_stays_unverified_when_only_live_html_context_is_missing(): void
    {
        $result = (new CareerSurfaceReadinessAuditor)->auditSlugs(
            slugs: ['software-developers'],
            locales: ['en'],
            apiArtifact: [[
                'canonical_slug' => 'software-developers',
                'locale' => 'en',
                'api_canonical_path' => '/en/career/jobs/software-developers',
                'api_indexable' => true,
            ]],
            includeLiveHtml: true,
            baseUrl: null,
        )->toArray();

        $this->assertSame(CareerCanonicalEligibilityStatus::UNVERIFIED, $result['status']);
        $this->assertSame(0, $result['blocked_rows']);
        $this->assertSame(1, $result['unverified_rows']);
        $this->assertSame([CareerSurfaceReadinessIssue::VALIDATOR_CONTEXT_MISSING => 1], $result['by_reason']);
    }

    public function test_batch_live_acceptance_requires_explicit_surface_truth_fields(): void
    {
        $result = (new CareerBatchLiveAcceptanceV2Auditor)->audit(
            batchId: 'batch-001',
            slugs: ['software-developers'],
            locales: ['en'],
            projection: ['items' => [[
                'canonical_slug' => 'software-developers',
                'locale' => 'en',
                'release_gate_pass' => true,
            ]]],
            truth: ['items' => [[
                'canonical_slug' => 'software-developers',
                'locale' => 'en',
                'release_gate_pass' => true,
            ]]],
            surfaces: ['items' => [[
                'canonical_slug' => 'software-developers',
                'locale' => 'en',
                'surface_match' => true,
                'canonical_self' => true,
            ]]],
        )->toArray();

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result['status']);
        $this->assertFalse($result['accepted']);
        $this->assertSame([CareerBatchLiveAcceptanceV2Issue::SURFACE_MISMATCH => 1], $result['by_reason']);
        $this->assertSame('fail', $result['surfaces']['surface_equality']);
    }
}
