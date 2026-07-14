<?php

namespace Tests\Feature\SEO;

use Tests\TestCase;

class BigFiveAuthorityV234Test extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('../generated/big-five-authority-v2/big5-authority-v2-media-og-34');
    }

    public function test_media_authority_audit_finds_no_eligible_big_five_asset(): void
    {
        $audit = $this->readJson('media-authority-audit.json');
        $this->assertSame('read_only_repository_authority', $audit['audit_mode']);
        $this->assertSame('Unknown_not_queried', $audit['production_runtime_media_inventory']);
        $this->assertCount(7, $audit['eligibility_gates']);
        $this->assertSame([6, 224, 16], array_column($audit['baseline_summary'], 'asset_count'));
        $this->assertSame([0, 0, 0], array_column($audit['baseline_summary'], 'eligible_big_five_count'));
        $this->assertSame(246, $audit['totals']['audited_repository_baseline_assets']);
        $this->assertSame(240, $audit['totals']['published_public_baseline_assets']);
        $this->assertSame(16, $audit['totals']['explicit_operator_approval_evidence_assets']);
        $this->assertSame(0, $audit['totals']['big_five_semantic_matches']);
        $this->assertSame(0, $audit['totals']['eligible_big_five_assets']);
        $this->assertSame('NO_APPROVED_BIG_FIVE_MEDIA_FOUND_FAIL_CLOSED', $audit['decision']);
    }

    public function test_every_pr07_through_pr33_candidate_has_three_missing_pending_slots(): void
    {
        $map = $this->readJson('candidate-media-map.json');
        $this->assertCount(231, $map['mappings']);
        $this->assertCount(231, array_unique(array_column($map['mappings'], 'candidate_key')));
        $this->assertCount(231, array_unique(array_column($map['mappings'], 'route')));
        $this->assertSame(['en' => 119, 'zh-CN' => 112], array_count_values(array_column($map['mappings'], 'locale')));
        foreach ($map['mappings'] as $mapping) {
            $this->assertSame('missing_pending', $mapping['mapping_status']);
            $this->assertSame(['hero', 'inline', 'og'], array_column($mapping['slots'], 'slot'));
            foreach ($mapping['slots'] as $slot) {
                $this->assertSame('missing_pending', $slot['status']);
                $this->assertNull($slot['media_asset_key']);
                $this->assertNull($slot['variant_key']);
                $this->assertNull($slot['public_url']);
                $this->assertNull($slot['alt']);
                $this->assertNull($slot['rights']);
                $this->assertNull($slot['provenance']);
                $this->assertNull($slot['operator_approval_ref']);
            }
            $this->assertFalse($mapping['cms_write_executed']);
            $this->assertFalse($mapping['media_upload_executed']);
            $this->assertFalse($mapping['publish_state_change']);
            $this->assertFalse($mapping['indexability_change']);
        }
    }

    public function test_upload_manifest_is_planning_only_without_fabricated_urls(): void
    {
        $manifest = $this->readJson('upload-mapping-manifest.json');
        $this->assertSame('PLANNING_ONLY_PENDING_OPERATOR_MEDIA', $manifest['status']);
        $this->assertCount(18, $manifest['requirements']);
        $this->assertSame(231, array_sum(array_column($manifest['requirements'], 'candidate_count')));
        $this->assertSame(54, array_sum(array_map(fn (array $requirement): int => count($requirement['slot_requirements']), $manifest['requirements'])));
        $this->assertFalse($manifest['upload_executed']);
        $this->assertFalse($manifest['mapping_write_executed']);
        $this->assertSame(0, $manifest['fabricated_urls']);
        $serialized = strtolower(json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('https://', $serialized);
        $this->assertStringNotContainsString('http://', $serialized);
    }

    public function test_dry_run_and_qa_prove_zero_mutation_fail_closed_output(): void
    {
        $dryRun = $this->readJson('dry-run-report.json');
        $this->assertSame('PASS_FAIL_CLOSED_NO_MEDIA', $dryRun['status']);
        $this->assertSame(231, $dryRun['counts']['candidate_pages']);
        $this->assertSame(693, $dryRun['counts']['total_slots']);
        $this->assertSame(0, $dryRun['counts']['mapped_slots']);
        $this->assertSame(693, $dryRun['counts']['missing_pending_slots']);
        $this->assertSame(0, $dryRun['counts']['approved_big_five_media_assets']);
        foreach ($dryRun['actions'] as $count) {
            $this->assertSame(0, $count);
        }
        $qa = $this->readJson('qa_report.json');
        $this->assertSame('PASS_PENDING_OPERATOR_MEDIA', $qa['status']);
        foreach ($qa['checks'] as $check) {
            $this->assertTrue($check);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = file_get_contents($this->packagePath.'/'.$file);
        $this->assertNotFalse($contents, "Unable to read {$file}");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
