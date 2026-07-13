<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BigFiveAuthorityV207Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-hub-07';

    /** @var list<string> */
    private const REQUIRED_SECTION_KINDS = [
        'direct_answer',
        'ocean_overview',
        'dimensional_model',
        'result_reading',
        'facet_navigation',
        'use_cases',
        'misconceptions',
        'scenario',
        'counterexample',
        'tradeoff',
        'action',
        'method_boundary',
        'visible_evidence',
        'internal_links',
    ];

    public function test_package_workflow_passes_with_exact_two_page_coverage_and_closed_release_gates(): void
    {
        $result = $this->runGate(expectSuccess: true);

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $result['status']);
        $this->assertSame(2, $result['expected_page_count']);
        $this->assertSame(2, $result['observed_final_page_count']);
        $this->assertSame([
            '/en/personality/big-five',
            '/zh/personality/big-five',
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
        $this->assertTrue($result['package_checks']['raw']['schema_ok']);
        $this->assertFalse($result['package_checks']['raw']['editorial_ok']);
        $this->assertTrue($result['package_checks']['repaired']['editorial_ok']);
        $this->assertTrue($result['package_checks']['final']['editorial_ok']);
    }

    public function test_final_hubs_are_independently_edited_and_cover_every_reader_intent(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $this->assertCount(2, $package['pages']);

        foreach ($package['pages'] as $page) {
            $this->assertSame('big-five-hub', $page['content_key']);
            $this->assertSame('model_hub', $page['page_family']);
            $this->assertSame('big_five', $page['framework']);
            $this->assertSame('independent_editorial', $page['authoring_mode']);
            $this->assertNull($page['source_locale']);
            $this->assertSame('draft_review_required', $page['status']);
            $kinds = array_column($page['sections'], 'kind');
            sort($kinds);
            $expectedKinds = self::REQUIRED_SECTION_KINDS;
            sort($expectedKinds);
            $this->assertSame($expectedKinds, $kinds);
            $this->assertCount(4, $page['claims']);
            $this->assertCount(3, $page['visible_sources']);
            $this->assertCount(7, $page['internal_links']);
            $localePrefix = $page['locale'] === 'en' ? '/en/' : '/zh/';
            foreach ($page['internal_links'] as $link) {
                $this->assertStringStartsWith($localePrefix, $link['href']);
            }
        }

        $this->assertNotSame($package['pages'][0]['title'], $package['pages'][1]['title']);
        $this->assertNotSame($package['pages'][0]['summary'], $package['pages'][1]['summary']);
        $this->assertSame('pending_human_review', $package['review_state']['status']);
        $this->assertNull($package['review_state']['reviewer']);
        $this->assertFalse($package['release_controls']['cms_write_allowed']);
        $this->assertFalse($package['release_controls']['indexability_change_allowed']);
    }

    public function test_unknown_or_unauthorized_scientific_evidence_fails_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['claims'][0] = [
            'claim_id' => 'claim.unknown',
            'source_ids' => ['competitor.big-five-public-structure-benchmark-2026-07-13'],
        ];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('required_claim_set_mismatch', $codes);
        $this->assertContains('claim_unknown', $codes);
        $this->assertContains('final_package_failed', collect($result['issues'])->pluck('code')->all());
    }

    public function test_private_flow_cross_framework_and_locale_link_drift_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['sections'][0]['body'] .= ' /'.'orders/example?'.'session_id=demo';
        $package['pages'][1]['sections'][0]['body'] .= ' MB'.'TI';
        $package['pages'][0]['internal_links'][0]['href'] = '/zh/personality/big-five/openness';

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('private_route_detected', $codes);
        $this->assertContains('private_identifier_detected', $codes);
        $this->assertContains('cross_framework_leakage', $codes);
        $this->assertContains('internal_links_incomplete', $codes);
        $this->assertFalse($result['writes_committed']);
        $this->assertFalse($result['cms_write_attempted']);
        $this->assertFalse($result['indexability_mutation_attempted']);
    }

    public function test_fabricated_review_or_release_state_cannot_hide_raw_failures(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['review_state']['reviewer'] = 'fabricated-reviewer';
        $package['review_state']['publish_allowed'] = true;
        $package['release_controls']['cms_write_allowed'] = true;
        $package['release_controls']['indexability_change_allowed'] = true;

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('review_state_fail_closed', $codes);
        $this->assertContains('release_control_open', $codes);
        $this->assertTrue($result['raw_failures_preserved']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_allowed']);
        $this->assertFalse($result['schema_eligible']);
        $this->assertFalse($result['deploy_attempted']);
    }

    /** @param array<string,mixed> $package @return array<string,mixed> */
    private function runTemporaryFinal(array $package): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-hub-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            return $this->runGate($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(dirname(base_path()).'/'.$path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
