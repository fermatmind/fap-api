<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class BigFiveAuthorityV205Test extends TestCase
{
    private const PACKAGE_DIR = '../generated/big-five-authority-v2/big5-authority-v2-source-ledger-05';

    public function test_source_categories_and_academic_metadata_are_complete(): void
    {
        $ledger = $this->readJson(self::PACKAGE_DIR.'/source-ledger.json');

        $this->assertSame([
            'academic_evidence',
            'official_product_evidence',
            'competitor_evidence',
            'internal_repository_evidence',
            'inference',
        ], $ledger['evidence_categories']);

        $academic = collect($ledger['sources'])->where('evidence_category', 'academic_evidence');
        $this->assertCount(5, $academic);

        foreach ($academic as $source) {
            $this->assertNotEmpty($source['title']);
            $this->assertNotEmpty($source['authors_or_organization']);
            $this->assertIsInt($source['year']);
            $this->assertNotEmpty($source['doi']);
            $this->assertSame('https://doi.org/'.$source['doi'], $source['public_url']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $source['access_date']);
            $this->assertNotEmpty($source['supported_claim']);
            $this->assertNotEmpty($source['limitation']);
            $this->assertNotEmpty($source['applicable_page_families']);
            $this->assertTrue($source['core_scientific_evidence_eligible']);
        }
    }

    public function test_claims_resolve_to_allowed_sources_and_core_claims_have_primary_academic_evidence(): void
    {
        $ledger = $this->readJson(self::PACKAGE_DIR.'/source-ledger.json');
        $sources = collect($ledger['sources'])->keyBy('id');

        foreach ($ledger['claims'] as $claim) {
            $this->assertNotEmpty($claim['allowed_summary_en']);
            $this->assertNotEmpty($claim['allowed_summary_zh_cn']);
            $this->assertNotEmpty($claim['limitation']);

            foreach ($claim['source_ids'] as $sourceId) {
                $this->assertTrue($sources->has($sourceId), "Missing source {$sourceId} for {$claim['id']}");
            }

            if ($claim['classification'] !== 'core_scientific') {
                continue;
            }

            $this->assertNotEmpty($claim['primary_source_ids']);
            foreach ($claim['primary_source_ids'] as $sourceId) {
                $source = $sources->get($sourceId);
                $this->assertSame('academic_evidence', $source['evidence_category']);
                $this->assertTrue($source['core_scientific_evidence_eligible']);
            }
        }
    }

    public function test_competitor_repository_and_inference_records_cannot_be_sole_scientific_evidence(): void
    {
        $ledger = $this->readJson(self::PACKAGE_DIR.'/source-ledger.json');

        $nonScientific = collect($ledger['sources'])->whereIn('evidence_category', [
            'competitor_evidence',
            'internal_repository_evidence',
            'inference',
        ]);
        $this->assertNotEmpty($nonScientific);

        foreach ($nonScientific as $source) {
            $this->assertFalse($source['core_scientific_evidence_eligible']);
            $this->assertFalse($source['sole_scientific_evidence_eligible']);
        }

        $inference = collect($ledger['claims'])->firstWhere('classification', 'editorial_inference');
        $this->assertSame('inference_requires_review', $inference['status']);
        $this->assertTrue($inference['requires_human_review']);
        $this->assertFalse($inference['allowed_as_public_claim']);
    }

    public function test_bilingual_terminology_preserves_conceptual_parity_and_framework_boundaries(): void
    {
        $terminology = $this->readJson(self::PACKAGE_DIR.'/terminology-ledger.json');

        $this->assertSame(['en', 'zh-CN'], $terminology['locale_pair']);
        $this->assertTrue($terminology['translation_policy']['conceptual_parity_required']);
        $this->assertFalse($terminology['translation_policy']['word_for_word_translation_required']);
        $this->assertTrue($terminology['translation_policy']['locale_independent_editorial_review_required']);
        $this->assertTrue($terminology['translation_policy']['framework_names_must_not_be_silently_equated']);

        $terms = collect($terminology['terms'])->keyBy('id');
        $this->assertSame('侧面', $terms['term.facet']['canonical_zh_cn']);
        $this->assertSame('方面', $terms['term.aspect']['canonical_zh_cn']);
        $this->assertSame('神经质', $terms['term.neuroticism']['canonical_zh_cn']);
        $this->assertStringContainsString('not a medical diagnosis', $terms['term.neuroticism']['boundary']);

        foreach ($terms as $term) {
            $this->assertNotEmpty($term['canonical_en']);
            $this->assertNotEmpty($term['canonical_zh_cn']);
            $this->assertNotEmpty($term['definition_en']);
            $this->assertNotEmpty($term['definition_zh_cn']);
            $this->assertNotEmpty($term['boundary']);
        }
    }

    public function test_package_is_preparation_only_and_keeps_all_operational_gates_closed(): void
    {
        $ledger = $this->readJson(self::PACKAGE_DIR.'/source-ledger.json');
        $qa = $this->readJson(self::PACKAGE_DIR.'/qa_report.json');

        $this->assertFalse($ledger['authority']['runtime_authority']);
        $this->assertFalse($ledger['authority']['publish_authority']);
        $this->assertFalse($ledger['authority']['indexability_authority']);
        $this->assertSame([false], array_values(array_unique($ledger['safety_boundaries'])));
        $this->assertSame('pass', $qa['outcome']);
        $this->assertFalse($qa['runtime_authority_changed']);
        $this->assertFalse($qa['cms_mutation_executed']);
        $this->assertFalse($qa['production_write_executed']);
        $this->assertFalse($qa['deploy_executed']);
        $this->assertFalse($qa['indexability_mutation_executed']);
        $this->assertFalse($qa['schema_activation_executed']);
        $this->assertFalse($qa['search_submission_executed']);
    }

    public function test_package_contains_no_private_routes_or_unsupported_marketing_claims(): void
    {
        $contents = file_get_contents(base_path(self::PACKAGE_DIR.'/source-ledger.json'))
            .file_get_contents(base_path(self::PACKAGE_DIR.'/terminology-ledger.json'));

        $this->assertDoesNotMatchRegularExpression('#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i', $contents);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\\b/i', $contents);
        $this->assertStringNotContainsString('absolutely accurate', strtolower($contents));
        $this->assertStringNotContainsString('most accurate personality test', strtolower($contents));
        $this->assertStringNotContainsString('guaranteed career', strtolower($contents));
        $this->assertStringNotContainsString('official partnership', strtolower($contents));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(base_path($path)) ?: '', true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
