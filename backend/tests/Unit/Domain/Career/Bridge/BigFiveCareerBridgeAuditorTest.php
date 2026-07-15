<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Bridge;

use App\Domain\Career\Bridge\BigFiveCareerBridgeAuditor;
use App\Domain\Career\Bridge\BigFiveCareerBridgeContract;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Career\BigFiveCareerBridgeAuditFixture;
use Tests\TestCase;

final class BigFiveCareerBridgeAuditorTest extends TestCase
{
    #[Test]
    public function exact_published_and_runtime_authority_locks_produce_a_deterministic_ready_report(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $candidates = BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career));

        $first = $auditor->audit(BigFiveCareerBridgeAuditFixture::bigFiveAuthority(), $career, $candidates);
        $second = $auditor->audit(BigFiveCareerBridgeAuditFixture::bigFiveAuthority(), $career, $candidates);

        $this->assertSame($first, $second);
        $this->assertSame('pass', $first['status']);
        $this->assertSame(1, $first['candidate_count']);
        $this->assertSame(1, $first['ready_count']);
        $this->assertSame(0, $first['blocked_count']);
        $this->assertSame([], $first['blocker_breakdown']);
        $this->assertSame(BigFiveCareerBridgeContract::PRIMARY_CAREER_SIGNAL, data_get($first, 'claim_boundary.primary_career_interest_signal'));
        $this->assertSame(BigFiveCareerBridgeContract::BIG_FIVE_ROLE, data_get($first, 'claim_boundary.big_five_role'));
        $this->assertFalse((bool) data_get($first, 'private_data_boundary.private_score_vector_read', true));
        $this->assertStringContainsString('| bridge-001 | published_projection_ready | 0 |', $auditor->markdown($first));
        $this->assertStringNotContainsString('working_revision_id', json_encode($first, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function zh_cn_big_five_selects_the_exact_zh_career_runtime_projection(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection('zh-CN');
        $report = $auditor->audit(
            BigFiveCareerBridgeAuditFixture::bigFiveAuthority('zh-CN'),
            $career,
            BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career), 'zh-CN'),
        );

        $this->assertSame('pass', $report['status']);
        $this->assertSame('zh', data_get($report, 'rows.0.career_locale'));
        $this->assertSame('zh-CN', data_get($report, 'source_revision_provenance.0.locale'));
    }

    #[Test]
    public function runtime_projection_mismatch_blocks_without_fallback_copy(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $candidates = BigFiveCareerBridgeAuditFixture::candidates(str_repeat('b', 64));

        $report = $auditor->audit(BigFiveCareerBridgeAuditFixture::bigFiveAuthority(), $career, $candidates);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(0, $report['ready_count']);
        $this->assertSame(1, $report['blocked_count']);
        $this->assertArrayHasKey('authority.career_runtime_projection_hash_mismatch', $report['blocker_breakdown']);
        $this->assertArrayNotHasKey('content', $report['rows'][0]);
    }

    #[Test]
    public function working_revision_private_data_and_ranking_claims_fail_closed(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $hash = $auditor->fingerprint($career);
        $candidates = BigFiveCareerBridgeAuditFixture::candidates($hash);
        $candidates['rows'][0]['input']['big_five_projection']['selected_revision_source'] = 'working_revision';
        $candidates['rows'][0]['input']['big_five_projection']['working_or_draft_revision_used'] = true;
        $candidates['rows'][0]['input']['privacy_boundary']['contains_private_assessment_data'] = true;
        $candidates['rows'][0]['output']['claim_boundary']['ranking_allowed'] = true;

        $report = $auditor->audit(BigFiveCareerBridgeAuditFixture::bigFiveAuthority(), $career, $candidates);

        $this->assertSame('blocked', $report['status']);
        $this->assertArrayHasKey('authority.big_five_published_projection_mismatch', $report['blocker_breakdown']);
        $this->assertArrayHasKey('input.big_five.selected_source_not_published_revision', $report['blocker_breakdown']);
        $this->assertArrayHasKey('input.privacy_boundary.contains_private_assessment_data_must_be_false', $report['blocker_breakdown']);
        $this->assertArrayHasKey('output.claim_boundary.ranking_allowed_must_be_false', $report['blocker_breakdown']);
    }

    #[Test]
    public function private_or_draft_fields_in_authority_artifacts_are_rejected_globally(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $bigFive = BigFiveCareerBridgeAuditFixture::bigFiveAuthority();
        $bigFive['items'][0]['draft_snapshot'] = ['score_vector' => ['O' => 0.8]];

        $report = $auditor->audit(
            $bigFive,
            $career,
            BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career)),
        );

        $this->assertSame('blocked', $report['status']);
        $this->assertArrayHasKey('artifact.big_five.forbidden_private_or_draft_key:draft_snapshot', $report['blocker_breakdown']);
        $this->assertArrayHasKey('artifact.big_five.forbidden_private_or_draft_key:score_vector', $report['blocker_breakdown']);
        $this->assertStringNotContainsString('score_vector', json_encode($report['source_revision_provenance'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function empty_candidate_set_fails_closed_instead_of_reporting_a_vacuous_pass(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $candidates = BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career));
        $candidates['rows'] = [];

        $report = $auditor->audit(BigFiveCareerBridgeAuditFixture::bigFiveAuthority(), $career, $candidates);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(0, $report['candidate_count']);
        $this->assertSame(0, $report['ready_count']);
        $this->assertArrayHasKey('candidate.rows_empty', $report['blocker_breakdown']);
    }

    #[Test]
    public function malformed_authority_list_entries_are_not_silently_discarded(): void
    {
        $auditor = app(BigFiveCareerBridgeAuditor::class);
        $career = BigFiveCareerBridgeAuditFixture::careerProjection();
        $bigFive = BigFiveCareerBridgeAuditFixture::bigFiveAuthority();
        $bigFive['items'][] = 'not-an-object';
        $career['items'][] = ['not', 'an', 'object'];

        $report = $auditor->audit(
            $bigFive,
            $career,
            BigFiveCareerBridgeAuditFixture::candidates($auditor->fingerprint($career)),
        );

        $this->assertSame('blocked', $report['status']);
        $this->assertArrayHasKey('authority.big_five_published_projection_items_invalid', $report['blocker_breakdown']);
        $this->assertArrayHasKey('authority.career_projection_items_invalid', $report['blocker_breakdown']);
    }
}
