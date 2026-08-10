<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CareerC03CacheOnlyDiscoverabilityRecoveryWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_the_exact_failed_incident_and_c02_authority_lineage(): void
    {
        $workflow = $this->workflow();

        foreach ([
            "FAILED_DEPLOY_RUN_ID: '31373985399'",
            "C02_REPAIR_RUN_ID: '31372257233'",
            'sha256:f0a85f1a6bd41fbd950e1384826a2f423e2d528430d312b9e825cde06797a704',
            '44951ee59449d0efeeebd179486b6e2db2d05ff265d547105131a6d39e1bf4ec',
            'sha256:ab84f3c2cc2330ba26ca72ffa2f16c07938d4a34ffaa4c943e8f662a93c055c2',
            '993980a3cd345dfc4315f075b78cf7f029bd5c9113b9cc1be0ef5d36bc4bee33',
            'sha256:5326960d5670519d831889688a91d13c1c3f1b937bdcc513618265c4899d7f2b',
            'c2659ace415f9bc91b8052f63c8224d6cc9e43bfde7696287aa288d69dfc0613',
            'sha256:ba1be4f6862851752b1bbfd6a535c7c1ef589e90658d9043f372174fd17ea6eb',
            'f1a30b3c83c8d3f11b83e4b8056074bf724e1f6d37646f93750567b0a60b4cc1',
            '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6',
            'old_incident_artifact_superseded: true',
            'standard_deploy_attempted: true',
            'migration_execution_attempted: true',
            'failed_run_standard_deploy_execution_count: 1',
            'failed_run_migration_execution_count: 1',
            'current_control_migration_count: 0',
            'terminal_failure_task: "guard:public-dns-health"',
            'symlink_changed: false',
            'queue_reloaded: false',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }
    }

    #[Test]
    public function workflow_is_latest_main_exact_sha_receipt_bound_and_fail_closed(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
            'expected_incident_closeout_receipt_sha256:',
            'expected_incident_closeout_artifact_digest:',
            'expected_verify_receipt_sha256:',
            'expected_verify_artifact_digest:',
            'operator_approval_phrase:',
            'career-c03-incident_closeout-${INCIDENT_CLOSEOUT_RUN_ID}-${INCIDENT_CLOSEOUT_RUN_ATTEMPT}',
            '.status == "PASS_INCIDENT_CLOSED"',
            '.status == "PASS_RECOVERY_REQUIRED"',
            'test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"',
            'test "$current_pre_state" = "$(jq -r \'.pre_state_sha256\' "$verify")"',
            'php /dev/stdin inspect',
            'php /dev/stdin apply',
            'php /dev/stdin rollback',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }
    }

    #[Test]
    public function verify_and_apply_contracts_preserve_exact_career_and_non_career_sets(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'PASS_C03_REVERIFIED_NO_APPLY_REQUIRED',
            'PASS_RECOVERY_REQUIRED',
            'PASS_C03_RECOVERED',
            'HOLD_NON_RECOVERABLE_DRIFT',
            'HOLD_APPLY_ROLLED_BACK',
            'HOLD_ROLLBACK_INCOMPLETE',
            'authority_inventory: $inspect.authority_inventory',
            'published_cohort: $inspect.authority',
            'detail_coverage: $inspect.detail_coverage',
            'target_set_sha256: $inspect.target_set_sha256',
            'cache_key_manifest: $inspect.cache_key_manifest',
            'cache_key_manifest_sha256: $inspect.cache_key_manifest_sha256',
            'revalidation_path_manifest: $inspect.revalidation_path_manifest',
            'revalidation_path_manifest_sha256: $inspect.revalidation_path_manifest_sha256',
            'non_career_url_set_sha256',
            'xargs -P2',
            'for round in 1 2; do',
            "'https://fermatmind.com/sitemap.xml'",
            "'https://fermatmind.com/llms.txt'",
            "'https://fermatmind.com/llms-full.txt'",
            'career_link_publication_gate: "CLOSED"',
            'automatic_retry_allowed: false',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }

        self::assertStringNotContainsString('1046', $workflow);
        self::assertStringNotContainsString('2092', $workflow);
        self::assertStringNotContainsString('uses: ./.github/workflows/deploy-production.yml', $workflow);
        self::assertStringNotContainsString('gh workflow run deploy-production.yml', $workflow);
        self::assertStringNotContainsString('php artisan migrate', $workflow);
        self::assertStringNotContainsString('queue:restart', $workflow);
        self::assertStringNotContainsString('supervisorctl', $workflow);
        self::assertStringNotContainsString('indexnow', strtolower($workflow));
        self::assertStringNotContainsString('googleapis', strtolower($workflow));
    }

    #[Test]
    public function workflow_artifact_is_always_uploaded_and_only_final_pass_states_close_c03(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString('if: always()', $workflow);
        self::assertStringContainsString(
            'name: career-c03-${{ inputs.mode }}-${{ github.run_id }}-${{ github.run_attempt }}',
            $workflow,
        );
        self::assertStringContainsString('retention-days: 30', $workflow);
        self::assertStringContainsString(
            'expected_re=\'^(PASS_C03_REVERIFIED_NO_APPLY_REQUIRED|PASS_RECOVERY_REQUIRED)$\'',
            $workflow,
        );
        self::assertSame(1, substr_count($workflow, 'status: "PASS_C03_RECOVERED"'));
        self::assertSame(1, substr_count($workflow, 'status=PASS_C03_REVERIFIED_NO_APPLY_REQUIRED'));
    }

    private function workflow(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/career-c03-cache-only-discoverability-recovery.yml',
        );
    }
}
