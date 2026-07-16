<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class EnneagramPublicAuthorityV220LinkGraphTest extends TestCase
{
    private const PACKAGE_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-link-graph-20';

    private const LEDGER = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json';

    private const EXECUTION_BOUNDARY_KEYS = [
        'production_write_executed',
        'database_mutated',
        'cms_mutated',
        'api_runtime_changed',
        'frontend_asset_or_content_created',
        'url_created',
        'published',
        'indexability_changed',
        'sitemap_changed',
        'llms_changed',
        'search_submitted',
        'deployed',
    ];

    private const QA_CHECK_KEYS = [
        'exact_pr07_116_route_inventory',
        'exact_pr09_18_visible_faq_intents',
        'exact_pr19_media_route_parity',
        'unique_graph_ids_paths_and_canonicals',
        'all_internal_targets_resolve',
        'locale_preserving_internal_links',
        'reciprocal_en_zh_cn_hreflang',
        'consistent_en_x_default',
        'no_private_route',
        'bounded_entity_taxonomy_only',
        'no_unregistered_matrix_expansion',
        'no_dead_or_self_link',
        'no_orphan_or_sink_record',
        'backend_authority_only',
        'no_frontend_invented_graph',
        'release_deferred_to_pr22',
        'no_runtime_or_production_mutation',
    ];

    public function test_exact_graph_records_match_the_frozen_route_inventory(): void
    {
        $graph = $this->readJson(self::PACKAGE_DIR.'/link-graph.json');
        $records = collect($graph['graph_records'])->keyBy('graph_id');
        $expected = collect($this->readJson(self::LEDGER)['page_maps'])
            ->keyBy(fn (array $row): string => $row['locale'].'|'.$row['identity_key']);

        $this->assertSame('enneagram_public_authority_v2_link_graph.v1', $graph['schema_version']);
        $this->assertSame('CMS/backend_candidate', $graph['authority']);
        $this->assertCount(116, $records);
        $this->assertSame($expected->keys()->sort()->values()->all(), $records->keys()->sort()->values()->all());
        $this->assertSame(['en' => 58, 'zh-CN' => 58], $records->countBy('locale')->sortKeys()->all());
        $this->assertSame(
            ['center' => 6, 'core_type' => 18, 'hub' => 2, 'instinctual_subtype' => 54, 'wing' => 36],
            $records->countBy('entity_type')->sortKeys()->all(),
        );

        foreach ($records as $key => $record) {
            foreach (['identity_key', 'locale', 'entity_type', 'code', 'path'] as $field) {
                $this->assertSame($expected[$key][$field], $record[$field], "{$key}.{$field}");
            }
        }
    }

    public function test_all_internal_links_resolve_preserve_locale_and_have_declared_relationships(): void
    {
        $records = collect($this->readJson(self::PACKAGE_DIR.'/link-graph.json')['graph_records']);
        $byPath = $records->keyBy('path');
        $inbound = array_fill_keys($byPath->keys()->all(), 0);
        $linkCount = 0;
        $expectedCardinality = ['hub' => 12, 'center' => 4, 'core_type' => 7, 'wing' => 4, 'instinctual_subtype' => 4];

        foreach ($records as $record) {
            $this->assertCount($expectedCardinality[$record['entity_type']], $record['internal_links'], $record['graph_id']);
            $relationships = collect($record['entity_relationships'])
                ->keyBy(fn (array $link): string => $link['relationship'].'|'.$link['target_identity_key']);
            $this->assertCount(count($record['internal_links']), $relationships, $record['graph_id']);

            foreach ($record['internal_links'] as $link) {
                $linkCount++;
                $this->assertArrayHasKey($link['target_path'], $byPath->all(), $record['graph_id']);
                $target = $byPath[$link['target_path']];
                $this->assertSame($record['locale'], $link['locale'], $record['graph_id']);
                $this->assertSame($record['locale'], $target['locale'], $record['graph_id']);
                $this->assertNotSame($record['path'], $link['target_path'], $record['graph_id']);
                $this->assertSame('visible_contextual_navigation_candidate', $link['visibility'], $record['graph_id']);
                $relationshipKey = $link['relationship'].'|'.$link['target_identity_key'];
                $this->assertArrayHasKey($relationshipKey, $relationships->all(), $record['graph_id']);
                $this->assertSame($target['entity_type'], $relationships[$relationshipKey]['target_entity_type'], $record['graph_id']);
                $this->assertSame($target['code'], $relationships[$relationshipKey]['target_code'], $record['graph_id']);
                $inbound[$link['target_path']]++;
            }
        }

        $this->assertSame(534, $linkCount);
        foreach ($inbound as $path => $count) {
            $this->assertGreaterThan(0, $count, $path);
        }
    }

    public function test_canonical_hreflang_and_x_default_are_unique_reciprocal_and_consistent(): void
    {
        $records = collect($this->readJson(self::PACKAGE_DIR.'/link-graph.json')['graph_records']);
        $byKey = $records->keyBy('graph_id');
        $canonicals = [];
        $groups = [];

        foreach ($records as $record) {
            $key = $record['graph_id'];
            $canonical = $record['canonical'];
            $hreflang = $record['hreflang'];
            $en = $byKey['en|'.$record['identity_key']];
            $zh = $byKey['zh-CN|'.$record['identity_key']];

            $this->assertSame($record['path'], $canonical['path'], $key);
            $this->assertSame('self_canonical_backend_candidate', $canonical['mode'], $key);
            $this->assertSame($en['path'], $hreflang['en'], $key);
            $this->assertSame($zh['path'], $hreflang['zh-CN'], $key);
            $this->assertSame($en['path'], $hreflang['x-default'], $key);
            $this->assertTrue($hreflang['reciprocal'], $key);
            $this->assertSame($en['hreflang'], $zh['hreflang'], $key);
            $canonicals[] = $canonical['path'];
            $groups[] = $hreflang['translation_group'];
        }

        $this->assertCount(116, array_unique($canonicals));
        $this->assertCount(58, array_unique($groups));
    }

    public function test_faq_intents_reference_only_existing_visible_content(): void
    {
        $graph = $this->readJson(self::PACKAGE_DIR.'/link-graph.json');
        $assets = [];
        foreach ($graph['source_lineage'] as $source) {
            if (! str_ends_with($source['path'], '-draft.json')) {
                continue;
            }
            foreach ($this->readJson($source['path'])['assets'] as $asset) {
                $assets[$asset['locale'].'|'.$asset['identity_key']] = ['asset' => $asset, 'path' => $source['path']];
            }
        }

        $intentIds = [];
        foreach ($graph['graph_records'] as $record) {
            $key = $record['graph_id'];
            $this->assertArrayHasKey($key, $assets);
            $this->assertCount(3, $record['faq_intents'], $key);
            foreach ($record['faq_intents'] as $index => $intent) {
                $this->assertSame($assets[$key]['asset']['faqs'][$index]['question'], $intent['question'], $intent['intent_id']);
                $this->assertSame("faqs.{$index}.question", $intent['visible_question_path'], $intent['intent_id']);
                $this->assertSame("faqs.{$index}.answer", $intent['visible_answer_path'], $intent['intent_id']);
                $this->assertSame($assets[$key]['path'], $intent['source_asset_path'], $intent['intent_id']);
                $this->assertSame('deferred_to_pr22_visible_content_and_named_human_review', $intent['schema_eligibility'], $intent['intent_id']);
                $this->assertArrayNotHasKey('answer', $intent, $intent['intent_id']);
                $intentIds[] = $intent['intent_id'];
            }
        }

        $this->assertCount(348, $intentIds);
        $this->assertCount(348, array_unique($intentIds));
    }

    public function test_source_lineage_is_current_and_backend_authoritative(): void
    {
        $graph = $this->readJson(self::PACKAGE_DIR.'/link-graph.json');
        $this->assertCount(12, $graph['source_lineage']);

        foreach ($graph['source_lineage'] as $source) {
            $contents = file_get_contents(base_path($source['path']));
            $this->assertNotFalse($contents, $source['path']);
            $this->assertSame(hash('sha256', $contents), $source['sha256'], $source['path']);
            $this->assertStringStartsWith('docs/seo/personality/enneagram-authority-v2/', $source['path']);
        }

        foreach ($graph['graph_records'] as $record) {
            $this->assertSame('backend_cms_public_api_candidate', $record['authority'], $record['graph_id']);
            $this->assertFalse($record['release_truth']['frontend_graph_authority'], $record['graph_id']);
        }
    }

    public function test_graph_stays_inside_public_route_taxonomy_and_release_boundaries(): void
    {
        $graph = $this->readJson(self::PACKAGE_DIR.'/link-graph.json');
        $allowedEntities = ['hub', 'center', 'core_type', 'wing', 'instinctual_subtype'];
        $identityEntities = collect($graph['graph_records'])
            ->unique('identity_key')
            ->countBy('entity_type')
            ->sortKeys()
            ->all();

        $this->assertSame(['center' => 3, 'core_type' => 9, 'hub' => 1, 'instinctual_subtype' => 27, 'wing' => 18], $identityEntities);
        foreach ($graph['graph_records'] as $record) {
            $this->assertContains($record['entity_type'], $allowedEntities, $record['graph_id']);
            $this->assertMatchesRegularExpression('#^/(en|zh)/personality/enneagram(?:/|$)#', $record['path'], $record['graph_id']);
            $this->assertTrue($record['release_truth']['planning_only'], $record['graph_id']);
            foreach (['publish_eligible', 'indexability_changed', 'sitemap_changed', 'llms_changed', 'frontend_graph_authority'] as $field) {
                $this->assertFalse($record['release_truth'][$field], "{$record['graph_id']}.{$field}");
            }
        }
        $this->assertExecutionBoundariesAreExactAndFalse($graph['execution_boundaries']);
    }

    public function test_qa_report_proves_all_hard_gates_without_release_mutation(): void
    {
        $qa = $this->readJson(self::PACKAGE_DIR.'/qa-report.json');
        $this->assertSame('pass_planning_only_release_deferred', $qa['status']);
        $this->assertSame(116, $qa['counts']['graph_records']);
        $this->assertSame(534, $qa['counts']['validated_real_targets']);
        $this->assertSame(348, $qa['counts']['faq_intents']);
        foreach (['dead_links', 'cross_locale_links', 'private_routes', 'self_links', 'orphan_records', 'sink_records'] as $field) {
            $this->assertSame(0, $qa['counts'][$field], $field);
        }
        $this->assertSame(self::QA_CHECK_KEYS, array_keys($qa['checks']));
        foreach ($qa['checks'] as $field => $check) {
            $this->assertSame(true, $check, $field);
        }
        $this->assertExecutionBoundariesAreExactAndFalse($qa['execution_boundaries']);
    }

    public function test_mutation_oracle_rejects_dead_cross_locale_canonical_and_hreflang_drift(): void
    {
        $graph = $this->readJson(self::PACKAGE_DIR.'/link-graph.json');
        $this->assertSame([], $this->graphErrors($graph));

        $dead = $graph;
        $dead['graph_records'][0]['internal_links'][0]['target_path'] = '/en/personality/enneagram/not-registered';
        $this->assertContains('dead_target', $this->graphErrors($dead));

        $crossLocale = $graph;
        $crossLocale['graph_records'][0]['internal_links'][0]['locale'] = 'zh-CN';
        $this->assertContains('cross_locale_target', $this->graphErrors($crossLocale));

        $canonical = $graph;
        $canonical['graph_records'][0]['canonical']['path'] = '/en/personality/enneagram/wrong';
        $this->assertContains('canonical_mismatch', $this->graphErrors($canonical));

        $hreflang = $graph;
        $hreflang['graph_records'][0]['hreflang']['x-default'] = '/zh/personality/enneagram';
        $this->assertContains('hreflang_mismatch', $this->graphErrors($hreflang));
    }

    /** @param array<string, mixed> $boundaries */
    private function assertExecutionBoundariesAreExactAndFalse(array $boundaries): void
    {
        $this->assertSame(self::EXECUTION_BOUNDARY_KEYS, array_keys($boundaries));
        foreach ($boundaries as $key => $value) {
            $this->assertSame(false, $value, $key);
        }
    }

    /** @param array<string, mixed> $graph
     * @return list<string>
     */
    private function graphErrors(array $graph): array
    {
        $records = collect($graph['graph_records'] ?? [])->keyBy('graph_id');
        $paths = $records->keyBy('path');
        $errors = [];

        foreach ($records as $record) {
            if (($record['canonical']['path'] ?? null) !== ($record['path'] ?? null)) {
                $errors[] = 'canonical_mismatch';
            }
            $en = $records['en|'.$record['identity_key']] ?? null;
            $zh = $records['zh-CN|'.$record['identity_key']] ?? null;
            if (! is_array($en) || ! is_array($zh)
                || ($record['hreflang']['en'] ?? null) !== $en['path']
                || ($record['hreflang']['zh-CN'] ?? null) !== $zh['path']
                || ($record['hreflang']['x-default'] ?? null) !== $en['path']) {
                $errors[] = 'hreflang_mismatch';
            }
            foreach ($record['internal_links'] ?? [] as $link) {
                if (! $paths->has($link['target_path'] ?? '')) {
                    $errors[] = 'dead_target';

                    continue;
                }
                if (($link['locale'] ?? null) !== $record['locale'] || $paths[$link['target_path']]['locale'] !== $record['locale']) {
                    $errors[] = 'cross_locale_target';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents(base_path($path));
        $this->assertNotFalse($contents, $path);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
