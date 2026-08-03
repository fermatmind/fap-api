<?php

declare(strict_types=1);

namespace Tests\Sre;

use Tests\TestCase;

final class ContentPromotionAutomationWorkflowTest extends TestCase
{
    public function test_workflow_binds_exact_inputs_runs_ordered_phases_and_never_deploys_or_changes_discoverability(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/content-promotion-automation.yml');

        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('fetch-depth: 0', $workflow);
        self::assertStringContainsString('test "$(git rev-parse HEAD)" = "$EXPECTED_CONTROL_PLANE_SHA"', $workflow);
        self::assertStringContainsString('minimum_executor_sha="8e738763162ff7c1507e28fa30d1b8cb7154de85"', $workflow);
        self::assertStringContainsString('git merge-base --is-ancestor "$EXPECTED_CONTROL_PLANE_SHA" origin/main', $workflow);
        self::assertStringContainsString('git merge-base --is-ancestor "$minimum_executor_sha" "$EXPECTED_CONTROL_PLANE_SHA"', $workflow);
        self::assertStringNotContainsString('test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"', $workflow);
        self::assertStringContainsString('test "$(tr -d', $workflow);
        self::assertStringContainsString('= "$CONTROL_SHA"', $workflow);
        self::assertStringContainsString('run_phase preflight', $workflow);
        self::assertStringContainsString('run_phase draft-import', $workflow);
        self::assertStringContainsString('run_phase publish', $workflow);
        self::assertStringContainsString('run_phase live-qa', $workflow);
        self::assertLessThan(strpos($workflow, 'run_phase draft-import'), strpos($workflow, 'run_phase preflight'));
        self::assertLessThan(strpos($workflow, 'run_phase publish'), strpos($workflow, 'run_phase draft-import'));
        self::assertLessThan(strpos($workflow, 'run_phase live-qa'), strpos($workflow, 'run_phase publish'));
        self::assertStringContainsString('content:promote-exact-package', $workflow);
        self::assertStringContainsString('CONTENT_PROMOTION_PREVIOUS_RECEIPT', $workflow);
        self::assertSame(2, substr_count($workflow, 'AUTOMATION_KEY: ${{ secrets.CONTENT_PROMOTION_AUTOMATION_KEY }}'));
        self::assertStringContainsString('CONTENT_PROMOTION_WORKFLOW_SIGNATURE="$WORKFLOW_SIGNATURE"', $workflow);
        self::assertStringContainsString('fermatmind.content_promotion_failure_evidence.v1', $workflow);
        self::assertStringContainsString('privacy_redaction:true', $workflow);
        self::assertStringContainsString('private_payload_read_count:0', $workflow);
        self::assertStringContainsString('*.failure.json "$local_receipts/"', $workflow);
        self::assertStringContainsString('.published_count == 0', $workflow);
        self::assertStringContainsString('.deploy_mutation_count == 0', $workflow);
        self::assertStringContainsString('.indexability_mutation_count == 0', $workflow);
        self::assertStringContainsString('.sitemap_mutation_count == 0', $workflow);
        self::assertStringContainsString('.llms_mutation_count == 0', $workflow);
        self::assertStringContainsString('.search_mutation_count == 0', $workflow);
        self::assertStringNotContainsString('approval phrase', strtolower($workflow));
        self::assertStringNotContainsString('human_operator', $workflow);
        self::assertStringNotContainsString('php artisan migrate', $workflow);
        self::assertStringNotContainsString('dep deploy', $workflow);
        self::assertStringNotContainsString('git pull', $workflow);
    }
}
