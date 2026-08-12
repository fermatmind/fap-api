<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerCurrentGenerationPointerBootstrapStagingWorkflowTest extends TestCase
{
    #[Test]
    public function staging_control_is_exact_receipt_bound_and_reruns_only_the_failed_push_run(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/career-current-generation-pointer-bootstrap-staging-ops.yml');
        self::assertIsString($workflow);

        foreach ([
            'environment: staging',
            'STAGING_DEPLOY_USER',
            'STAGING_DEPLOY_PORT',
            'STAGING_DEPLOY_HOST',
            'STAGING_DEPLOY_PATH',
            'actions: write',
            'expected_preflight_artifact_digest:',
            'expected_projection_path_sha256:',
            'expected_ledger_path_sha256:',
            'relative_path_bytewise_ascending_first_v1',
            '.event == "push"',
            '.head_branch == "main"',
            '.head_sha == $sha',
            '.conclusion == "failure"',
            'PASS_PREFLIGHT_APPLY_ELIGIBLE',
            'PASS_APPLY_POINTER_BOOTSTRAPPED',
            'rerun-failed-jobs',
            'staging_rerun_dispatched',
            'automatic_pointer_retry_allowed',
        ] as $required) {
            self::assertStringContainsString($required, $workflow);
        }

        foreach ([
            'PRODUCTION_DEPLOY_',
            'environment: production',
            'createWorkflowDispatch',
            'gh workflow run',
            'curl -k',
            '--insecure',
            'php artisan migrate',
            'queue:restart',
            'mysql ',
            'psql ',
            'INSERT ',
            'UPDATE ',
            'DELETE ',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $workflow);
        }
    }
}
