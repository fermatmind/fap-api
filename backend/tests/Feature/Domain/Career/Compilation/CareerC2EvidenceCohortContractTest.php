<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Tests\TestCase;

final class CareerC2EvidenceCohortContractTest extends TestCase
{
    public function test_c2_authority_binds_one_control_and_five_difficulty_targets(): void
    {
        $root = base_path('content_assets/career/evidence/c2-first-five');
        $manifest = $this->readJson($root.'/manifest.json');
        foreach ($manifest['files'] as $file) {
            self::assertSame($file['sha256'], hash_file('sha256', $root.'/'.$file['path']));
        }

        $cohort = $this->readJson($root.'/cohort.json');
        $selection = $this->readJson($root.'/selection-report.json');
        self::assertCount(5, $cohort['target_slugs']);
        self::assertCount(6, $cohort['evidence_bound_slugs']);
        self::assertSame(1040, $cohort['expected_baseline_retained_slug_count']);
        self::assertSame(10, $cohort['expected_public_changed_locale_page_count']);
        self::assertSame(['accountants-and-auditors'], $cohort['control_slugs']);
        self::assertSame(['software-developers'], $cohort['manual_hold_slugs']);
        self::assertNotContains('software-developers', $cohort['evidence_bound_slugs']);
        self::assertSame(5, $selection['selected_target_count']);
        self::assertCount(5, array_unique(array_column($selection['selected'], 'difficulty_bucket')));
        self::assertSame($cohort['target_slugs'], array_column($selection['selected'], 'slug'));
        foreach (['database_writes', 'cache_writes', 'cms_writes', 'occupation_generation_writes',
            'sitemap_writes', 'discoverability_writes', 'search_submissions'] as $key) {
            self::assertSame(0, $selection[$key]);
        }

        $sources = [];
        foreach ($this->jsonLines($root.'/source-registry.jsonl') as $source) {
            self::assertArrayNotHasKey($source['source_key'], $sources);
            $sources[$source['source_key']] = $source;
        }
        $claimsBySlug = [];
        foreach ($this->jsonLines($root.'/claim-bindings.jsonl') as $claim) {
            self::assertContains($claim['canonical_slug'], $cohort['evidence_bound_slugs']);
            self::assertSame('approved', $claim['review_status']);
            self::assertSame([], $claim['blocker_codes']);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $claim['normalized_value_digest']);
            foreach ($claim['source_keys'] as $sourceKey) {
                self::assertArrayHasKey($sourceKey, $sources);
                self::assertSame($claim['market'], $sources[$sourceKey]['market']);
                self::assertSame($claim['locale'], $sources[$sourceKey]['locale']);
                self::assertSame($claim['captured_at'], $sources[$sourceKey]['captured_at']);
                self::assertSame($claim['effective_period'], $sources[$sourceKey]['effective_period']);
                self::assertGreaterThanOrEqual($claim['expires_at'], $sources[$sourceKey]['expires_at']);
            }
            $claimsBySlug[$claim['canonical_slug']][] = $claim['claim_key'];
        }
        self::assertCount(36, array_merge(...array_values($claimsBySlug)));
        foreach ($cohort['evidence_bound_slugs'] as $slug) {
            self::assertSame($cohort['required_claim_keys'], $claimsBySlug[$slug]);
        }

        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        self::assertCount(1046, $package['rows']);
        self::assertNotContains('software-developers', $package['slugs']);
        foreach ($cohort['evidence_bound_slugs'] as $slug) {
            self::assertSame(
                'exact_claim_binding',
                $package['rows'][$slug]['metadata_json']['ten_block_compilation_v1']['content_application'],
            );
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string,mixed>> */
    private function jsonLines(string $path): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        );
    }
}
