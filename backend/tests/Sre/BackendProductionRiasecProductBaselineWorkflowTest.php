<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackendProductionRiasecProductBaselineWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_latest_main_bound_production_protected_and_exactly_scoped(): void
    {
        $source = $this->source();

        foreach ([
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'cancel-in-progress: false',
            'contents: read',
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'test "$EXPECTED_ACTIVE_REVISION" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'I explicitly approve SELECT-only production RIASEC product baseline',
            'active SHA ${EXPECTED_ACTIVE_REVISION} control SHA ${EXPECTED_CONTROL_PLANE_SHA}',
            'date window 2026-07-13 through 2026-08-09 org 0.',
            '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$',
            'secrets.SSH_PRIVATE_KEY',
            'secrets.SSH_KNOWN_HOSTS',
            'secrets.PRODUCTION_DEPLOY_USER',
            'secrets.PRODUCTION_DEPLOY_PORT',
            'secrets.PRODUCTION_DEPLOY_HOST',
            'secrets.PRODUCTION_RETIRED_DEPLOY_HOST',
            'secrets.PRODUCTION_DEPLOY_PATH',
            'test "$DEPLOY_HOST" != "$RETIRED_DEPLOY_HOST"',
            'persist-credentials: false',
            'retention-days: 7',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        foreach (['inputs.release_id', 'env.RELEASE_ID', '$RELEASE_ID', ' release ${RELEASE_ID}'] as $operatorGuess) {
            $this->assertStringNotContainsString($operatorGuess, $source);
        }
    }

    #[Test]
    public function remote_step_uses_a_read_only_transaction_and_fixed_org_zero_reports(): void
    {
        $remote = $this->between(
            $this->source(),
            '      - name: Stream fixed SELECT-only reports without remote files',
            '      - name: Finalize sanitized immutable receipt',
        );

        foreach ([
            "statement('SET TRANSACTION READ ONLY')",
            'beginTransaction()',
            'rollBack()',
            '$reportFailure ??=',
            'if ($reportFailure !== null)',
            'AnalyticsTrafficExclusionPolicy::class',
            'AnalyticsFunnelDailyBuilder::class',
            'SeoConversionDailyBuilder::class',
            "CarbonImmutable::parse('2026-07-13')",
            "CarbonImmutable::parse('2026-08-09')",
            "'2026-07-12T16:00:00+00:00'",
            "'2026-08-09T16:00:00+00:00'",
            "'Asia/Shanghai'",
            "->where('occurred_at', '>=', \$windowStart)",
            "->where('occurred_at', '<', \$windowEndExclusive)",
            '$runtime = $current.\'/backend\'',
            '$releasesRoot = realpath($deployPath.\'/releases\')',
            'dirname($current) !== $releasesRoot',
            '$activeRelease = basename($current)',
            "preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', \$activeRelease)",
            "'release_id' => \$activeRelease",
            'require $runtime.\'/vendor/autoload.php\'',
            '$normalizeApprovedPath',
            'for ($decodePass = 0; $decodePass < 5; $decodePass++)',
            'rawurldecode($path)',
            "preg_match('/%[0-9a-f]{2}/i', \$path)",
            "'fermatmind.com'",
            "'/en/tests/holland-career-interest-test-riasec'",
            "'/take'",
            '$sourcePath === $canonicalPath',
            '$landingSessionFirstAt',
            '$normalizeSessionHash',
            'session linked to preceding canonical landing_pv',
            "'occurred_at'",
            '$landingSessionFirstAt[$sessionHash]->lte($eventAt)',
            'LANDING_SOURCE_RECONCILIATION_FAILED',
            "DB::table('events')",
            'SCOPED_SOURCE_RECONCILIATION_FAILED',
            "'scoped_source_reconciliation' => 'exact'",
            "'unscoped_builder_skipped_rows'",
            "'materialized_table_used' => false",
            "'source_table' => 'events'",
            "['riasec_60', 'riasec_140']",
            "data_get(\$row, 'dimensions.form_code')",
            "\$funnel['filters']['form_codes'] = \$forms",
            'MeasurementFunnelReadModel::class',
            'MeasurementFailureCohortReadModel::class',
            '> "$run_dir/combined.json" 2> "$run_dir/remote.stderr"',
            'writes_committed == false',
            '($funnel[\'status\'] ?? null) !== \'pass\'',
            'in_array(($failure[\'status\'] ?? null), [\'pass\', \'empty\']',
        ] as $contract) {
            $this->assertStringContainsString($contract, $remote);
        }

        $landingBranch = strpos($remote, "if (\$eventCode === 'landing_pv')");
        $downstreamMetric = strpos($remote, '$metric = match ($eventCode)');
        $this->assertNotFalse($landingBranch);
        $this->assertNotFalse($downstreamMetric);
        $this->assertLessThan($downstreamMetric, $landingBranch);

        foreach ([
            'php artisan', 'sudo ', 'supervisorctl', 'systemctl', 'service ', 'redis-cli',
            'queue:restart', 'migrate', 'deploy ', 'publish', 'indexnow', 'search:submit',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $remote);
        }

        foreach (['cat "$run_dir/remote.stderr"', 'getMessage()', 'set -x'] as $secretRisk) {
            $this->assertStringNotContainsString($secretRisk, $remote);
        }
        $this->assertStringNotContainsString('$urlPath === $takePath || $sourcePath === $canonicalPath', $remote);
    }

    #[Test]
    public function artifacts_are_aggregate_only_hash_bound_and_failure_safe(): void
    {
        $source = $this->source();

        foreach ([
            'Initialize failure-safe sanitized receipt',
            'FAIL_PRODUCTION_RIASEC_PRODUCT_BASELINE',
            'PASS_PRODUCTION_RIASEC_PRODUCT_BASELINE',
            'landing-and-product-funnel.json',
            'attempt-result-funnel.json',
            'failure-cohorts.json',
            'source_report_sha256',
            'source_health',
            'questions_load_failure',
            'submit_failure',
            'database_write: false',
            'cms_write: false',
            'remote_file_write: false',
            'raw_log_read: false',
            'search_submit: false',
            'writes_committed: false',
            'release_id: null',
            '| .release_id = $release_id',
            'if: ${{ always() }}',
            'RECEIPT_FILE_NAME: backend-production-riasec-product-baseline-receipt.json',
            'cp "$RUNNER_TEMP/$RECEIPT_FILE_NAME" artifacts/backend-production-riasec-product-baseline-receipt.json',
            'mkdir -p artifacts',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        $checkout = strpos($source, '      - name: Check out exact main control plane');
        $this->assertNotFalse($checkout);
        $this->assertStringContainsString('> "$RUNNER_TEMP/$RECEIPT_FILE_NAME"', substr($source, 0, (int) $checkout));
        $this->assertStringNotContainsString('> artifacts/', substr($source, 0, (int) $checkout));

        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'attempt_id:', 'event_id:'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $source);
        }

        $this->assertStringNotContainsString("DB::table('analytics_seo_conversion_daily')", $source);
    }

    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-production-riasec-product-baseline.yml',
        );
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, (int) $startPosition);
        $this->assertNotFalse($endPosition);

        return substr($source, (int) $startPosition, (int) $endPosition - (int) $startPosition);
    }
}
