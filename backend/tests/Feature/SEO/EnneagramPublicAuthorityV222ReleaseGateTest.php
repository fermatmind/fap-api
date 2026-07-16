<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV222ReleaseGate;
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

    public function test_release_gate_aggregates_the_exact_estate_and_fails_closed_on_real_blockers(): void
    {
        $report = $this->report();

        $this->assertSame('ENNEAGRAM-PUBLIC-AUTHORITY-V2-RELEASE-GATE-22', $report['artifact']);
        $this->assertSame('fail_closed', $report['status']);
        $this->assertSame('HOLD', $report['decision']);
        $this->assertFalse($report['ok']);
        $this->assertFalse($report['automated_gate_passed']);
        $this->assertFalse($report['human_review_passed']);
        $this->assertFalse($report['media_rights_review_passed']);
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
            'media_originals' => 58,
            'media_mappings' => 116,
            'pre_write_public_fingerprints' => 116,
            'named_human_reviews' => 0,
            'approved_human_reviews' => 0,
            'rejected_human_reviews' => 0,
            'missing_human_reviews' => 116,
            'pending_media_rights_reviews' => 116,
        ], $report['counts']);

        $this->assertCount(116, $report['asset_records']);
        $this->assertCount(116, array_unique(array_column($report['asset_records'], 'asset_key')));
        $this->assertCount(116, array_unique(array_column($report['asset_records'], 'path')));
        $this->assertCount(116, array_unique(array_column($report['asset_records'], 'asset_sha256')));
        $this->assertCount(116, $report['pre_write_public_fingerprints']);
        $this->assertCount(116, array_unique(array_column($report['pre_write_public_fingerprints'], 'pre_write_public_sha256')));
        $this->assertCount(58, $report['media_manifest_records']);
        $this->assertCount(58, array_unique(array_column($report['media_manifest_records'], 'record_sha256')));
        $this->assertCount(116, $report['missing_human_reviews']);
        $this->assertCount(116, $report['pending_media_rights_review_asset_keys']);

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

    public function test_full_aggregate_editorial_gate_exposes_the_two_cross_family_duplicate_blockers(): void
    {
        $report = $this->report();
        $issues = $report['editorial_integrity_gate']['issues'];

        $this->assertSame('fail_closed', $report['editorial_integrity_gate']['status']);
        $this->assertFalse($report['editorial_integrity_gate']['automated_gate_passed']);
        $this->assertSame(116, $report['editorial_integrity_gate']['qa_row_count']);
        $this->assertCount(2, $issues);
        $this->assertSame(['duplicate_sentence'], array_values(array_unique(array_column($issues, 'code'))));
        $this->assertSame([
            'en|instinctual_subtype:type-4/social',
            'en|instinctual_subtype:type-9/one-to-one',
        ], array_column($issues, 'asset_key'));
        $this->assertSame([
            'en|instinctual_subtype:type-3/social',
            'en|instinctual_subtype:type-5/social',
        ], array_column($issues, 'duplicate_of_asset_key'));
        $this->assertSame([
            'editorial_integrity_duplicate_sentence',
            'editorial_integrity_duplicate_sentence',
            'editorial_integrity_gate_failed',
        ], array_column($report['errors'], 'code'));
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
        $this->assertFalse($reviewed['automated_gate_passed']);
        $this->assertFalse($reviewed['media_rights_review_passed']);
        $this->assertFalse($reviewed['release_eligible']);
        $this->assertSame('fail_closed', $reviewed['status']);
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

        $this->artisan('personality:enneagram-authority-v2-release-gate', ['--json' => true])
            ->expectsOutputToContain('"decision": "HOLD"')
            ->assertFailed();
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(EnneagramPublicAuthorityV222ReleaseGate::class)->evaluate(
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
            return app(EnneagramPublicAuthorityV222ReleaseGate::class)->evaluate(base_path(), $path);
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
}
