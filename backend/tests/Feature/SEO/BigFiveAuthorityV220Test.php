<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BigFiveAuthorityV220Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-test-landing-20';

    public function test_package_workflow_passes_with_two_landings_and_closed_release_gates(): void
    {
        $result = $this->runGate(expectSuccess: true);

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $result['status']);
        $this->assertSame(2, $result['expected_page_count']);
        $this->assertSame(2, $result['observed_final_page_count']);
        $this->assertSame(2, $result['observed_locale_pair_count']);
        $this->assertTrue($result['backend_product_authority_verified']);
        $this->assertSame([
            '/en/tests/big-five-personality-test-ocean-model',
            '/zh/tests/big-five-personality-test-ocean-model',
        ], $result['canonical_paths']);
        $this->assertTrue($result['raw_failures_preserved']);
        $this->assertTrue($result['skeptical_review_accounted']);
        $this->assertTrue($result['automated_gate_passed']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_allowed']);
        $this->assertFalse($result['schema_eligible']);
        $this->assertFalse($result['writes_committed']);
        $this->assertFalse($result['cms_write_attempted']);
        $this->assertFalse($result['indexability_mutation_attempted']);
        $this->assertFalse($result['search_submission_attempted']);
        $this->assertFalse($result['deploy_attempted']);
        $this->assertSame([
            'locale_not_independently_authored',
            'outline_incomplete',
            'product_truth_unverified',
            'template_or_cliche_detected',
            'visible_sources_incomplete',
        ], $result['package_checks']['raw']['issue_codes']);
        $this->assertTrue($result['package_checks']['repaired']['editorial_ok']);
        $this->assertTrue($result['package_checks']['final']['editorial_ok']);
    }

    public function test_final_landings_preserve_product_evidence_privacy_and_navigation_contracts(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $this->assertCount(2, $package['pages']);

        foreach ($package['pages'] as $page) {
            $this->assertSame('test-landing:big-five-ocean', $page['content_key']);
            $this->assertSame('test_landing', $page['page_family']);
            $this->assertSame('big_five', $page['framework']);
            $this->assertSame('BIG5_OCEAN', $page['scale_code']);
            $this->assertSame('independent_editorial', $page['authoring_mode']);
            $this->assertNull($page['source_locale']);
            $this->assertSame('draft_review_required', $page['status']);
            $this->assertSame('inference_requires_human_review', $page['editorial_claim_status']);
            $this->assertCount(3, $page['how_to_answer']);
            $this->assertCount(3, $page['report_includes']);
            $this->assertCount(3, $page['report_does_not_include']);
            $this->assertCount(5, $page['faq']);
            $this->assertCount(7, $page['internal_links']);
            $this->assertCount(2, $page['claims']);
            $this->assertCount(2, $page['visible_sources']);
            $this->assertSame('free_only', $page['access_and_commerce']['assessment_access']);
            $this->assertSame(['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'], $page['access_and_commerce']['free_sections']);
            $this->assertSame(['big5_full', 'big5_action_plan'], $page['access_and_commerce']['conditional_modules']);
            $this->assertFalse($page['access_and_commerce']['fixed_price_embedded']);
            $this->assertTrue($page['access_and_commerce']['live_runtime_required']);
            $this->assertSame([
                'reliability' => 'Unknown',
                'validity' => 'Unknown',
                'normative_sample_size' => 'Unknown',
                'percentile_calibration' => 'Unknown',
            ], $page['technical_evidence']['unknown_numeric_evidence']);

            $prefix = $page['locale'] === 'en' ? 'en' : 'zh';
            $this->assertSame("/{$prefix}/privacy", $page['privacy_href']);
            $this->assertSame("/{$prefix}/personality/big-five", $page['internal_links'][0]['href']);
            $this->assertSame("/{$prefix}/personality/big-five#method-boundary", $page['internal_links'][6]['href']);
            $this->assertSame(array_fill(0, 5, 'domain'), array_column(array_slice($page['internal_links'], 1, 5), 'intent'));
        }

        $this->assertStringContainsString('not a diagnosis', $package['pages'][0]['summary']);
        $this->assertStringContainsString('不是诊断', $package['pages'][1]['summary']);
        $this->assertStringContainsString('live backend', $package['pages'][0]['access_and_commerce']['explanation']);
        $this->assertStringContainsString('live backend', $package['pages'][1]['access_and_commerce']['explanation']);
        $this->assertSame('pending_human_review', $package['review_state']['status']);
        $this->assertNull($package['review_state']['reviewer']);
        $this->assertFalse($package['release_controls']['cms_write_allowed']);
        $this->assertFalse($package['release_controls']['indexability_change_allowed']);
    }

    public function test_unknown_scientific_claim_fails_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['claims'][0] = [
            'claim_id' => 'claim.unknown',
            'source_ids' => ['competitor.unapproved-source'],
        ];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('required_claim_set_mismatch', $codes);
        $this->assertContains('claim_unknown', $codes);
        $this->assertContains('final_package_failed', collect($result['issues'])->pluck('code')->all());
    }

    public function test_product_truth_and_unreviewed_numeric_evidence_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['access_and_commerce']['assessment_access'] = 'always_paid';
        $package['pages'][0]['access_and_commerce']['fixed_price_embedded'] = true;
        $package['pages'][0]['technical_evidence']['unknown_numeric_evidence']['reliability'] = 0.93;

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('backend_product_truth_mismatch', $codes);
        $this->assertContains('unsupported_numeric_evidence', $codes);
    }

    public function test_locale_navigation_private_flow_and_release_drift_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][1]['internal_links'][0]['href'] = '/en/personality/big-five';
        $package['pages'][0]['privacy_href'] = '/en/reports/private-result';
        $package['release_controls']['cms_write_allowed'] = true;
        $package['review_state']['publish_allowed'] = true;

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('internal_link_matrix_mismatch', $codes);
        $this->assertContains('privacy_link_mismatch', $codes);
        $this->assertContains('private_route_detected', $codes);
        $this->assertContains('release_control_open', $codes);
        $this->assertContains('review_state_fail_closed', $codes);
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    private function runTemporaryFinal(array $package): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-test-landing-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            return $this->runGate($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function runGate(?string $finalSource = null, bool $expectSuccess = false): array
    {
        $repoRoot = dirname(base_path());
        $command = ['node', self::DIR.'/validate-package.mjs'];
        if ($finalSource !== null) {
            $command[] = '--final-source';
            $command[] = $finalSource;
        }

        $process = new Process($command, $repoRoot);
        $process->setTimeout(20);
        $process->run();

        if ($expectSuccess) {
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        } else {
            $this->assertFalse($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }

        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(dirname(base_path()).'/'.$path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
