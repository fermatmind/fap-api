<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class EnneagramResultPageReportSidecarIssueCommandTest extends TestCase
{
    public function test_report_sidecar_command_succeeds_with_external_blocker_when_required_gates_are_green(): void
    {
        $this->artisan('enneagram:result-page-report-sidecar', [
            'action' => 'audit',
            '--run-id' => 'command-sidecar',
            '--artifact-dir' => sys_get_temp_dir().'/enneagram-report-sidecar-command',
            '--blocker-source' => 'external',
            '--blocker-reason' => 'outside current PR scope',
            '--github-checks-green' => '1',
            '--scope-validation-green' => '1',
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }
}
