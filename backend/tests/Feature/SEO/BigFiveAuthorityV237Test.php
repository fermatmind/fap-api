<?php

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BigFiveAuthorityV237Test extends TestCase
{
    private string $packageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageDir = dirname(__DIR__, 4).'/generated/big-five-authority-v2/big5-authority-v2-release-gate-37';
    }

    public function test_aggregate_manifest_has_exact_deterministic_inventory(): void
    {
        $manifest = $this->readJson('aggregate-manifest.json');

        $this->assertSame('dry_run_only', $manifest['release_mode']);
        $this->assertSame(231, $manifest['exact_counts']['assets']);
        $this->assertSame(231, $manifest['exact_counts']['canonical_routes']);
        $this->assertSame(10, $manifest['exact_counts']['exact_301_aliases']);
        $this->assertSame(109, $manifest['exact_counts']['reciprocal_bilingual_pairs']);
        $this->assertSame(10, $manifest['exact_counts']['source_ledger_entries']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['package_sha256']);

        $material = '';
        foreach ($manifest['input_files'] as $entry) {
            $absolute = dirname(__DIR__, 4).'/'.$entry['path'];
            $this->assertFileExists($absolute);
            $this->assertSame($entry['bytes'], filesize($absolute));
            $this->assertSame($entry['sha256'], hash_file('sha256', $absolute));
            $material .= $entry['path']."\t".$entry['bytes']."\t".$entry['sha256']."\n";
        }
        $this->assertSame($manifest['package_sha256'], hash('sha256', $material));
    }

    public function test_every_asset_passes_schema_source_duplicate_bilingual_and_private_contracts(): void
    {
        $report = $this->readJson('per-page-release-report.json');
        $assets = $report['assets'];

        $this->assertCount(231, $assets);
        $this->assertCount(231, array_unique(array_column($assets, 'asset_id')));
        $this->assertCount(231, array_unique(array_column($assets, 'route')));
        foreach ($assets as $asset) {
            $this->assertTrue($asset['schema_valid']);
            $this->assertTrue($asset['source_record_valid']);
            $this->assertTrue($asset['duplicate_and_intent_valid']);
            $this->assertTrue($asset['private_boundary_valid']);
            $this->assertSame($asset['route'], $asset['canonical_path']);
            $this->assertContains($asset['bilingual_identity'], ['real_reciprocal_pair', 'not_applicable_no_real_counterpart']);
            $this->assertDoesNotMatchRegularExpression('#/(attempts|reports|orders|payments|results)(/|$)#', $asset['route']);
        }
    }

    public function test_release_and_indexability_are_decided_per_page_and_fail_closed(): void
    {
        $assets = $this->readJson('per-page-release-report.json')['assets'];

        foreach ($assets as $asset) {
            $this->assertFalse($asset['publish_eligible']);
            $this->assertFalse($asset['indexability_eligible']);
            $this->assertFalse($asset['sitemap_eligible']);
            $this->assertFalse($asset['llms_eligible']);
            $this->assertFalse($asset['llms_full_eligible']);
            $this->assertNotEmpty($asset['blockers']);
            $this->assertContains('author_reviewer_date', $asset['blockers']);
            $this->assertContains('media_authority', $asset['blockers']);
        }
    }

    public function test_local_test_database_dry_run_plans_exact_counts_and_writes_zero_rows(): void
    {
        $process = new Process(['php', $this->packageDir.'/local-test-db-dry-run.php']);
        $process->mustRun();
        $measurement = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(231, $measurement['candidate_count']);
        $this->assertSame(231, $measurement['planned_create_count']);
        $this->assertSame(0, $measurement['planned_update_count']);
        $this->assertSame(0, $measurement['executed_insert_count']);
        $this->assertSame(0, $measurement['executed_update_count']);
        $this->assertSame(0, $measurement['executed_delete_count']);
        $this->assertSame(0, $measurement['measured_database_write_delta']);
        $this->assertTrue($measurement['transaction_rolled_back']);
        $this->assertFalse($measurement['production_connection_used']);
    }

    public function test_authorization_packet_is_reconciled_and_executable_for_draft_only_writer(): void
    {
        $packet = $this->readJson('production-authorization-packet.json');
        $qa = $this->readJson('qa_report.json');

        $this->assertSame('GO_DRAFT_ONLY_PRODUCTION_IMPORT_AUTHORIZED_PENDING_EXACT_PREFLIGHT', $packet['status']);
        $this->assertSame('af99ac41406a2967b9f4778dc9da07b920bfbb7f', $packet['pr37_merge_sha']);
        $this->assertSame(231, $packet['asset_count']);
        $this->assertSame(231, $packet['local_test_empty_baseline_counts']['create']);
        $this->assertSame(0, $packet['local_test_empty_baseline_counts']['update']);
        $this->assertSame(231, $packet['canonical_count']);
        $this->assertSame(10, $packet['alias_301_count']);
        $this->assertStringContainsString('personality:big-five-authority-v2-draft-import', $packet['write_workflow']['production_command']);
        $this->assertTrue($packet['approval_phrase_currently_executable']);
        $this->assertStringContainsString($packet['package_sha256'], $packet['exact_approval_phrase_template']);
        $this->assertNotEmpty($packet['abort_boundaries']);
        $this->assertSame('PASS_DRAFT_ONLY_WRITER_AUTHORIZED_NO_PUBLIC_RELEASE', $qa['status']);
        foreach ($qa['checks'] as $check) {
            $this->assertTrue($check);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packageDir.'/'.$file);
        $this->assertNotFalse($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
