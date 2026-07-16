<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV222ReleaseGate;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Tests\TestCase;

final class EnneagramPublicAuthorityV222ReleaseGateTest extends TestCase
{
    private const PACKAGE_DIR = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22';

    private const EXECUTION_BOUNDARY_KEYS = [
        'production_write_executed',
        'database_mutated',
        'cms_mutated',
        'revision_pointer_changed',
        'media_uploaded',
        'cache_revalidated',
        'indexability_changed',
        'sitemap_changed',
        'llms_changed',
        'search_submitted',
        'deployed',
    ];

    public function test_release_gate_aggregates_the_exact_estate_and_holds_only_for_human_review(): void
    {
        $report = $this->report();

        $this->assertSame('ENNEAGRAM-PUBLIC-AUTHORITY-V2-RELEASE-GATE-22', $report['artifact']);
        $this->assertSame('hold_missing_human_review', $report['status']);
        $this->assertSame('HOLD', $report['decision']);
        $this->assertFalse($report['ok']);
        $this->assertTrue($report['automated_gate_passed']);
        $this->assertFalse($report['human_review_passed']);
        $this->assertTrue($report['media_boundary_passed']);
        $this->assertFalse($report['release_eligible']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['package_sha256']);

        $this->assertSame([
            'identities' => 58,
            'assets' => 116,
            'locales' => ['en' => 58, 'zh-CN' => 58],
            'entities' => ['center' => 6, 'core_type' => 18, 'hub' => 2, 'instinctual_subtype' => 54, 'wing' => 36],
            'source_mappings' => 116,
            'qa_rows' => 116,
            'editorial_integrity_qa_rows' => 116,
            'graph_records' => 116,
            'unique_canonicals' => 116,
            'empty_media_authority_count' => 116,
            'media_write_count' => 0,
            'pre_write_public_fingerprints' => 116,
            'named_human_reviews' => 0,
            'approved_human_reviews' => 0,
            'rejected_human_reviews' => 0,
            'missing_human_reviews' => 116,
        ], $report['counts']);

        $this->assertCount(116, $report['asset_records']);
        $this->assertCount(116, array_unique(array_column($report['asset_records'], 'asset_key')));
        $this->assertCount(116, array_unique(array_column($report['asset_records'], 'path')));
        $this->assertCount(116, array_unique(array_column($report['asset_records'], 'asset_sha256')));
        $this->assertCount(116, $report['pre_write_public_fingerprints']);
        $this->assertCount(116, array_unique(array_column($report['pre_write_public_fingerprints'], 'pre_write_public_sha256')));
        $this->assertCount(116, $report['missing_human_reviews']);
        $this->assertSame([
            'contract' => ['hero' => null, 'inline' => [], 'og' => null],
            'target_count' => 116,
            'valid_count' => 116,
            'non_empty_asset_keys' => [],
            'media_write_count' => 0,
            'media_library_write_performed' => false,
        ], $report['empty_media_authority']);
        $this->assertSame([], array_values(array_filter(
            $report['source_hashes'],
            static fn (array $source): bool => str_contains((string) $source['path'], 'enneagram-public-authority-v2-media-og-19'),
        )));

        $this->assertSame(['sitemap' => 116, 'llms_txt' => 116, 'llms_full_txt' => 116], array_intersect_key(
            $report['discoverability_inventory'],
            array_flip(['sitemap', 'llms_txt', 'llms_full_txt']),
        ));
        $this->assertFalse($report['discoverability_inventory']['mutation_performed']);
        $this->assertTrue($report['rollback_readiness']['complete']);
        $this->assertFalse($report['rollback_readiness']['rollback_command_executed']);
        $this->assertFalse($report['exact_production_command_plan']['executed']);
        $this->assertSame(self::EXECUTION_BOUNDARY_KEYS, array_keys($report['execution_boundaries']));
        $this->assertSame([false], array_values(array_unique($report['execution_boundaries'])));
    }

    public function test_full_aggregate_editorial_gate_passes_all_116_assets_without_duplicates(): void
    {
        $report = $this->report();
        $issues = $report['editorial_integrity_gate']['issues'];

        $this->assertSame('ready_for_human_review', $report['editorial_integrity_gate']['status']);
        $this->assertTrue($report['editorial_integrity_gate']['automated_gate_passed']);
        $this->assertSame(116, $report['editorial_integrity_gate']['qa_row_count']);
        $this->assertSame([], $issues);
        $this->assertSame([], $report['errors']);
    }

    public function test_named_review_records_are_hash_bound_and_cannot_override_other_gates(): void
    {
        $initial = $this->report();
        $reviews = array_map(static fn (array $asset): array => [
            'asset_key' => $asset['asset_key'],
            'reviewer' => 'Release reviewer',
            'reviewed_at' => '2026-07-16T00:00:00Z',
            'asset_sha256' => $asset['asset_sha256'],
            'decision' => 'approved',
        ], $initial['asset_records']);
        $reviewed = $this->reportWithReviews($reviews);

        $this->assertTrue($reviewed['human_review_passed']);
        $this->assertSame(116, $reviewed['counts']['named_human_reviews']);
        $this->assertSame(116, $reviewed['counts']['approved_human_reviews']);
        $this->assertSame(0, $reviewed['counts']['missing_human_reviews']);
        $this->assertTrue($reviewed['automated_gate_passed']);
        $this->assertTrue($reviewed['media_boundary_passed']);
        $this->assertTrue($reviewed['release_eligible']);
        $this->assertSame('pass', $reviewed['status']);
        $this->assertSame($initial['package_sha256'], $reviewed['package_sha256']);

        $reviews[0]['asset_sha256'] = str_repeat('0', 64);
        $invalid = $this->reportWithReviews($reviews);
        $this->assertFalse($invalid['human_review_passed']);
        $this->assertSame(115, $invalid['counts']['named_human_reviews']);
        $this->assertSame(1, $invalid['counts']['missing_human_reviews']);
        $this->assertContains('manual_review_invalid', array_column($invalid['errors'], 'code'));
    }

    public function test_frozen_report_matches_the_read_only_gate_and_command_returns_hold(): void
    {
        $this->assertSame($this->report(), $this->readJson(self::PACKAGE_DIR.'/release-gate-report.json'));

        $this->artisan('personality:enneagram-authority-v2-integrity-gate', ['--release-gate' => true, '--json' => true])
            ->expectsOutputToContain('"decision": "HOLD"')
            ->assertFailed();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->releaseGate()->evaluate(
            base_path(),
            self::PACKAGE_DIR.'/manual-review-register.json',
        );
    }

    /** @param list<array<string, mixed>> $reviews @return array<string, mixed> */
    private function reportWithReviews(array $reviews): array
    {
        $path = tempnam(sys_get_temp_dir(), 'enneagram-v2-reviews-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode(['reviews' => $reviews], JSON_THROW_ON_ERROR));

        try {
            return $this->releaseGate()->evaluate(base_path(), $path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents(base_path($path));
        $this->assertNotFalse($contents, $path);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function releaseGate(): EnneagramPublicAuthorityV222ReleaseGate
    {
        $integrityGate = app(EnneagramPublicAuthorityV2IntegrityGate::class);

        return new EnneagramPublicAuthorityV222ReleaseGate($integrityGate);
    }
}
