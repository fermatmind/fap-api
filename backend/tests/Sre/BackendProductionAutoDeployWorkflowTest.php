<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackendProductionAutoDeployWorkflowTest extends TestCase
{
    #[Test]
    public function successful_exact_main_staging_dispatches_the_existing_standard_production_lane_once(): void
    {
        $source = $this->source();

        foreach ([
            'name: Backend Production Auto Deploy',
            'workflow_run:',
            'workflows: ["Deploy Application"]',
            'types: [completed]',
            'branches: [main]',
            'actions: write',
            'contents: read',
            'pull-requests: read',
            'cancel-in-progress: false',
            "github.event.workflow_run.status == 'completed'",
            "github.event.workflow_run.conclusion == 'success'",
            "github.event.workflow_run.event == 'push'",
            "github.event.workflow_run.head_branch == 'main'",
            'github.event.workflow_run.head_repository.full_name == github.repository',
            'github.rest.actions.getWorkflowRun',
            "staging.name !== 'Deploy Application'",
            "staging.path !== '.github/workflows/deploy.yml'",
            'staging.head_repository?.full_name !== expectedRepository',
            'main.data.commit.sha !== deploySha',
            'Skipping superseded staging success',
            'github.rest.repos.listPullRequestsAssociatedWithCommit',
            "pull.base?.ref === 'main'",
            'pull.merge_commit_sha === deploySha',
            'mergedMainPulls.length !== 1',
            'github.rest.actions.listWorkflowRuns',
            "workflow_id: 'deploy-production.yml'",
            "event: 'workflow_dispatch'",
            'run.display_title === expectedRunTitle',
            'automatic retry is refused',
            'Check out exact staged main',
            'persist-credentials: false',
            'Resolve latest successful active production receipt',
            'github.rest.actions.listJobsForWorkflowRun',
            "step.name === 'Record production release candidate'",
            'Verify active revision receipt and cumulative no-migration scope',
            'backend-production-deploy-progress-${ACTIVE_RUN_ID}-*',
            '.schema_version == "fermatmind.deployer-progress.v1"',
            '.environment == "production"',
            '.status == "completed"',
            'git merge-base --is-ancestor "$active_sha" "$DEPLOY_SHA"',
            'test "$active_sha" != "$DEPLOY_SHA"',
            '-- backend/database/migrations',
            'github.rest.actions.createWorkflowDispatch',
            "ref: 'main'",
            'expected_release_sha: deploySha',
            'staging_run_id: stagingRunId',
            'release_id: releaseId',
            'operator_approval_phrase: approvalPhrase',
            "deploy_mode: 'standard'",
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        foreach ([
            'workflow_dispatch:',
            'push:',
            'pull_request:',
            'secrets.',
            'ssh',
            'sudo',
            'approved_migration',
            'expected_deployed_revision',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function production_lane_exposes_a_deterministic_exact_dispatch_identity(): void
    {
        $production = (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/deploy-production.yml',
        );

        $this->assertStringContainsString(
            'run-name: Deploy API Production ${{ inputs.expected_release_sha }} staging-${{ inputs.staging_run_id }}',
            $production,
        );
        $this->assertStringContainsString('workflow_dispatch:', $production);
        $this->assertStringContainsString('Validate manual exact-SHA approval and staging evidence', $production);
    }

    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-production-auto-deploy.yml',
        );
    }
}
