<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class BigFiveResultPageV2AgentAutomationTest extends TestCase
{
    public function test_nightly_audit_is_registered_as_strict_redacted_schedule(): void
    {
        $events = $this->scheduleListEvents();
        $event = collect($events)->first(
            fn (array $event): bool => str_contains(
                (string) ($event['command'] ?? ''),
                'big5:result-page-v2-agent audit --strict --json --no-ansi'
            )
        );

        $this->assertNotNull(
            $event,
            'schedule:list did not include the Big Five V2 strict audit command. Commands: '.json_encode(
                array_values(array_map(fn (array $item): string => (string) ($item['command'] ?? ''), $events))
            )
        );
        $this->assertSame('40 4 * * *', $event['expression']);
        $this->assertStringContainsString('--strict', (string) $event['command']);
        $this->assertStringContainsString('--json', (string) $event['command']);
        $this->assertStringContainsString('--no-ansi', (string) $event['command']);
    }

    public function test_weekly_ops_runner_is_registered_as_redacted_schedule(): void
    {
        $events = $this->scheduleListEvents();
        $event = collect($events)->first(
            fn (array $event): bool => str_contains(
                (string) ($event['command'] ?? ''),
                'big5:result-page-v2-agent weekly-ops --json --no-ansi'
            )
        );

        $this->assertNotNull($event, 'schedule:list did not include the Big Five V2 weekly ops command.');
        $this->assertSame('20 5 * * 1', $event['expression']);
        $this->assertStringContainsString('--json', (string) $event['command']);
        $this->assertStringContainsString('--no-ansi', (string) $event['command']);
    }

    public function test_strict_nightly_audit_fails_closed_and_keeps_artifacts_redacted(): void
    {
        $root = base_path('artifacts/testing/big5-v2-nightly-audit');
        $artifactRoot = $root.'/artifacts';
        $sourceLedgerRoot = $root.'/source_ledger';

        $this->deleteDirectory($root);
        mkdir($sourceLedgerRoot, 0775, true);

        try {
            file_put_contents($sourceLedgerRoot.'/source_ledger.json', json_encode([
                'schema_version' => 'malformed',
                'runtime_use' => 'runtime',
                'production_use_allowed' => true,
                'sources' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'audit',
                '--run-id' => 'nightly-fail-closed',
                '--artifact-dir' => $artifactRoot,
                '--source-ledger-dir' => $sourceLedgerRoot,
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(1);

            $runDir = $artifactRoot.'/nightly-fail-closed';
            foreach ([
                'input_inventory.json',
                'validation_report.json',
                'safety_report.json',
                'qa_eval_summary.json',
                'ops_report_summary.json',
                'go_no_go.md',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $inventory = $this->readJson($runDir.'/input_inventory.json');
            $this->assertSame('staging_only', $inventory['runtime_use'] ?? null);
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));
            $this->assertFalse((bool) data_get($inventory, 'source_ledger.valid', true));
            $this->assertStringEndsWith(
                'artifacts/testing/big5-v2-nightly-audit/source_ledger/source_ledger.json',
                (string) data_get($inventory, 'source_ledger.primary_ledger_path')
            );
            foreach ([
                'database_write',
                'cms_write',
                'frontend_copy_write',
                'runtime_flag_change',
                'release_snapshot_change',
                'production_import_gate_change',
                'rollout_gate_change',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($inventory, "negative_guarantees.{$guarantee}", true), $guarantee);
            }

            $qa = $this->readJson($runDir.'/qa_eval_summary.json');
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_pilot', true));
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_runtime', true));
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_production', true));

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('production_use_allowed: false', $goNoGo);
            $this->assertStringContainsString('ready_for_production: false', $goNoGo);

            $combinedArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'input_inventory.json',
                    'validation_report.json',
                    'safety_report.json',
                    'qa_eval_summary.json',
                    'ops_report_summary.json',
                    'go_no_go.md',
                ]
            ));
            $this->assertStringNotContainsString($root, $combinedArtifacts);
            $this->assertStringNotContainsString('report_json', $combinedArtifacts);
            $this->assertStringNotContainsString('report_full_json', $combinedArtifacts);
            $this->assertStringNotContainsString('payload_json', $combinedArtifacts);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scheduleListEvents(): array
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:list', '--json', '--no-ansi'], base_path());
        $process->mustRun();

        $decoded = json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
