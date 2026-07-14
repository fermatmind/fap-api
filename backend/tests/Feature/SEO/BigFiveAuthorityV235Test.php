<?php

namespace Tests\Feature\SEO;

use App\Services\BigFive\AuthorityV2\LinkGraph\BigFiveAuthorityV2LinkGraphValidator;
use Tests\TestCase;

class BigFiveAuthorityV235Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-link-graph-35');
    }

    public function test_exact_candidate_graph_passes_backend_validator(): void
    {
        $graph = $this->readJson('link-graph.json');
        $report = app(BigFiveAuthorityV2LinkGraphValidator::class)->validate($graph);
        $this->assertTrue($report['ok']);
        $this->assertSame('pass', $report['status']);
        $this->assertSame(['nodes' => 231, 'edges' => 1199, 'hreflang_pairs' => 109, 'redirects' => 10, 'errors' => 0], $report['counts']);
        $this->assertFalse($report['writes_committed']);
        $this->assertFalse($report['cms_write_attempted']);
        $this->assertFalse($report['publish_attempted']);
        $this->assertFalse($report['indexability_attempted']);
        $this->assertFalse($report['sitemap_llms_schema_release_attempted']);
    }

    public function test_validator_fails_closed_on_dead_target_canonical_and_redirect_drift(): void
    {
        $validator = app(BigFiveAuthorityV2LinkGraphValidator::class);

        $dead = $this->readJson('link-graph.json');
        $dead['edges'][0]['target'] = '/en/personality/big-five/not-a-real-target';
        $deadReport = $validator->validate($dead);
        $this->assertFalse($deadReport['ok']);
        $this->assertContains('dead_edge', array_column($deadReport['errors'], 'code'));

        $canonical = $this->readJson('link-graph.json');
        $canonical['nodes'][0]['canonical_path'] = '/en/personality/big-five/wrong';
        $canonicalReport = $validator->validate($canonical);
        $this->assertFalse($canonicalReport['ok']);
        $this->assertContains('canonical_mismatch', array_column($canonicalReport['errors'], 'code'));

        $redirect = $this->readJson('link-graph.json');
        $redirect['redirects'][0]['status_code'] = 302;
        $redirectReport = $validator->validate($redirect);
        $this->assertFalse($redirectReport['ok']);
        $this->assertContains('invalid_zh_legacy_redirect', array_column($redirectReport['errors'], 'code'));
    }

    public function test_read_only_console_command_validates_default_graph(): void
    {
        $this->artisan('personality:big-five-authority-v2:validate-link-graph')
            ->expectsOutputToContain('ok=1')
            ->expectsOutputToContain('nodes=231')
            ->expectsOutputToContain('writes_committed=0')
            ->expectsOutputToContain('sitemap_llms_schema_release_attempted=0')
            ->assertSuccessful();
    }

    public function test_artifacts_prove_real_targets_hreflang_and_exact_zh_redirects(): void
    {
        $targets = $this->readJson('target-validation-report.json');
        $this->assertSame(231, $targets['counts']['candidate_nodes']);
        $this->assertSame(1199, $targets['counts']['internal_edges']);
        $this->assertSame(1199, $targets['counts']['validated_real_targets']);
        $this->assertSame(109, $targets['counts']['hreflang_pairs']);
        $this->assertSame(10, $targets['counts']['redirects']);
        foreach (['dead_edges', 'orphan_nodes', 'sink_nodes', 'self_links', 'cross_locale_edges', 'redirect_chains_or_cycles'] as $key) {
            $this->assertSame(0, $targets['counts'][$key]);
        }

        $overlap = $this->readJson('intent-overlap-report.json');
        $this->assertCount(10, $overlap['controls']);
        foreach ($overlap['controls'] as $control) {
            $this->assertSame('PASS_DISTINCT_INTENT', $control['cannibalization_control']);
            $this->assertSame('both self-canonical candidates with distinct intent', $control['canonical_policy']);
        }

        $qa = $this->readJson('qa_report.json');
        $this->assertSame('PASS_NO_RELEASE_MUTATION', $qa['status']);
        foreach ($qa['checks'] as $check) {
            $this->assertTrue($check);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
