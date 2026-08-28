<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoPlatform10ProductionCloseoutContractTest extends TestCase
{
    public function test_existing_exact_sha_lane_owns_the_one_time_backfill_and_public_closeout(): void
    {
        $classifier = (string) file_get_contents(base_path('../.github/trunk/classify-paths.mjs'));
        $ci = (string) file_get_contents(base_path('../.github/workflows/ci.yml'));
        $deployWorkflow = (string) file_get_contents(base_path('../.github/workflows/deploy.yml'));
        $deployer = (string) file_get_contents(base_path('../deploy.php'));
        $operation = require config_path('seo_platform_10.php');

        $this->assertStringContainsString('seo_platform_10_closeout: paths.includes("backend/config/seo_platform_10.php")', $classifier);
        $this->assertStringContainsString('seo_platform_10_closeout:', $ci);
        $this->assertStringContainsString('steps.receipt.outputs.seo_platform_10_closeout', $deployWorkflow);
        $this->assertSame(2, substr_count($deployWorkflow, "-o seo_platform_10_closeout='\${{ needs.policy.outputs.seo_platform_10_closeout }}'"));
        $this->assertStringContainsString("task('seo:platform-10-material-backfill'", $deployer);
        $this->assertStringContainsString("task('seo:platform-10-public-closeout'", $deployer);
        $this->assertStringContainsString("after('guard:no-pending-seo-intel-migrations', 'seo:platform-10-material-backfill')", $deployer);
        $this->assertStringContainsString("after('seo:url-truth-reconciliation-receipt', 'seo:platform-10-public-closeout')", $deployer);
        $this->assertStringContainsString('idempotent_rerun', $deployer);
        $this->assertStringContainsString('projection_digest', $deployer);
        $this->assertStringContainsString('unknown_legacy_action', $deployer);
        $this->assertStringContainsString('recovery_ready_without_destructive_probe', $deployer);
        $this->assertSame(10000, $operation['max_records']);
        $this->assertSame('measurement_hold_no_write', $operation['staging_disabled_policy']);
        $this->assertStringContainsString('dry_run_rc=$?', $deployer);
        $this->assertStringContainsString('test "$dry_run_rc" = 0', $deployer);
        $this->assertSame(2, substr_count($deployer, 'deploySeoPlatform10SkipsDisabledStaging(') - 1);
        $this->assertStringContainsString("currentHost()->getAlias() !== 'staging'", $deployer);
        $this->assertStringContainsString('seo-platform-10-staging-measurement-hold.v1', $deployer);
        $this->assertStringContainsString("'writes_committed' => false", $deployer);
        $this->assertStringContainsString("'search_submission_allowed' => false", $deployer);
        $this->assertStringContainsString('requires SEO Intel in production', $deployer);
    }

    public function test_closeout_scripts_expose_no_manual_or_search_mutation_control(): void
    {
        $deployer = (string) file_get_contents(base_path('../deploy.php'));
        $publicSmoke = (string) file_get_contents(base_path('scripts/deploy/verify_seo_platform_10_public_closeout.sh'));
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-content-publishing-workspace.blade.php'));

        $this->assertStringContainsString('sitemap_digest', $publicSmoke);
        $this->assertStringContainsString('llms_digest', $publicSmoke);
        $this->assertStringContainsString('llms_full_digest', $publicSmoke);
        $this->assertStringContainsString('min($locales) > 0', $publicSmoke);
        $this->assertStringContainsString('private_path_count', $publicSmoke);
        $this->assertStringNotContainsString('indexnow', strtolower($publicSmoke));
        $this->assertStringNotContainsString('search:submit', strtolower($deployer));
        $this->assertStringNotContainsString('<form', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('backfill', $workspace);
    }
}
