<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Tests\TestCase;

final class EnneagramPublicAuthorityV207SourceLedgerTest extends TestCase
{
    private const PACKAGE_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07';

    private const SCORECARD = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json';

    public function test_single_registry_has_required_classes_and_complete_limitations(): void
    {
        $registry = $this->readJson(self::PACKAGE_DIR.'/source-registry.json');
        $this->assertSame(['systematic_review', 'primary_empirical', 'measurement_documentation', 'traditional_theory', 'competitor_intent', 'editorial_synthesis', 'blocked_or_unverified'], $registry['source_categories']);
        $this->assertCount(1, glob(base_path(self::PACKAGE_DIR.'/source-registry.json')) ?: []);
        $sources = collect($registry['sources']);
        $this->assertSame($registry['source_categories'], $sources->pluck('category')->unique()->values()->all());
        foreach ($sources as $source) {
            $this->assertNotEmpty($source['title']);
            $this->assertNotEmpty($source['authors_or_organization']);
            $this->assertIsInt($source['year']);
            $this->assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}$/', $source['accessed_at']);
            $this->assertNotEmpty($source['supported_use']);
            $this->assertNotEmpty($source['limitation']);
            $this->assertNotEmpty($source['page_families']);
        }
    }

    public function test_scientific_sources_are_traceable_and_other_classes_cannot_be_science(): void
    {
        $sources = collect($this->readJson(self::PACKAGE_DIR.'/source-registry.json')['sources']);
        $scientific = $sources->whereIn('category', ['systematic_review', 'primary_empirical', 'measurement_documentation']);
        $this->assertCount(4, $scientific);
        foreach ($scientific as $source) {
            $this->assertNotEmpty($source['doi']);
            $this->assertSame('https://doi.org/'.$source['doi'], $source['public_url']);
            $this->assertTrue($source['scientific_evidence_eligible']);
            $this->assertTrue($source['sole_scientific_evidence_eligible']);
            $this->assertFalse($source['competitor']);
        }
        foreach ($sources->whereIn('category', ['traditional_theory', 'competitor_intent', 'editorial_synthesis', 'blocked_or_unverified']) as $source) {
            $this->assertFalse($source['scientific_evidence_eligible']);
            $this->assertFalse($source['sole_scientific_evidence_eligible']);
        }
    }

    public function test_internal_source_paths_share_the_backend_root_and_resolve(): void
    {
        $sources = collect($this->readJson(self::PACKAGE_DIR.'/source-registry.json')['sources'])
            ->whereNotNull('internal_path');

        $this->assertNotEmpty($sources);

        foreach ($sources as $source) {
            $this->assertFileExists(
                base_path($source['internal_path']),
                "Internal source path does not resolve for {$source['id']}"
            );
        }
    }

    public function test_truity_is_competitor_intent_only(): void
    {
        $registry = $this->readJson(self::PACKAGE_DIR.'/source-registry.json');
        $source = collect($registry['sources'])->firstWhere('id', 'truity_benchmark_registry_2026');
        $this->assertSame('competitor_intent', $source['category']);
        $this->assertTrue($source['competitor']);
        $this->assertFalse($source['scientific_evidence_eligible']);
        foreach ($registry['claims'] as $claim) {
            if ($claim['classification'] === 'competitor_intent_only') {
                $this->assertFalse($claim['allowed_as_public_claim']);
            } else {
                $this->assertNotContains('truity_benchmark_registry_2026', $claim['primary_source_ids']);
            }
        }
    }

    public function test_claims_are_bilingual_limited_and_resolve(): void
    {
        $registry = $this->readJson(self::PACKAGE_DIR.'/source-registry.json');
        $sources = collect($registry['sources'])->keyBy('id');
        foreach ($registry['claims'] as $claim) {
            $this->assertNotEmpty($claim['allowed_summary_en']);
            $this->assertNotEmpty($claim['allowed_summary_zh_cn']);
            $this->assertNotEmpty($claim['limitation']);
            $this->assertTrue($claim['requires_human_review']);
            foreach ($claim['source_ids'] as $sourceId) {
                $this->assertTrue($sources->has($sourceId), "Missing source {$sourceId}");
            }
            foreach ($claim['primary_source_ids'] as $sourceId) {
                $source = $sources->get($sourceId);
                $this->assertContains($source['category'], ['systematic_review', 'primary_empirical', 'measurement_documentation']);
                $this->assertTrue($source['scientific_evidence_eligible']);
                $this->assertFalse($source['competitor']);
            }
        }
    }

    public function test_exactly_116_maps_match_frozen_scorecard(): void
    {
        $maps = $this->readJson(self::PACKAGE_DIR.'/page-claim-maps.json');
        $scorecard = $this->readJson(self::SCORECARD);
        $expected = collect($scorecard['rows'])->mapWithKeys(fn (array $row): array => [
            $row['locale'].'|'.$row['identity_key'] => array_intersect_key($row, array_flip(['locale', 'identity_key', 'entity_type', 'code', 'path'])),
        ]);
        $this->assertSame(116, $maps['target_count']);
        $this->assertCount(116, $maps['page_maps']);
        $this->assertCount(116, collect($maps['page_maps'])->unique(fn (array $map): string => $map['locale'].'|'.$map['identity_key']));
        foreach ($maps['page_maps'] as $map) {
            $key = $map['locale'].'|'.$map['identity_key'];
            $this->assertTrue($expected->has($key));
            $this->assertSame($expected->get($key), array_intersect_key($map, $expected->get($key)));
        }
    }

    public function test_pages_have_factual_mappings_limits_and_blocks(): void
    {
        $registry = $this->readJson(self::PACKAGE_DIR.'/source-registry.json');
        $maps = $this->readJson(self::PACKAGE_DIR.'/page-claim-maps.json');
        $claims = collect($registry['claims'])->keyBy('id');
        foreach ($maps['page_maps'] as $map) {
            $this->assertNotEmpty($map['factual_claim_ids']);
            $this->assertNotEmpty($map['limitations']);
            $this->assertNotEmpty($map['blocked_claim_ids']);
            $this->assertSame('evidence_limited_requires_human_review', $map['evidence_status']);
            $this->assertSame([], $map['competitor_source_ids']);
            foreach ($map['claim_ids'] as $claimId) {
                $this->assertTrue($claims->has($claimId));
                $this->assertNotEmpty($claims->get($claimId)['limitation']);
            }
            foreach ($map['factual_claim_ids'] as $claimId) {
                $this->assertTrue($claims->get($claimId)['factual']);
            }
            foreach ($map['blocked_claim_ids'] as $claimId) {
                $this->assertSame('blocked_or_unverified', $claims->get($claimId)['classification']);
                $this->assertFalse($claims->get($claimId)['allowed_as_public_claim']);
            }
        }
    }

    public function test_zero_allowed_factual_claims_are_unmapped(): void
    {
        $registry = $this->readJson(self::PACKAGE_DIR.'/source-registry.json');
        $maps = $this->readJson(self::PACKAGE_DIR.'/page-claim-maps.json');
        $mapped = collect($maps['page_maps'])->flatMap(fn (array $map): array => $map['factual_claim_ids'])->unique();
        $allowed = collect($registry['claims'])->filter(fn (array $claim): bool => $claim['factual'] && $claim['allowed_as_public_claim'])->pluck('id');
        $this->assertEmpty($allowed->diff($mapped));
        $this->assertEmpty($mapped->diff($allowed));
        $this->assertNotContains('claim.competitor.intent_only', $mapped);
    }

    public function test_wing_and_subtype_limits_are_explicit(): void
    {
        $maps = collect($this->readJson(self::PACKAGE_DIR.'/page-claim-maps.json')['page_maps']);
        $wings = $maps->where('entity_type', 'wing');
        $subtypes = $maps->where('entity_type', 'instinctual_subtype');
        $this->assertCount(36, $wings);
        $this->assertCount(54, $subtypes);
        foreach ($wings as $map) {
            $this->assertContains('claim.wing.traditional_secondary_hypothesis', $map['factual_claim_ids']);
            $this->assertStringContainsString('little research', implode(' ', $map['limitations']));
        }
        foreach ($subtypes as $map) {
            $this->assertContains('claim.subtype.traditional_secondary_hypothesis', $map['factual_claim_ids']);
            $this->assertStringContainsString('instrument-specific', strtolower(implode(' ', $map['limitations'])));
        }
    }

    public function test_blocked_assumptions_and_non_mutation_are_explicit(): void
    {
        $registry = $this->readJson(self::PACKAGE_DIR.'/source-registry.json');
        $maps = $this->readJson(self::PACKAGE_DIR.'/page-claim-maps.json');
        $blocked = file_get_contents(base_path(self::PACKAGE_DIR.'/BLOCKED_OR_UNVERIFIED_ASSUMPTIONS.md'));
        $this->assertStringContainsString('Unknown', $blocked);
        $this->assertFalse($registry['authority']['runtime_authority']);
        $this->assertFalse($registry['authority']['publish_authority']);
        $this->assertFalse($registry['authority']['indexability_authority']);
        $this->assertSame([false], array_values(array_unique($registry['safety_boundaries'])));
        $this->assertSame([false], array_values(array_unique($maps['execution_boundaries'])));
    }

    public function test_package_has_no_private_routes_or_unsupported_marketing_claims(): void
    {
        $contents = file_get_contents(base_path(self::PACKAGE_DIR.'/source-registry.json')).file_get_contents(base_path(self::PACKAGE_DIR.'/page-claim-maps.json'));
        $this->assertDoesNotMatchRegularExpression('#(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|private)(?:/|\\?)#i', $contents);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:orderNo|order_id|resultId|attemptId|reportId|payment_id|transaction_id|auth_token|session_id|share_id)\\b/i', $contents);
        foreach (['absolutely accurate', 'most accurate personality test', 'guaranteed career', 'official partnership'] as $claim) {
            $this->assertStringNotContainsString($claim, strtolower($contents));
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(file_get_contents(base_path($path)) ?: '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
