<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class EnneagramResultPageCandidateStagingHarnessCommandTest extends TestCase
{
    public function test_candidate_staging_harness_command_fails_closed_without_candidate_dir(): void
    {
        $this->artisan('enneagram:result-page-candidate-staging-harness', [
            'action' => 'audit',
            '--run-id' => 'missing-candidate',
            '--artifact-dir' => sys_get_temp_dir().'/enneagram-candidate-staging-command',
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(1);
    }
}
