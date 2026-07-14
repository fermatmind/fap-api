<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\LinkGraph;

final class BigFiveAuthorityV2LinkGraphValidator
{
    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function validate(array $graph): array
    {
        $errors = [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        $hreflangPairs = is_array($graph['hreflang_pairs'] ?? null) ? $graph['hreflang_pairs'] : [];
        $redirects = is_array($graph['redirects'] ?? null) ? $graph['redirects'] : [];
        $byRoute = [];

        if (($graph['schema_version'] ?? null) !== 'big5-authority-v2-link-graph.v1') {
            $errors[] = $this->error('schema_version', 'invalid_schema', 'Expected big5-authority-v2-link-graph.v1.');
        }
        if (($graph['release_effect'] ?? null) !== 'none_planning_and_validation_only') {
            $errors[] = $this->error('release_effect', 'release_boundary_violation', 'PR35 must not release sitemap, llms, schema, or indexability.');
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = $this->error("nodes.{$index}", 'invalid_node', 'Node must be an object.');

                continue;
            }
            $route = (string) ($node['route'] ?? '');
            if ($route === '' || isset($byRoute[$route])) {
                $errors[] = $this->error("nodes.{$index}.route", 'duplicate_or_empty_route', 'Node routes must be non-empty and unique.');

                continue;
            }
            $byRoute[$route] = $node;
            if (($node['canonical_path'] ?? null) !== $route || ($node['canonical_mode'] ?? null) !== 'self_canonical_candidate') {
                $errors[] = $this->error("nodes.{$index}.canonical_path", 'canonical_mismatch', 'Candidate canonical path must be self-consistent.');
            }
            if (($node['authority'] ?? null) !== 'CMS/backend_candidate' || ($node['release_eligibility'] ?? null) !== 'deferred_to_pr36') {
                $errors[] = $this->error("nodes.{$index}.authority", 'authority_boundary_violation', 'Authority must remain CMS/backend candidate with eligibility deferred to PR36.');
            }
        }

        $inbound = array_fill_keys(array_keys($byRoute), 0);
        $outbound = array_fill_keys(array_keys($byRoute), 0);
        $edgeKeys = [];
        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                $errors[] = $this->error("edges.{$index}", 'invalid_edge', 'Edge must be an object.');

                continue;
            }
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            $edgeKey = $source.'>'.$target;
            if (! isset($byRoute[$source]) || ! isset($byRoute[$target])) {
                $errors[] = $this->error("edges.{$index}", 'dead_edge', 'Every edge source and target must be a real candidate route.');

                continue;
            }
            if ($source === $target || isset($edgeKeys[$edgeKey])) {
                $errors[] = $this->error("edges.{$index}", 'self_or_duplicate_edge', 'Self-links and duplicate source-target edges are prohibited.');

                continue;
            }
            $edgeKeys[$edgeKey] = true;
            if (($byRoute[$source]['locale'] ?? null) !== ($byRoute[$target]['locale'] ?? null)) {
                $errors[] = $this->error("edges.{$index}", 'cross_locale_navigation', 'Navigation edges must preserve locale.');
            }
            $inbound[$target]++;
            $outbound[$source]++;
        }
        foreach ($byRoute as $route => $_node) {
            if (($inbound[$route] ?? 0) === 0) {
                $errors[] = $this->error('nodes', 'orphan_node', "No inbound edge for {$route}.");
            }
            if (($outbound[$route] ?? 0) === 0) {
                $errors[] = $this->error('nodes', 'sink_node', "No outbound edge for {$route}.");
            }
        }

        $translationGroups = [];
        foreach ($hreflangPairs as $index => $pair) {
            if (! is_array($pair)) {
                $errors[] = $this->error("hreflang_pairs.{$index}", 'invalid_hreflang_pair', 'Hreflang pair must be an object.');

                continue;
            }
            $group = (string) ($pair['translation_group'] ?? '');
            $en = (string) ($pair['en'] ?? '');
            $zh = (string) ($pair['zh-CN'] ?? '');
            if ($group === '' || isset($translationGroups[$group])) {
                $errors[] = $this->error("hreflang_pairs.{$index}", 'duplicate_hreflang_group', 'Hreflang translation groups must be non-empty and unique.');
            }
            $translationGroups[$group] = true;
            if (! isset($byRoute[$en], $byRoute[$zh])
                || ($byRoute[$en]['locale'] ?? null) !== 'en'
                || ($byRoute[$zh]['locale'] ?? null) !== 'zh-CN'
                || ($pair['x_default'] ?? null) !== $en
                || ($pair['reciprocal'] ?? null) !== true) {
                $errors[] = $this->error("hreflang_pairs.{$index}", 'invalid_hreflang_target', 'Hreflang targets must be real, locale-correct, reciprocal candidates.');
            }
        }

        $expectedRedirects = $this->expectedZhRedirects();
        $redirectSources = [];
        foreach ($redirects as $index => $redirect) {
            if (! is_array($redirect)) {
                $errors[] = $this->error("redirects.{$index}", 'invalid_redirect', 'Redirect must be an object.');

                continue;
            }
            $source = (string) ($redirect['source'] ?? '');
            $target = (string) ($redirect['target'] ?? '');
            $redirectSources[$source] = true;
            if (($expectedRedirects[$source] ?? null) !== $target
                || ($redirect['status_code'] ?? null) !== 301
                || ($redirect['exact_match'] ?? null) !== true
                || ($redirect['hop_count'] ?? null) !== 1
                || isset($byRoute[$source])
                || ! isset($byRoute[$target])) {
                $errors[] = $this->error("redirects.{$index}", 'invalid_zh_legacy_redirect', 'ZH legacy aliases must be exact single-hop 301 redirects to the locked V2 candidate.');
            }
        }
        if (count($redirects) !== 10 || array_diff_key($expectedRedirects, $redirectSources) !== []) {
            $errors[] = $this->error('redirects', 'redirect_inventory_mismatch', 'Exactly ten locked zh legacy redirects are required.');
        }

        $legacyNodes = array_filter($nodes, fn (mixed $node): bool => is_array($node) && ($node['intent_class'] ?? null) === 'legacy_polarity_explainer');
        if (count($legacyNodes) !== 10 || array_filter($legacyNodes, fn (array $node): bool => ($node['locale'] ?? null) !== 'en' || ($node['navigation_visibility'] ?? null) !== 'compatibility_only') !== []) {
            $errors[] = $this->error('nodes', 'legacy_intent_boundary_mismatch', 'Exactly ten EN legacy polarity nodes must remain compatibility-only and distinct from V2 ranges.');
        }

        return [
            'ok' => $errors === [],
            'status' => $errors === [] ? 'pass' : 'fail',
            'artifact' => 'BIG5-AUTHORITY-V2-LINK-GRAPH-35',
            'counts' => [
                'nodes' => count($nodes),
                'edges' => count($edges),
                'hreflang_pairs' => count($hreflangPairs),
                'redirects' => count($redirects),
                'errors' => count($errors),
            ],
            'errors' => $errors,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'indexability_attempted' => false,
            'sitemap_llms_schema_release_attempted' => false,
        ];
    }

    /** @return array<string, string> */
    private function expectedZhRedirects(): array
    {
        return [
            '/zh/personality/big-five/emotional-stability' => '/zh/personality/big-five/neuroticism-low',
            '/zh/personality/big-five/high-agreeableness' => '/zh/personality/big-five/agreeableness-high',
            '/zh/personality/big-five/high-conscientiousness' => '/zh/personality/big-five/conscientiousness-high',
            '/zh/personality/big-five/high-extraversion' => '/zh/personality/big-five/extraversion-high',
            '/zh/personality/big-five/high-neuroticism' => '/zh/personality/big-five/neuroticism-high',
            '/zh/personality/big-five/high-openness' => '/zh/personality/big-five/openness-high',
            '/zh/personality/big-five/low-agreeableness' => '/zh/personality/big-five/agreeableness-low',
            '/zh/personality/big-five/low-conscientiousness' => '/zh/personality/big-five/conscientiousness-low',
            '/zh/personality/big-five/low-extraversion' => '/zh/personality/big-five/extraversion-low',
            '/zh/personality/big-five/low-openness' => '/zh/personality/big-five/openness-low',
        ];
    }

    /** @return array{field:string,code:string,message:string} */
    private function error(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
