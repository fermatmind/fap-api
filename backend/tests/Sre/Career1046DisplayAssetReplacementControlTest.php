<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class Career1046DisplayAssetReplacementControlTest extends TestCase
{
    public function test_the_standalone_runner_emits_a_sanitized_receipt_before_composer_is_loaded(): void
    {
        $runner = dirname(__DIR__, 3).'/backend/scripts/operations/career_1046_display_asset_replacement.php';
        $output = [];
        $status = 0;

        exec(
            'CAREER_DISPLAY_REPLACEMENT_BACKEND_ROOT= CAREER_DISPLAY_REPLACEMENT_EXECUTE= '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner).' preflight 2>&1',
            $output,
            $status,
        );

        self::assertSame(1, $status);
        $receipt = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('career.1046.display_asset_replacement.v1', $receipt['contract_version']);
        self::assertSame('FAIL_DISPLAY_ASSET_REPLACEMENT', $receipt['status']);
        self::assertSame('EXECUTION_CONTRACT_INVALID', $receipt['safe_error_code']);
        self::assertFalse($receipt['production_write_execution']);
        self::assertSame('confirmed_zero_write', $receipt['write_commit_state']);
        self::assertFalse($receipt['writes_committed']);
    }

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
        self::assertStringContainsString('startswith("Career 1046 display asset preflight [op:")', $workflow);
        self::assertStringNotContainsString('.name == "Career 1046 Display Asset Replacement"', $workflow);
        self::assertStringContainsString('Validate all 2092 public pages', $workflow);
        self::assertStringContainsString('remote_receipt="$RUNNER_TEMP/career-1046-display-asset-remote-receipt.json"', $workflow);
        self::assertStringContainsString('ssh_stderr="$RUNNER_TEMP/career-1046-display-asset-ssh.stderr"', $workflow);
        self::assertStringContainsString("trap 'rm -f -- \"\$ssh_stderr\"' EXIT", $workflow);
        self::assertStringContainsString('run_remote_once()', $workflow);
        self::assertStringContainsString(
            'while [ "$MODE" = preflight ] && [ "$ssh_rc" -eq 255 ] && [ ! -s "$remote_receipt" ] && [ "$attempt" -lt 3 ]; do',
            $workflow,
        );
        self::assertStringContainsString('sleep 10', $workflow);
        self::assertStringContainsString('if [ "$ssh_rc" -ne 0 ]; then', $workflow);
        self::assertStringContainsString('.status == "FAIL_DISPLAY_ASSET_REPLACEMENT"', $workflow);
        self::assertStringContainsString('.write_commit_state == "confirmed_zero_write"', $workflow);
        self::assertStringContainsString('mv "$remote_receipt" "$receipt"', $workflow);
        self::assertStringContainsString('safe_error_code=REMOTE_HOST_KEY_FAILURE', $workflow);
        self::assertStringContainsString('safe_error_code=REMOTE_AUTHENTICATION_FAILURE', $workflow);
        self::assertStringContainsString('safe_error_code=REMOTE_CONNECTION_TIMEOUT', $workflow);
        self::assertStringContainsString('safe_error_code=REMOTE_CONNECTION_REFUSED', $workflow);
        self::assertStringContainsString('safe_error_code=REMOTE_CONNECTION_RESET', $workflow);
        self::assertStringContainsString('safe_error_code=REMOTE_NETWORK_FAILURE', $workflow);
        self::assertStringContainsString('if [ "$ssh_rc" -eq 255 ]; then', $workflow);
        self::assertStringContainsString("jq --arg safe_error_code \"\$safe_error_code\" '.safe_error_code = \$safe_error_code'", $workflow);
        self::assertSame(3, substr_count($workflow, 'run_remote_once'));
        self::assertStringNotContainsString('2>/dev/null', $workflow);
        self::assertStringNotContainsString('StrictHostKeyChecking=no', $workflow);
        self::assertStringNotContainsString('curl -k', $workflow);
        self::assertStringContainsString('task_4b_through_7b_executed', $runner);
        self::assertStringContainsString("'sitemap_or_llms_release_executed' => false", $runner);
        self::assertStringContainsString("'search_channel_executed' => false", $runner);
        self::assertStringContainsString('DB::transaction', $service);
        self::assertStringContainsString('->lockForUpdate()', $service);
        self::assertStringContainsString('DATABASE_TARGET_STATE_DRIFT', $service);
        self::assertStringContainsString('activatePreparedJobDetailPayloadsForExposure', $service);
        self::assertStringNotContainsString('cachePostflight', $service);
        self::assertStringContainsString('restoreDatabaseRows', $service);
        self::assertStringContainsString('career.missing_12_display_asset_package.v1', $workflow);
        self::assertStringContainsString('insert exactly the authorized 12 missing v4.2 display assets', $workflow);
        self::assertStringContainsString("['database_insert_count'] ?? null) !== 12", $runner);
        self::assertStringContainsString('DATABASE_INSERT_TARGET_STATE_DRIFT', $service);
        self::assertStringContainsString('DATABASE_COMPENSATION_INSERT_DELETE_FAILED', $service);
        self::assertStringContainsString('AI_EXPOSURE_RATING_CONFLICT_UNRESOLVED', $service);
        self::assertStringContainsString('DISPLAY_INSERT_OCCUPATION_CROSSWALK_MISMATCH', $service);
        self::assertStringContainsString("\$plan['expected_blocks'][\$slug][\$locale]", $service);
        self::assertLessThan(
            strpos($runner, "throw new Career1046DisplayAssetReplacementFailure('RESULT_COUNT_MISMATCH')"),
            strpos($runner, "\$receipt['writes_committed'] = true"),
        );
        self::assertStringContainsString("'database_insert_count' => 0", $service);
        self::assertStringContainsString("'database_delete_count' => 0", $service);
        self::assertStringNotContainsString('Task 3A', $service);
        self::assertStringNotContainsString('IndexNow', $service);
    }
}
