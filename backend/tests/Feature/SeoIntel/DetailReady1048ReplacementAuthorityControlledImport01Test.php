<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class DetailReady1048ReplacementAuthorityControlledImport01Test extends TestCase
{
    public function test_controlled_import_report_exists_and_preserves_non_public_boundaries(): void
    {
        $path = base_path('docs/seo/generated/detail-ready-1048-replacement-authority-controlled-import-01.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);
        $this->assertSame('detail_ready_1048_replacement_authority_controlled_import.v1', $payload['schema_version'] ?? null);
        $this->assertSame('DETAIL_READY_1048_REPLACEMENT_AUTHORITY_CONTROLLED_IMPORT-01', $payload['task'] ?? null);
        $this->assertSame('computer-occupations-all-other', $payload['target_slug'] ?? null);
        $this->assertContains('software-developers', $payload['manual_hold_slugs_preserved'] ?? []);
        $this->assertSame('dry_run', $payload['default_mode'] ?? null);
        $this->assertTrue($payload['apply_requires_confirmation'] ?? false);
        $this->assertTrue($payload['no_deploy'] ?? false);
        $this->assertTrue($payload['no_publish'] ?? false);
        $this->assertTrue($payload['no_runtime_promotion'] ?? false);
        $this->assertTrue($payload['no_sitemap_llms_footer_exposure'] ?? false);
        $this->assertTrue($payload['no_search_channel_action'] ?? false);
        $this->assertTrue($payload['no_url_submission'] ?? false);
        $this->assertFalse($payload['production_import_executed_in_this_pr'] ?? true);
        $this->assertSame(0, $payload['write_scope_when_confirmed']['index_states'] ?? null);
        $this->assertSame(0, $payload['write_scope_when_confirmed']['runtime_promotions'] ?? null);
        $this->assertSame('DETAIL_READY_1048_DELTA_AUTHORITY_REPAIR-01', $payload['next_task'] ?? null);
    }
}
