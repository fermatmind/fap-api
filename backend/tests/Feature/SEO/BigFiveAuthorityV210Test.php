<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BigFiveAuthorityV210Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-range-openness-10';

    /** @var list<string> */
    private const CONTENT_FIELDS = [
        'meaning',
        'possible_patterns',
        'counterexample',
        'context_variation',
        'combination_effects',
        'strengths_tradeoffs',
        'communication_action',
        'not_meaning',
        'method_boundary',
    ];

    public function test_package_workflow_passes_with_eight_pages_and_closed_release_gates(): void
    {
        $result = $this->runGate(expectSuccess: true);

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $result['status']);
        $this->assertSame(8, $result['expected_page_count']);
        $this->assertSame(8, $result['observed_final_page_count']);
        $this->assertSame(6, $result['observed_v2_range_count']);
        $this->assertSame(2, $result['observed_legacy_count']);
        $this->assertSame([
            '/en/personality/big-five/high-openness',
            '/en/personality/big-five/low-openness',
            '/en/personality/big-five/openness-high',
            '/en/personality/big-five/openness-low',
            '/en/personality/big-five/openness-mid',
            '/zh/personality/big-five/openness-high',
            '/zh/personality/big-five/openness-low',
            '/zh/personality/big-five/openness-mid',
        ], $result['canonical_paths']);
        $this->assertTrue($result['raw_failures_preserved']);
        $this->assertTrue($result['skeptical_review_accounted']);
        $this->assertTrue($result['automated_gate_passed']);
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['publish_allowed']);
        $this->assertFalse($result['schema_eligible']);
        $this->assertFalse($result['writes_committed']);
        $this->assertFalse($result['cms_write_attempted']);
        $this->assertFalse($result['redirect_graph_mutation_attempted']);
        $this->assertFalse($result['indexability_mutation_attempted']);
        $this->assertFalse($result['search_submission_attempted']);
        $this->assertFalse($result['deploy_attempted']);
        $this->assertTrue($result['package_checks']['raw']['schema_ok']);
        $this->assertFalse($result['package_checks']['raw']['editorial_ok']);
        $this->assertSame([
            'legacy_intent_missing',
            'locale_not_independently_authored',
            'outline_incomplete',
            'template_or_cliche_detected',
            'visible_sources_incomplete',
        ], $result['package_checks']['raw']['issue_codes']);
        $this->assertTrue($result['package_checks']['repaired']['editorial_ok']);
        $this->assertTrue($result['package_checks']['final']['editorial_ok']);
    }

    public function test_final_package_preserves_v2_locale_pairs_and_distinct_legacy_intents(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $this->assertCount(8, $package['pages']);

        $v2Pairs = [];
        $legacyIntents = [];
        foreach ($package['pages'] as $page) {
            $this->assertSame('range', $page['page_family']);
            $this->assertSame('big_five', $page['framework']);
            $this->assertSame('openness', $page['domain_code']);
            $this->assertSame('independent_editorial', $page['authoring_mode']);
            $this->assertNull($page['source_locale']);
            $this->assertSame('draft_review_required', $page['status']);
            $this->assertSame('inference_requires_human_review', $page['editorial_claim_status']);
            $this->assertCount(2, $page['claims']);
            $this->assertCount(2, $page['visible_sources']);
            foreach (self::CONTENT_FIELDS as $field) {
                $this->assertNotEmpty($page[$field]);
            }

            if ($page['route_family'] === 'v2_range') {
                $v2Pairs[$page['range_level']][$page['locale']] = $page;
                $this->assertNull($page['legacy_intent']);
            } else {
                $this->assertSame('legacy_canonical', $page['route_family']);
                $this->assertSame('en', $page['locale']);
                $legacyIntents[] = $page['legacy_intent'];
            }
        }

        $this->assertSame(['high', 'middle', 'low'], array_keys($v2Pairs));
        foreach ($v2Pairs as $pair) {
            $this->assertArrayHasKey('en', $pair);
            $this->assertArrayHasKey('zh-CN', $pair);
            $this->assertNotSame($pair['en']['meaning'], $pair['zh-CN']['meaning']);
        }
        $this->assertSame([
            'idea_portfolio_to_decision_protocol',
            'evidence_threshold_for_change_adoption',
        ], $legacyIntents);
        $this->assertStringContainsString('selective pattern', $v2Pairs['middle']['en']['summary']);
        $this->assertStringContainsString('选择性模式', $v2Pairs['middle']['zh-CN']['summary']);
        $this->assertFalse($package['release_controls']['redirect_graph_change_allowed']);
        $this->assertFalse($package['release_controls']['indexability_change_allowed']);
    }

    public function test_unknown_scientific_claim_fails_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['claims'][0] = [
            'claim_id' => 'claim.unknown',
            'source_ids' => ['competitor.unapproved-range-source'],
        ];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('required_claim_set_mismatch', $codes);
        $this->assertContains('claim_unknown', $codes);
        $this->assertContains('final_package_failed', collect($result['issues'])->pluck('code')->all());
    }

    public function test_range_identity_value_hierarchy_middle_erasure_and_legacy_duplication_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['range_level'] = 'low';
        $package['pages'][0]['not_meaning'] .= ' High is better.';
        $package['pages'][3]['summary'] = 'This middle result has no strong traits and nothing distinctive about it.';

        $v2High = $package['pages'][0];
        $legacyHighIndex = 6;
        $package['pages'][$legacyHighIndex]['title'] = $v2High['title'];
        $package['pages'][$legacyHighIndex]['summary'] = $v2High['summary'];
        foreach (self::CONTENT_FIELDS as $field) {
            $package['pages'][$legacyHighIndex][$field] = $v2High[$field];
        }

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('range_identity_mismatch', $codes);
        $this->assertContains('value_hierarchy_detected', $codes);
        $this->assertContains('middle_range_erased', $codes);
        $this->assertContains('legacy_v2_near_duplicate', $codes);
    }

    public function test_private_flow_cross_framework_and_fabricated_release_state_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['communication_action'] .= ' /'.'orders/example?'.'session_id=demo MB'.'TI';
        $package['review_state']['reviewer'] = 'fabricated-reviewer';
        $package['review_state']['publish_allowed'] = true;
        $package['release_controls']['cms_write_allowed'] = true;
        $package['release_controls']['redirect_graph_change_allowed'] = true;
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
        $this->assertFalse($result['redirect_graph_mutation_attempted']);
        $this->assertFalse($result['deploy_attempted']);
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    private function runTemporaryFinal(array $package): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-range-openness-');
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
