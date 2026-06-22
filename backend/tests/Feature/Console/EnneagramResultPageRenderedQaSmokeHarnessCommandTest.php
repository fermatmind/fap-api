<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramResultPageRenderedQaSmokeHarnessCommandTest extends TestCase
{
    public function test_rendered_qa_smoke_harness_command_succeeds_with_candidate_dir(): void
    {
        $candidateDir = storage_path('framework/testing/rendered_qa_smoke_command_candidate');
        File::ensureDirectoryExists($candidateDir);

        $this->artisan('enneagram:result-page-rendered-qa-smoke-harness', [
            'action' => 'audit',
            '--run-id' => 'command-smoke',
            '--artifact-dir' => sys_get_temp_dir().'/enneagram-rendered-qa-smoke-command',
            '--candidate-dir' => $candidateDir,
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }
}
