<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ControlledWorkflowOperationGateTest extends TestCase
{
    #[Test]
    public function controlled_workflows_gate_before_environment_and_require_execute(): void
    {
        foreach ([
            'deploy-production.yml',
            'career-staging-authority-artifact-recovery.yml',
            'career-current-generation-pointer-bootstrap-staging-ops.yml',
            'content-promotion-automation.yml',
            'content-promotion-key-reconciliation.yml',
            'career-baseline-index-state-authority-repair.yml',
            'career-baseline-set-identity-diagnostic.yml',
        ] as $name) {
            $source = $this->workflow($name);
            self::assertStringContainsString('operation_key:', $source, $name);
            self::assertStringContainsString('[op:${{ inputs.operation_key }}]', $source, $name);
            self::assertStringContainsString('uses: ./.github/actions/controlled-operation-gate', $source, $name);
            self::assertStringContainsString("outputs.decision == 'execute'", $source, $name);
            self::assertLessThan(strpos($source, 'environment:'), strpos($source, 'operation_gate:') ?: strpos($source, 'operation-gate:'), $name);
        }
    }

    #[Test]
    public function staging_and_auto_production_require_owner_receipts(): void
    {
        $staging = $this->workflow('deploy.yml');
        self::assertStringContainsString('paths-ignore:', $staging);
        $controlOnlyPaths = [
            'AGENTS.md',
            '.github/actions/controlled-operation-gate/action.yml',
            '.github/scripts/controlled-operation-gate.cjs',
            '.github/workflows/backend-production-auto-deploy.yml',
            '.github/workflows/career-production-runtime-authority-diagnostic.yml',
            '.github/workflows/career-runtime-authority-permission-preflight.yml',
            '.github/workflows/career-runtime-authority-permission-repair.yml',
            '.github/workflows/career-1046-discoverability-permit-permission.yml',
            '.github/workflows/career-current-generation-pointer-bootstrap-staging-ops.yml',
            '.github/workflows/career-staging-authority-artifact-recovery.yml',
            '.github/workflows/career-baseline-index-state-authority-repair.yml',
            '.github/workflows/career-baseline-set-identity-diagnostic.yml',
            '.github/workflows/content-promotion-automation.yml',
            '.github/workflows/content-promotion-key-reconciliation.yml',
            '.github/workflows/deploy-production.yml',
            '.github/workflows/deploy.yml',
            'backend/tests/Sre/ControlledWorkflowOperationGateTest.php',
            'backend/tests/Sre/BackendProductionAutoDeployWorkflowTest.php',
            'backend/scripts/operations/career_production_runtime_authority_diagnostic.php',
            'backend/scripts/deploy/career_runtime_authority_permission_control.sh',
            'backend/scripts/deploy/career_1046_discoverability_permit_permission_control.sh',
            'backend/scripts/deploy/content_promotion_key_reconciliation.sh',
            'backend/scripts/operations/career_baseline_index_state_authority_repair.php',
            'backend/scripts/operations/career_baseline_set_identity_diagnostic.php',
            'backend/tests/Sre/CareerProductionRuntimeAuthorityDiagnosticWorkflowTest.php',
            'backend/tests/Sre/DeployStorageAndDatabaseConfigTest.php',
            'backend/tests/Sre/Career1046DiscoverabilityPermitPermissionWorkflowTest.php',
            'backend/tests/Sre/ContentPromotionKeyReconciliationTest.php',
            'backend/tests/Sre/CareerBaselineIndexStateAuthorityRepairTest.php',
            'backend/tests/Sre/CareerBaselineSetIdentityDiagnosticTest.php',
            'docs/operations/career-baseline-index-state-authority-repair.md',
            'docs/operations/career-baseline-set-identity-diagnostic.md',
            'tests/ops/controlled-operation-gate.test.cjs',
            'tests/ops/test_career_runtime_authority_permission_control.py',
        ];
        self::assertSame(1, preg_match('/paths-ignore:\n(?<paths>(?:\s+- "[^"]+"\n)+)/', $staging, $pathIgnore));
        self::assertSame(count($controlOnlyPaths), preg_match_all('/^\s+- "(?<path>[^"]+)"$/m', $pathIgnore['paths'], $paths));
        self::assertSame($controlOnlyPaths, $paths['path']);
        self::assertStringContainsString('backend-staging-operation-${{ needs.operation-gate.outputs.operation_key }}-${{ github.run_id }}-${{ github.run_attempt }}', $staging);
        self::assertStringContainsString("if: needs.operation-gate.outputs.decision == 'execute'", $staging);

        $automatic = $this->workflow('backend-production-auto-deploy.yml');
        self::assertStringContainsString('uses: ./.github/actions/controlled-operation-gate', $automatic);
        self::assertStringContainsString("needs.operation-gate.outputs.decision == 'execute'", $automatic);
        self::assertStringContainsString('backend-production-auto-dispatch-${{ needs.operation-gate.outputs.operation_key }}-${{ github.run_id }}-${{ github.run_attempt }}', $automatic);
        self::assertStringContainsString('Automatic production dispatch requires one immutable staging owner receipt.', $automatic);
        self::assertStringContainsString('operation_key: operationKey', $automatic);
        self::assertStringContainsString('workflow=.github/workflows/deploy-production.yml', $automatic);
    }

    #[Test]
    public function shared_gate_is_receipt_bound_and_never_reads_approval_phrases(): void
    {
        $gate = (string) file_get_contents(dirname(__DIR__, 3).'/.github/scripts/controlled-operation-gate.cjs');
        $action = (string) file_get_contents(dirname(__DIR__, 3).'/.github/actions/controlled-operation-gate/action.yml');

        foreach (['attach_active', 'attach_success', 'blocked_prior_terminal', 'blocked_receipt_invalid', 'blocked_ambiguous_owners'] as $decision) {
            self::assertStringContainsString($decision, $gate);
        }
        self::assertStringContainsString('before Environment or secret access', $action);
        self::assertStringContainsString('DIGEST_PATTERN', $gate);
        self::assertStringNotContainsString('approval_phrase', strtolower($gate.$action));
        self::assertStringNotContainsString('secrets.', $gate.$action);
    }

    private function workflow(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/'.$name);
    }
}
