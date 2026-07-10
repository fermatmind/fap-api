<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
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

    public function test_candidate_staging_harness_command_preserves_default_hash_contracts_when_options_are_empty(): void
    {
        $artifactRoot = sys_get_temp_dir().'/enneagram-candidate-staging-command-defaults';
        $candidateDir = $artifactRoot.'/candidate';
        File::deleteDirectory($artifactRoot);
        File::ensureDirectoryExists($candidateDir);

        $this->artisan('enneagram:result-page-candidate-staging-harness', [
            'action' => 'audit',
            '--run-id' => 'default-hash-contracts',
            '--artifact-dir' => $artifactRoot,
            '--candidate-dir' => $candidateDir,
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(1);

        $report = json_decode((string) File::get(
            $artifactRoot.'/default-hash-contracts/candidate_export_staging_import_report.json'
        ), true);
        $this->assertSame(
            'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0',
            data_get($report, 'candidate_contract.expected_candidate_manifest_sha256')
        );
        $this->assertSame(
            'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
            data_get($report, 'candidate_contract.expected_runtime_registry_sha256')
        );
    }
}
