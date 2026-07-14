<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV235Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-link-graph-35');
    }

    public function test_exact_candidate_graph_passes_package_validator(): void
    {
        $graph = $this->readJson('link-graph.json');
        $this->assertCount(231, $graph['nodes']);
        $this->assertCount(1199, $graph['edges']);
        $this->assertCount(109, $graph['hreflang_pairs']);
        $this->assertCount(10, $graph['redirects']);

        $output = [];
        $exitCode = 1;
        exec('node '.escapeshellarg($this->packagePath.'/validate-package.mjs').' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertStringContainsString('Big Five PR35 validation passed', implode("\n", $output));
    }

    public function test_validator_fails_closed_on_dead_target_canonical_and_redirect_drift(): void
    {
        $dead = $this->readJson('link-graph.json');
        $dead['edges'][0]['target'] = '/en/personality/big-five/not-a-real-target';
        $this->assertContains('dead_edge', $this->validationErrorCodes($dead));

        $canonical = $this->readJson('link-graph.json');
        $canonical['nodes'][0]['canonical_path'] = '/en/personality/big-five/wrong';
        $this->assertContains('canonical_mismatch', $this->validationErrorCodes($canonical));

        $redirect = $this->readJson('link-graph.json');
        $redirect['redirects'][0]['status_code'] = 302;
        $this->assertContains('invalid_zh_legacy_redirect', $this->validationErrorCodes($redirect));
    }

    public function test_validator_contract_is_read_only_and_release_closed(): void
    {
        $graph = $this->readJson('link-graph.json');
        $qa = $this->readJson('qa_report.json');

        $this->assertSame('none_planning_and_validation_only', $graph['release_effect']);
        $this->assertSame('PASS_NO_RELEASE_MUTATION', $qa['status']);
        $this->assertTrue($qa['checks']['eligibility_deferred_to_pr36']);
        $this->assertTrue($qa['checks']['no_sitemap_llms_schema_or_indexability_release']);
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

    /**
     * Minimal independent mutation oracle for the fail-closed fixtures above.
     * The complete graph contract is enforced by validate-package.mjs.
     *
     * @param  array<string, mixed>  $graph
     * @return list<string>
     */
    private function validationErrorCodes(array $graph): array
    {
        $nodes = (array) ($graph['nodes'] ?? []);
        $routes = [];
        $errors = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $route = (string) ($node['route'] ?? '');
            $routes[$route] = true;
            if (($node['canonical_path'] ?? null) !== $route) {
                $errors[] = 'canonical_mismatch';
            }
        }

        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            if (! is_array($edge)
                || ! isset($routes[(string) ($edge['source'] ?? '')], $routes[(string) ($edge['target'] ?? '')])) {
                $errors[] = 'dead_edge';
            }
        }

        foreach ((array) ($graph['redirects'] ?? []) as $redirect) {
            if (! is_array($redirect)
                || ($redirect['status_code'] ?? null) !== 301
                || ($redirect['exact_match'] ?? null) !== true
                || ($redirect['hop_count'] ?? null) !== 1
                || ! isset($routes[(string) ($redirect['target'] ?? '')])) {
                $errors[] = 'invalid_zh_legacy_redirect';
            }
        }

        return array_values(array_unique($errors));
    }
}
