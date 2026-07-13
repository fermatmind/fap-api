<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BigFiveAuthorityV209Test extends TestCase
{
    private const DIR = 'generated/big-five-authority-v2/big5-authority-v2-facet-hubs-09';

    /** @var array<string, list<string>> */
    private const FACETS = [
        'openness' => ['imagination', 'aesthetics', 'feelings', 'actions', 'ideas', 'values'],
        'conscientiousness' => ['competence', 'order', 'dutifulness', 'achievement-striving', 'self-discipline', 'deliberation'],
        'extraversion' => ['warmth', 'gregariousness', 'assertiveness', 'activity', 'excitement-seeking', 'positive-emotions'],
        'agreeableness' => ['trust', 'straightforwardness', 'altruism', 'compliance', 'modesty', 'tender-mindedness'],
        'neuroticism' => ['anxiety', 'anger', 'depression', 'self-consciousness', 'impulsiveness', 'vulnerability'],
    ];

    public function test_package_workflow_passes_with_two_hubs_and_closed_release_gates(): void
    {
        $result = $this->runGate(expectSuccess: true);

        $this->assertTrue($result['ok'], json_encode($result['issues'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('pass', $result['status']);
        $this->assertSame(2, $result['expected_page_count']);
        $this->assertSame(2, $result['observed_final_page_count']);
        $this->assertSame([
            '/en/personality/big-five/facets',
            '/zh/personality/big-five/facets',
        ], $result['canonical_paths']);
        $this->assertSame([5, 5], $result['observed_domain_groups_per_page']);
        $this->assertSame([30, 30], $result['observed_facet_links_per_page']);
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
            'facet_navigation_incomplete',
            'locale_not_independently_authored',
            'outline_incomplete',
            'template_or_cliche_detected',
            'visible_sources_incomplete',
        ], $result['package_checks']['raw']['issue_codes']);
        $this->assertTrue($result['package_checks']['repaired']['editorial_ok']);
        $this->assertTrue($result['package_checks']['final']['editorial_ok']);
    }

    public function test_final_hubs_group_the_frozen_inventory_with_locale_safe_navigation(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $this->assertCount(2, $package['pages']);

        foreach ($package['pages'] as $page) {
            $this->assertSame('big-five-facet-hub', $page['content_key']);
            $this->assertSame('facet_hub', $page['page_family']);
            $this->assertSame('big_five', $page['framework']);
            $this->assertSame('independent_editorial', $page['authoring_mode']);
            $this->assertNull($page['source_locale']);
            $this->assertSame('draft_review_required', $page['status']);
            $this->assertSame('inference_requires_human_review', $page['editorial_claim_status']);
            $this->assertCount(5, $page['domain_groups']);
            $this->assertSame(array_keys(self::FACETS), array_column($page['domain_groups'], 'domain_code'));
            $this->assertCount(2, $page['claims']);
            $this->assertCount(2, $page['visible_sources']);

            $localePrefix = $page['locale'] === 'en' ? 'en' : 'zh';
            $facetHrefs = [];
            foreach ($page['domain_groups'] as $group) {
                $this->assertSame("/{$localePrefix}/personality/big-five/{$group['domain_code']}", $group['domain_href']);
                $this->assertSame(self::FACETS[$group['domain_code']], array_column($group['facets'], 'code'));
                $this->assertCount(6, $group['facets']);
                foreach ($group['facets'] as $facet) {
                    $this->assertSame("/{$localePrefix}/personality/big-five/facets/{$facet['code']}", $facet['href']);
                    $facetHrefs[] = $facet['href'];
                }
            }
            $this->assertCount(30, $facetHrefs);
            $this->assertCount(30, array_unique($facetHrefs));
        }

        $this->assertNotSame($package['pages'][0]['title'], $package['pages'][1]['title']);
        $this->assertNotSame($package['pages'][0]['definition'], $package['pages'][1]['definition']);
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
            'source_ids' => ['competitor.unapproved-facet-source'],
        ];

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('required_claim_set_mismatch', $codes);
        $this->assertContains('claim_unknown', $codes);
        $this->assertContains('final_package_failed', collect($result['issues'])->pluck('code')->all());
    }

    public function test_domain_facet_and_locale_navigation_drift_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['domain_groups'][0]['facets'][0]['code'] = 'invented-facet';
        $package['pages'][0]['domain_groups'][1]['facets'][0]['href'] = $package['pages'][0]['domain_groups'][0]['facets'][1]['href'];
        $package['pages'][1]['domain_groups'][0]['domain_href'] = '/en/personality/big-five/openness';

        $result = $this->runTemporaryFinal($package);
        $codes = collect($result['package_checks']['final']['issues'])->pluck('code')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('facet_inventory_mismatch', $codes);
        $this->assertContains('facet_navigation_invalid', $codes);
        $this->assertContains('facet_coverage_mismatch', $codes);
        $this->assertContains('facet_href_duplicate', $codes);
        $this->assertContains('domain_navigation_invalid', $codes);
    }

    public function test_private_flow_cross_framework_and_fabricated_release_state_fail_closed(): void
    {
        $package = $this->readJson(self::DIR.'/final-package.json');
        $package['pages'][0]['how_to_use'] .= ' /'.'orders/example?'.'session_id=demo MB'.'TI';
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
        $this->assertFalse($result['human_review_passed']);
        $this->assertFalse($result['writes_committed']);
        $this->assertFalse($result['deploy_attempted']);
    }

    /** @param array<string, mixed> $package @return array<string, mixed> */
    private function runTemporaryFinal(array $package): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big5-authority-v2-facet-hubs-');
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
