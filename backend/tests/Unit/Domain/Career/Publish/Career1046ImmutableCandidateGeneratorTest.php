<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Career1046ImmutableCandidateGeneratorTest extends TestCase
{
    public function test_it_generates_exact_deterministic_1046_2092_candidate_with_discoverability_closed(): void
    {
        $fixture = $this->fixture();
        $candidate = $this->generator()->generate(...$fixture);
        $repeat = $this->generator()->generate(...$fixture);

        $this->assertSame($candidate, $repeat);
        $this->assertMatchesRegularExpression('/^career-1046-[0-9a-f]{32}$/', $candidate['generation_id']);
        $this->assertSame([
            'unique_slugs' => 1046,
            'locale_rows' => 2092,
            'published_slugs' => 1046,
            'published_locale_rows' => 2092,
            'missing' => 0,
            'duplicate' => 0,
            'outside_target' => 0,
        ], $candidate['counts']);
        $this->assertSame(
            Career1046ImmutableCandidateGenerator::TARGET_SET_SHA256,
            $candidate['authority']['target_slug_set_sha256'],
        );
        $this->assertSame(
            Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_SET_SHA256,
            $candidate['authority']['target_locale_row_set_sha256'],
        );
        $projection = $candidate['documents'][CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME];
        $this->assertCount(2092, $projection['items']);
        $this->assertSame(2092, $projection['counts']['published']);
        $this->assertSame(0, $projection['counts']['sitemap_live']);
        $this->assertSame(0, $projection['counts']['llms_live']);
        $this->assertNotContains(true, array_column($projection['items'], 'sitemap_live'));
        $this->assertNotContains(true, array_column($projection['items'], 'llms_live'));
        $this->assertFalse($candidate['candidate_receipt']['active_pointer_written']);
        $this->assertFalse($candidate['candidate_receipt']['published']);
        $this->assertFalse($candidate['candidate_receipt']['warmed']);
        $this->assertFalse($candidate['candidate_receipt']['production_workflow_triggered']);
    }

    public function test_it_materializes_no_clobber_candidate_without_an_active_pointer(): void
    {
        $candidate = $this->generator()->generate(...$this->fixture());
        $root = sys_get_temp_dir().'/career-1046-candidate-test-'.bin2hex(random_bytes(8));

        try {
            $receipt = $this->generator()->materializeImmutable($root, $candidate);

            $this->assertSame('candidates/'.$candidate['generation_id'], $receipt['candidate_relative_path']);
            $this->assertFalse($receipt['active_pointer_written']);
            $this->assertFileDoesNotExist($root.'/active-generation.json');
            $this->assertFileExists(
                $root.'/candidates/'.$candidate['generation_id'].'/generation-manifest.json',
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('career_1046_candidate_no_clobber');
            $this->generator()->materializeImmutable($root, $candidate);
        } finally {
            $this->removeFixtureRoot($root);
        }
    }

    public function test_it_rejects_any_receipt_covered_slug_without_matching_database_authority(): void
    {
        $fixture = $this->fixture();
        array_pop($fixture['databaseMatchingReceiptSlugs']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('career_1046_database_receipt_authority_set_mismatch');

        $this->generator()->generate(...$fixture);
    }

    public function test_it_rejects_1048_2096_and_the_two_outside_target_slugs(): void
    {
        $fixture = $this->fixture();
        $outsideRows = $this->ledgerRows(Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS);
        $fixture['ledger']['public_resolution']['rows'] = [
            ...$fixture['ledger']['public_resolution']['rows'],
            ...$outsideRows,
        ];
        $outsideProjection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray([
            'public_resolution' => ['rows' => $outsideRows],
        ]);
        $fixture['projection']['items'] = [
            ...$fixture['projection']['items'],
            ...$outsideProjection['items'],
        ];
        foreach (Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $fixture['detailRows'][] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'payload' => ['title' => $slug.'-'.$locale],
                ];
            }
        }

        $this->assertCount(1048, $fixture['ledger']['public_resolution']['rows']);
        $this->assertCount(2096, $fixture['projection']['items']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('career_1046_ledger_contains_forbidden_slug');

        $this->generator()->generate(...$fixture);
    }

    public function test_it_rejects_duplicate_or_locale_incomplete_product_rows(): void
    {
        $fixture = $this->fixture();
        array_pop($fixture['detailRows']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('career_1046_detail_locale_rows_set_mismatch');

        $this->generator()->generate(...$fixture);
    }

    private function generator(): Career1046ImmutableCandidateGenerator
    {
        return new Career1046ImmutableCandidateGenerator;
    }

    /**
     * @return array{
     *   manifestPath:string,
     *   baselineAuthoritySlugs:list<string>,
     *   databaseMatchingReceiptSlugs:list<string>,
     *   ledger:array<string,mixed>,
     *   projection:array<string,mixed>,
     *   detailRows:list<array<string,mixed>>
     * }
     */
    private function fixture(): array
    {
        $manifestPath = dirname(__DIR__, 5).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $baseline = $manifest['baseline_slugs'];
        $receipts = $manifest['delta_slugs'];
        $target = array_values(array_unique([...$baseline, ...$receipts]));
        sort($target, SORT_STRING);
        $ledger = [
            'ledger_kind' => CareerFullReleaseLedgerService::LEDGER_KIND,
            'ledger_version' => 'career.release_ledger.1046.candidate.v1',
            'scope' => 'career_exact_1046',
            'public_resolution' => [
                'rows' => $this->ledgerRows($target),
            ],
        ];
        $projection = (new CareerRuntimePublishProjectionService)->buildFromLedgerArray($ledger);
        $details = [];
        foreach ($target as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $details[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'payload' => [
                        'identity' => ['canonical_slug' => $slug],
                        'titles' => ['canonical' => $slug.'-'.$locale],
                    ],
                ];
            }
        }

        return [
            'manifestPath' => $manifestPath,
            'baselineAuthoritySlugs' => $baseline,
            'databaseMatchingReceiptSlugs' => $receipts,
            'ledger' => $ledger,
            'projection' => $projection,
            'detailRows' => $details,
        ];
    }

    /** @param list<string> $slugs @return list<array<string,mixed>> */
    private function ledgerRows(array $slugs): array
    {
        return array_map(static fn (string $slug): array => [
            'source_slug' => $slug,
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'public_eligible' => true,
            'indexability' => 'indexable',
        ], $slugs);
    }

    private function removeFixtureRoot(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
