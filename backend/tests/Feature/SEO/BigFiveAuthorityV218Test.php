<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BigFiveAuthorityV218Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-facets-agreeableness-18';

    /** @var list<string> */
    private const CONTENT_FIELDS = [
        'domain_difference',
        'two_ends',
        'counterexample',
        'observation_contexts',
        'low_risk_action',
        'not_meaning',
        'method_boundary',
    ];

    /** @var list<string> */
    private const FACETS = ['trust', 'straightforwardness', 'altruism', 'compliance', 'modesty', 'tender-mindedness'];

    public function test_package_workflow_passes_with_twelve_pages_and_closed_release_gates(): void
    {
        $result = $this->runGate(expectSuccess: true);

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $result['status']);
        $this->assertSame(12, $result['expected_page_count']);
        $this->assertSame(12, $result['observed_final_page_count']);
        $this->assertSame(6, $result['observed_facet_count']);
        $this->assertSame(6, $result['observed_locale_pair_count']);
        $this->assertSame([
            '/en/personality/big-five/facets/altruism',
            '/en/personality/big-five/facets/compliance',
            '/en/personality/big-five/facets/modesty',
            '/en/personality/big-five/facets/straightforwardness',
            '/en/personality/big-five/facets/tender-mindedness',
            '/en/personality/big-five/facets/trust',
            '/zh/personality/big-five/facets/altruism',
            '/zh/personality/big-five/facets/compliance',
            '/zh/personality/big-five/facets/modesty',
            '/zh/personality/big-five/facets/straightforwardness',
            '/zh/personality/big-five/facets/tender-mindedness',
            '/zh/personality/big-five/facets/trust',
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
            'template_or_cliche_detected',
            'visible_sources_incomplete',
        ], $result['package_checks']['raw']['issue_codes']);
        $this->assertTrue($result['package_checks']['repaired']['editorial_ok']);
        $this->assertTrue($result['package_checks']['final']['editorial_ok']);
    }

    public function test_final_package_preserves_exact_facet_pairs_taxonomy_and_content_contract(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $this->assertSame('ipip_neo_30_facet_navigation', $package['taxonomy']);
        $this->assertCount(12, $package['pages']);

        $pairs = [];
        foreach ($package['pages'] as $page) {
            $this->assertSame('facet_detail', $page['page_family']);
            $this->assertSame('facet_canonical', $page['route_family']);
            $this->assertSame('big_five', $page['framework']);
            $this->assertSame('agreeableness', $page['domain_code']);
            $this->assertSame('ipip_neo_30_facet_navigation', $page['taxonomy']);
            $this->assertSame('facet:agreeableness:'.$page['facet_code'], $page['content_key']);
            $this->assertSame('independent_editorial', $page['authoring_mode']);
            $this->assertNull($page['source_locale']);
            $this->assertSame('draft_review_required', $page['status']);
            $this->assertSame('inference_requires_human_review', $page['editorial_claim_status']);
            $this->assertCount(2, $page['scenarios']);
            $this->assertCount(2, $page['reflection_questions']);
            $this->assertCount(2, $page['claims']);
            $this->assertCount(2, $page['visible_sources']);
            foreach (self::CONTENT_FIELDS as $field) {
                $this->assertNotEmpty($page[$field]);
            }
            $pairs[$page['facet_code']][$page['locale']] = $page;
        }

        $this->assertSame(self::FACETS, array_keys($pairs));
        foreach ($pairs as $facet => $pair) {
            $this->assertArrayHasKey('en', $pair, $facet);
            $this->assertArrayHasKey('zh-CN', $pair, $facet);
            $this->assertNotSame($pair['en']['domain_difference'], $pair['zh-CN']['domain_difference']);
        }
        $this->assertStringContainsString('not gullibility', $pairs['trust']['en']['summary']);
        $this->assertStringContainsString('不要求说出一切', $pairs['straightforwardness']['zh-CN']['summary']);
        $this->assertStringContainsString('not moral worth', $pairs['altruism']['en']['summary']);
        $this->assertStringContainsString('不等于服从', $pairs['compliance']['zh-CN']['summary']);
        $this->assertStringContainsString('not low self-esteem', $pairs['modesty']['en']['summary']);
        $this->assertStringContainsString('不等于脆弱', $pairs['tender-mindedness']['zh-CN']['summary']);
        $this->assertFalse($package['release_controls']['cms_write_allowed']);
        $this->assertFalse($package['release_controls']['indexability_change_allowed']);
    }

    public function test_unknown_scientific_claim_fails_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['claims'][0] = [
            'claim_id' => 'claim.unknown',
            'source_ids' => ['competitor.unapproved-facet-source'],
        ];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('required_claim_set_mismatch', $codes);
        $this->assertContains('claim_unknown', $codes);
        $this->assertContains('final_package_failed', collect($result['issues'])->pluck('code')->all());
    }

    public function test_facet_identity_value_hierarchy_scenario_taxonomy_and_duplication_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['facet_code'] = 'straightforwardness';
        $package['pages'][0]['not_meaning'] .= ' Higher is better.';
        $package['pages'][0]['scenarios'] = [$package['pages'][0]['scenarios'][0]];
        $package['pages'][0]['taxonomy'] = 'bfi_2_15_facets';

        $source = $package['pages'][0];
        $duplicateIndex = 2;
        foreach (self::CONTENT_FIELDS as $field) {
            $package['pages'][$duplicateIndex][$field] = $source[$field];
        }
        $package['pages'][$duplicateIndex]['scenarios'] = $source['scenarios'];
        $package['pages'][$duplicateIndex]['reflection_questions'] = $source['reflection_questions'];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('facet_identity_mismatch', $codes);
        $this->assertContains('value_hierarchy_detected', $codes);
        $this->assertContains('two_scenarios_required', $codes);
        $this->assertContains('facet_near_duplicate', $codes);
    }

    public function test_private_flow_cross_framework_and_fabricated_release_state_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['low_risk_action'] .= ' /'.'orders/example?'.'session_id=demo MB'.'TI';
        $package['review_state']['reviewer'] = 'fabricated-reviewer';
        $package['review_state']['publish_allowed'] = true;
        $package['release_controls']['cms_write_allowed'] = true;
        $package['release_controls']['indexability_change_allowed'] = true;

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('private_route_detected', $codes);
        $this->assertContains('private_identifier_detected', $codes);
        $this->assertContains('cross_framework_leakage', $codes);
        $this->assertContains('review_state_fail_closed', $codes);
        $this->assertContains('release_control_open', $codes);
        $this->assertTrue($result['raw_failures_preserved']);
        $this->assertFalse($result['deploy_attempted']);
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    private function runTemporaryFinal(array $package): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-facets-agreeableness-');
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
