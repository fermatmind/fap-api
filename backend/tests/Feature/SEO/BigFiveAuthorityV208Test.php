<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BigFiveAuthorityV208Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-domains-08';

    /** @var array<string, list<string>> */
    private const FACETS = [
        'openness' => ['imagination', 'aesthetics', 'feelings', 'actions', 'ideas', 'values'],
        'conscientiousness' => ['competence', 'order', 'dutifulness', 'achievement-striving', 'self-discipline', 'deliberation'],
        'extraversion' => ['warmth', 'gregariousness', 'assertiveness', 'activity', 'excitement-seeking', 'positive-emotions'],
        'agreeableness' => ['trust', 'straightforwardness', 'altruism', 'compliance', 'modesty', 'tender-mindedness'],
        'neuroticism' => ['anxiety', 'anger', 'depression', 'self-consciousness', 'impulsiveness', 'vulnerability'],
    ];

    public function test_package_workflow_passes_with_exact_ten_page_coverage_and_closed_release_gates(): void
    {
        $result = $this->runGate(expectSuccess: true);

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $result['status']);
        $this->assertSame(10, $result['expected_page_count']);
        $this->assertSame(10, $result['observed_final_page_count']);
        $this->assertCount(10, $result['canonical_paths']);
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
        $this->assertSame([
            'locale_not_independently_authored',
            'outline_incomplete',
            'template_or_cliche_detected',
            'visible_sources_incomplete',
        ], $result['package_checks']['raw']['issue_codes']);
        $this->assertTrue($result['package_checks']['repaired']['editorial_ok']);
        $this->assertTrue($result['package_checks']['final']['editorial_ok']);
    }

    public function test_final_domains_are_independently_edited_and_cover_ranges_facets_and_sources(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $this->assertCount(10, $package['pages']);

        $localePairs = [];
        foreach ($package['pages'] as $page) {
            $domain = $page['domain_code'];
            $this->assertArrayHasKey($domain, self::FACETS);
            $this->assertSame("domain:{$domain}", $page['content_key']);
            $this->assertSame('domain', $page['page_family']);
            $this->assertSame('big_five', $page['framework']);
            $this->assertSame('independent_editorial', $page['authoring_mode']);
            $this->assertNull($page['source_locale']);
            $this->assertSame('draft_review_required', $page['status']);
            $this->assertSame('inference_requires_human_review', $page['editorial_claim_status']);
            $rangeLevels = array_keys($page['range']);
            sort($rangeLevels);
            $this->assertSame(['high', 'low', 'middle'], $rangeLevels);
            $this->assertSame(self::FACETS[$domain], array_column($page['facets'], 'code'));
            $this->assertCount(6, $page['facets']);
            $this->assertCount(3, $page['claims']);
            $this->assertCount(2, $page['visible_sources']);
            $localePairs[$domain][$page['locale']] = $page;
        }

        $this->assertCount(5, $localePairs);
        foreach ($localePairs as $pair) {
            $this->assertArrayHasKey('en', $pair);
            $this->assertArrayHasKey('zh-CN', $pair);
            $this->assertNotSame($pair['en']['title'], $pair['zh-CN']['title']);
            $this->assertNotSame($pair['en']['definition'], $pair['zh-CN']['definition']);
        }

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
            'source_ids' => ['competitor.unapproved-big-five-source'],
        ];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('required_claim_set_mismatch', $codes);
        $this->assertContains('claim_unknown', $codes);
        $this->assertContains('final_package_failed', collect($result['issues'])->pluck('code')->all());
    }

    public function test_facet_path_and_duplicate_content_drift_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['facets'][0]['code'] = 'invented-facet';
        $package['pages'][1]['canonical_path'] = $package['pages'][0]['canonical_path'];
        $package['pages'][2]['scenario'] = $package['pages'][0]['scenario'];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('facet_inventory_mismatch', $codes);
        $this->assertContains('canonical_coverage_mismatch', $codes);
        $this->assertContains('canonical_locale_mismatch', $codes);
        $this->assertContains('duplicate_editorial_body', $codes);
    }

    public function test_private_flow_cross_framework_and_fabricated_release_state_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['scenario'] .= ' /'.'orders/example?'.'session_id=demo MB'.'TI';
        $package['review_state']['reviewer'] = 'fabricated-reviewer';
        $package['review_state']['publish_allowed'] = true;
        $package['release_controls']['cms_write_allowed'] = true;

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('private_route_detected', $codes);
        $this->assertContains('private_identifier_detected', $codes);
        $this->assertContains('cross_framework_leakage', $codes);
        $this->assertContains('review_state_fail_closed', $codes);
        $this->assertContains('release_control_open', $codes);
        $this->assertTrue($result['raw_failures_preserved']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['writes_committed']);
        $this->assertFalse($result['deploy_attempted']);
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    private function runTemporaryFinal(array $package): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-domains-');
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
