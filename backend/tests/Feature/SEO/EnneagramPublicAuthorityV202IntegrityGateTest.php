<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Tests\TestCase;

final class EnneagramPublicAuthorityV202IntegrityGateTest extends TestCase
{
    public function test_frozen_116_page_scorecard_passes_every_zero_write_gate(): void
    {
        $report = $this->gate()->validate($this->scorecard());

        $this->assertTrue($report['ok']);
        $this->assertSame('pass', $report['status']);
        $this->assertSame(58, $report['expected_identity_count']);
        $this->assertSame(116, $report['expected_page_count']);
        $this->assertSame(116, $report['source_page_count']);
        $this->assertSame(116, $report['unique_identity_locale_count']);
        $this->assertSame(0, $report['error_count']);
        $this->assertSame([], $report['error_codes']);
        $this->assertSame([
            'taxonomy' => 'pass',
            'route_canonical_hreflang' => 'pass',
            'private_boundary' => 'pass',
            'qa_review_truth' => 'pass',
        ], $report['checks']);
        $this->assertZeroWrite($report);
    }

    public function test_route_canonical_and_hreflang_drift_fail_closed(): void
    {
        $scorecard = $this->scorecard();
        $scorecard['rows'][0]['path'] = '/en/personality/enneagram/unknown';
        $scorecard['rows'][1]['canonical'] = 'https://fermatmind.com/zh/personality/enneagram/type-9';
        $scorecard['rows'][2]['hreflang']['x-default'] = $scorecard['rows'][2]['hreflang']['zh-CN'];
        $scorecard['rows'][3]['http_status'] = 302;

        $report = $this->gate()->validate($scorecard);

        $this->assertFalse($report['ok']);
        $this->assertSame('fail', $report['status']);
        $this->assertContains('route_mismatch', $report['error_codes']);
        $this->assertContains('canonical_mismatch', $report['error_codes']);
        $this->assertContains('hreflang_mismatch', $report['error_codes']);
        $this->assertContains('http_truth_mismatch', $report['error_codes']);
        $this->assertSame('fail', $report['checks']['route_canonical_hreflang']);
        $this->assertZeroWrite($report);
    }

    public function test_private_review_and_revision_truth_conflicts_fail_closed(): void
    {
        $scorecard = $this->scorecard();
        $scorecard['rows'][0]['canonical'] = 'https://fermatmind.com/en/results/private';
        $scorecard['rows'][1]['private_boundary'] = ['safe' => false, 'violations' => ['report']];
        $scorecard['rows'][2]['review_truth']['reviewer'] = 'Automated Agent';
        $scorecard['rows'][2]['review_truth']['human_review_completed'] = true;
        $scorecard['rows'][3]['review_truth']['review_state'] = 'human_reviewed';
        $scorecard['rows'][4]['revision_state']['working_revision_pointer_exposed'] = true;
        $scorecard['truth_boundary']['human_review_completed_count'] = 1;

        $report = $this->gate()->validate($scorecard);

        $this->assertContains('private_boundary_violation', $report['error_codes']);
        $this->assertContains('private_boundary_truth_conflict', $report['error_codes']);
        $this->assertContains('review_truth_conflict', $report['error_codes']);
        $this->assertContains('review_state_invalid', $report['error_codes']);
        $this->assertContains('revision_pointer_exposed', $report['error_codes']);
        $this->assertContains('truth_boundary_conflict', $report['error_codes']);
        $this->assertSame('fail', $report['checks']['private_boundary']);
        $this->assertSame('fail', $report['checks']['qa_review_truth']);
        $this->assertZeroWrite($report);
    }

    public function test_missing_duplicate_and_unknown_taxonomy_rows_fail_closed(): void
    {
        $scorecard = $this->scorecard();
        $scorecard['rows'][114] = $scorecard['rows'][0];
        $scorecard['rows'][115]['identity_key'] = 'tritype:1-2-3';

        $report = $this->gate()->validate($scorecard);

        $this->assertContains('duplicate_identity_locale', $report['error_codes']);
        $this->assertContains('unexpected_taxonomy_row', $report['error_codes']);
        $this->assertContains('missing_taxonomy_row', $report['error_codes']);
        $this->assertSame('fail', $report['checks']['taxonomy']);
        $this->assertSame(115, $report['unique_identity_locale_count']);
        $this->assertZeroWrite($report);
    }

    public function test_command_emits_the_passing_report_without_writes(): void
    {
        $this->artisan('personality:enneagram-authority-v2-integrity-gate', ['--json' => true])
            ->expectsOutputToContain('"status": "pass"')
            ->assertSuccessful();
    }

    public function test_command_errors_remain_fail_closed_and_zero_write(): void
    {
        $this->artisan('personality:enneagram-authority-v2-integrity-gate', [
            '--source' => '/tmp/does-not-exist-enneagram-authority-v2.json',
        ])
            ->expectsOutputToContain('status=fail')
            ->expectsOutputToContain('errors_count=1')
            ->expectsOutputToContain('writes_committed=0')
            ->assertFailed();
    }

    private function gate(): EnneagramPublicAuthorityV2IntegrityGate
    {
        return app(EnneagramPublicAuthorityV2IntegrityGate::class);
    }

    /** @return array<string, mixed> */
    private function scorecard(): array
    {
        $path = base_path('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json');
        $scorecard = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($scorecard);

        return $scorecard;
    }

    /** @param array<string, mixed> $report */
    private function assertZeroWrite(array $report): void
    {
        $this->assertFalse($report['writes_committed']);
        $this->assertFalse($report['cms_write_attempted']);
        $this->assertFalse($report['database_mutation_attempted']);
        $this->assertFalse($report['indexability_mutation_attempted']);
        $this->assertFalse($report['search_submission_attempted']);
    }
}
