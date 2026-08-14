<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class Career1046DisplayAssetReplacementControlTest extends TestCase
{
    public function test_the_control_plane_is_one_receipt_bound_apply_with_locked_boundaries(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = (string) file_get_contents($root.'/.github/workflows/career-1046-display-asset-replacement.yml');
        $runner = (string) file_get_contents($root.'/backend/scripts/operations/career_1046_display_asset_replacement.php');
        $service = (string) file_get_contents($root.'/backend/app/Domain/Career/Display/Career1046DisplayAssetReplacement.php');

        self::assertStringContainsString('options: [preflight, apply]', $workflow);
        self::assertStringContainsString('Elect display-asset operation owner before Environment access', $workflow);
        self::assertStringContainsString('expected_preflight_receipt_sha256', $workflow);
        self::assertStringContainsString('expected_preflight_state_sha256', $workflow);
        self::assertStringContainsString('Validate all 2092 public pages', $workflow);
        self::assertStringContainsString('task_4b_through_7b_executed', $runner);
        self::assertStringContainsString("'sitemap_or_llms_release_executed' => false", $runner);
        self::assertStringContainsString("'search_channel_executed' => false", $runner);
        self::assertStringContainsString('DB::transaction', $service);
        self::assertStringContainsString('activatePreparedJobDetailPayloadsForExposure', $service);
        self::assertStringContainsString('restoreDatabaseRows', $service);
        self::assertStringContainsString("'database_insert_count' => 0", $service);
        self::assertStringContainsString("'database_delete_count' => 0", $service);
        self::assertStringNotContainsString('Task 3A', $service);
        self::assertStringNotContainsString('IndexNow', $service);
    }
}
