<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceAnalyzer;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceBoundaryGuard;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use Tests\TestCase;

final class SeoPlatform11G3CompetitiveAnalyzerTest extends TestCase
{
    public function test_replay_is_byte_deterministic_and_input_order_independent(): void
    {
        $input = $this->input();
        $first = app(CompetitiveEvidenceAnalyzer::class)->analyze($input);
        $input['projections'] = array_reverse($input['projections']);
        $input['source_policies'] = array_reverse($input['source_policies']);
        $second = app(CompetitiveEvidenceAnalyzer::class)->analyze($input);

        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR),
            json_encode($second, JSON_THROW_ON_ERROR),
        );
        $this->assertSame('READY', $first['status']);
        $this->assertSame('necessary', $first['11i_handoff']['page_necessity']);
        $this->assertSame('yes', $first['11i_handoff']['translation_only']);
        $this->assertSame(10000, $first['11i_handoff']['template_similarity']);
        $this->assertTrue(app(CompetitiveEvidenceBoundaryGuard::class)->finding($first['findings'][0]));
        $this->assertTrue(app(CompetitiveEvidenceBoundaryGuard::class)->handoff($first['11i_handoff']));
        $this->assertTrue(app(CompetitiveEvidenceBoundaryGuard::class)->output($first));
    }

    public function test_similarity_uses_fixed_integer_weights_and_lcs(): void
    {
        $input = $this->input();
        $input['projections'][1] = $this->projection('fermatmind-zh', 'fermatmind_public', 'zh-CN', ['definition', 'faq'], []);
        $output = app(CompetitiveEvidenceAnalyzer::class)->analyze($input);

        $this->assertSame([
            'module_set_bp' => 2666,
            'module_order_bp' => 2000,
            'entity_relation_bp' => 2000,
            'internal_link_pattern_bp' => 1000,
        ], $output['11i_handoff']['template_similarity_components']);
        $this->assertSame(7666, $output['11i_handoff']['template_similarity']);
        $this->assertSame('no', $output['11i_handoff']['translation_only']);
    }

    public function test_single_source_cannot_create_gaps_or_necessary_decision(): void
    {
        $input = $this->input();
        $input['projections'] = array_values(array_filter(
            $input['projections'],
            static fn (array $projection): bool => $projection['source_id'] !== 'competitor-b',
        ));
        $output = app(CompetitiveEvidenceAnalyzer::class)->analyze($input);

        $this->assertSame([], $output['findings'][0]['structure_gaps']);
        $this->assertSame([], $output['findings'][0]['entity_gaps']);
        $this->assertSame([], $output['findings'][0]['information_gain']);
        $this->assertSame('conditional', $output['11i_handoff']['page_necessity']);
        $this->assertSame('HOLD', $output['status']);
        $this->assertContains('MULTI_SOURCE_EVIDENCE_HOLD', $output['11i_handoff']['hold_reasons']);
    }

    public function test_stale_or_conflicting_required_evidence_holds_unknown(): void
    {
        $stale = $this->input();
        $stale['source_policies'][0]['freshness_state'] = 'expired';
        $staleOutput = app(CompetitiveEvidenceAnalyzer::class)->analyze($stale);
        $this->assertSame('HOLD', $staleOutput['status']);
        $this->assertSame('unknown', $staleOutput['11i_handoff']['page_necessity']);
        $this->assertSame('unknown', $staleOutput['11i_handoff']['translation_only']);
        $this->assertSame('expired', $staleOutput['11i_handoff']['source_freshness']);

        $conflict = $this->input();
        $conflict['authority']['conflict'] = true;
        $conflictOutput = app(CompetitiveEvidenceAnalyzer::class)->analyze($conflict);
        $this->assertSame('conflict', $conflictOutput['11i_handoff']['source_freshness']);
        $this->assertContains('SOURCE_CONFLICT_HOLD', $conflictOutput['11i_handoff']['hold_reasons']);
    }

    public function test_vendor_claim_is_never_upgraded_and_body_copy_cannot_enter_output(): void
    {
        $input = $this->input();
        $output = app(CompetitiveEvidenceAnalyzer::class)->analyze($input);
        $claim = $output['findings'][0]['competitor_claims'][0];

        $this->assertSame('competitor_claim', $claim['claim_class']);
        $this->assertFalse($claim['fact_upgrade_allowed']);
        $encoded = json_encode($output, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('body', $encoded);
        $this->assertStringNotContainsString('snippet', $encoded);
        $this->assertStringNotContainsString('marketing promise', $encoded);
        $this->assertSame(0, $output['model_calls']);
        $this->assertSame(0, $output['tool_calls']);
        $this->assertSame(0, $output['external_calls']);
        $this->assertFalse($output['execution_allowed']);
    }

    public function test_unknown_entity_is_not_promoted_to_a_gap(): void
    {
        $input = $this->input();
        foreach ([2, 3] as $index) {
            $input['projections'][$index]['structure']['entity_ids'][] = 'entity.unregistered';
            $input['projections'][$index]['structure']['entity_relations'][] = [
                'entity_id' => 'entity.unregistered',
                'relation' => 'measures',
                'target_id' => 'schema.quiz',
                'relation_hash' => str_repeat('9', 64),
            ];
            $input['projections'][$index] = $this->seal($input['projections'][$index], 'projection_hash');
        }
        $output = app(CompetitiveEvidenceAnalyzer::class)->analyze($input);

        $this->assertNotContains('entity.unregistered', array_column($output['findings'][0]['entity_gaps'], 'entity_id'));
        $this->assertContains('UNKNOWN_ENTITY_SIGNAL', $output['findings'][0]['unknowns']);
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $relation = [[
            'entity_id' => 'entity.big-five',
            'relation' => 'measures',
            'target_id' => 'schema.quiz',
            'relation_hash' => str_repeat('7', 64),
        ]];

        return [
            'page_family' => 'tests',
            'locale' => 'zh-CN',
            'projections' => [
                $this->projection('fermatmind-en', 'fermatmind_public', 'en', ['hero', 'definition', 'faq'], []),
                $this->projection('fermatmind-zh', 'fermatmind_public', 'zh-CN', ['hero', 'definition', 'faq'], []),
                $this->projection('competitor-a', 'competitor_public', 'en', ['hero', 'definition', 'dimensions', 'faq'], $relation, true),
                $this->projection('competitor-b', 'competitor_public', 'en', ['hero', 'definition', 'dimensions', 'faq'], $relation, true),
            ],
            'source_policies' => [
                ['source_id' => 'competitor-b', 'freshness_state' => 'fresh', 'policy_hash' => str_repeat('b', 64)],
                ['source_id' => 'competitor-a', 'freshness_state' => 'fresh', 'policy_hash' => str_repeat('a', 64)],
            ],
            'authority' => [
                'source_hash' => str_repeat('c', 64),
                'freshness_state' => 'fresh',
                'conflict' => false,
                'owner_gap_confirmed' => true,
                'modules' => ['definition', 'faq', 'hero'],
                'entity_relations' => [],
                'information_ids' => [],
            ],
            'measurement' => [
                'source_hash' => str_repeat('d', 64),
                'freshness_state' => 'fresh',
                'conflict' => false,
                'demand_windows' => [true, true, false],
            ],
            'dependency_ingestion' => ['external_reads' => 0],
        ];
    }

    /** @param list<string> $modules @param list<array<string,mixed>> $relations @return array<string,mixed> */
    private function projection(string $sourceId, string $sourceClass, string $locale, array $modules, array $relations, bool $claim = false): array
    {
        $moduleRecords = [];
        foreach ($modules as $ordinal => $module) {
            $moduleRecords[] = [
                'module_type' => $module,
                'ordinal' => $ordinal,
                'module_hash' => app(SeoEvidenceCanonicalHasher::class)->hash([$module, $ordinal]),
            ];
        }
        $pattern = [
            'from_family' => 'tests',
            'relation' => 'related',
            'to_family' => 'personality',
            'count_bucket' => '2-3',
        ];
        $pattern['pattern_hash'] = app(SeoEvidenceCanonicalHasher::class)->hash($pattern);
        $structure = [
            'headings' => [],
            'modules' => $moduleRecords,
            'schema_types' => ['WebPage'],
            'entity_ids' => $relations === [] ? [] : ['entity.big-five'],
            'entity_relations' => $relations,
            'claim_signals' => $claim ? [['claim_id' => 'claim.vendor-rating', 'claim_hash' => str_repeat('8', 64)]] : [],
            'canonical_hash' => str_repeat('1', 64),
            'hreflang' => [],
            'internal_link_patterns' => [$pattern],
        ];
        $structure['structure_fingerprint'] = app(SeoEvidenceCanonicalHasher::class)->hash($structure);
        $projection = [
            'version' => 'seo.competitive_page_projection.v2',
            'source_id' => $sourceId,
            'cohort_id' => 'competitive.big-five.live.v1',
            'source_class' => $sourceClass,
            'page_family' => 'tests',
            'locale' => $locale,
            'public_url_hash' => str_repeat('2', 64),
            'source_policy_ref' => [
                'policy_id' => 'policy.'.$sourceId,
                'policy_version' => 1,
                'policy_hash' => str_repeat('3', 64),
                'status' => 'approved',
                'expires_at' => '2026-09-30T00:00:00Z',
            ],
            'capture' => [
                'captured_at' => '2026-09-01T00:00:00Z',
                'response_hash' => str_repeat('4', 64),
                'content_type' => 'text/html',
                'response_bytes' => 100,
                'http_status' => 200,
                'robots_decision' => 'allowed',
                'terms_decision' => 'approved',
                'license_decision' => 'public_structure_permitted',
            ],
            'structure' => $structure,
            'redaction' => [
                'raw_html_retained' => false,
                'competitor_snippets_retained' => false,
                'private_data_present' => false,
                'login_or_paywall_detected' => false,
                'injection_scan_result' => 'pass',
            ],
        ];

        return $this->seal($projection, 'projection_hash');
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function seal(array $value, string $field): array
    {
        unset($value[$field]);
        $value[$field] = app(SeoEvidenceCanonicalHasher::class)->hash($value);

        return $value;
    }
}
