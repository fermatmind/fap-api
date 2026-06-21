<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageAgentReadiness;
use Tests\TestCase;

final class EnneagramResultPageAgentReadinessTest extends TestCase
{
    public function test_audit_service_writes_control_packet_without_generation_or_activation(): void
    {
        $artifactRoot = $this->tempDir('enneagram-agent-readiness');

        try {
            $summary = app(EnneagramResultPageAgentReadiness::class)->audit([
                'run_id' => 'unit-run',
                'artifact_dir' => $artifactRoot,
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));

            $runDir = $artifactRoot.'/unit-run';
            foreach ([
                'control_packet.json',
                'readiness_inventory.json',
                'validation_commands.json',
                'safety_policy.json',
                'go_no_go.md',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $controlPacket = $this->readJson($runDir.'/control_packet.json');
            $this->assertSame(EnneagramResultPageAgentReadiness::SCHEMA_VERSION, $controlPacket['schema_version'] ?? null);
            $this->assertSame('backend', $controlPacket['content_authority'] ?? null);
            $this->assertSame('ENNEAGRAM', $controlPacket['scale'] ?? null);
            $this->assertFalse((bool) data_get($controlPacket, 'negative_guarantees.bulk_content_generation_happened', true));
            $this->assertFalse((bool) data_get($controlPacket, 'negative_guarantees.activation_happened', true));
            $this->assertFalse((bool) data_get($controlPacket, 'agent_batches.0.generation_allowed', true));

            $inventory = $this->readJson($runDir.'/readiness_inventory.json');
            $this->assertSame(630, (int) ($inventory['expected_payload_count'] ?? 0));
            $this->assertSame(
                'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
                data_get($inventory, 'runtime_registry.expected_sha256')
            );
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));

            $commands = $this->readJson($runDir.'/validation_commands.json');
            $futureExport = implode("\n", (array) ($commands['future_candidate_export_gate'] ?? []));
            $this->assertStringContainsString('enneagram:export-production-equivalent-candidate-payloads', $futureExport);
            $this->assertStringContainsString('total_payload_count == 630', $futureExport);

            $safety = $this->readJson($runDir.'/safety_policy.json');
            $this->assertContains('fixed_type_certainty', $safety['forbidden_claim_families'] ?? []);
            $this->assertContains('score_comparison_between_e105_and_fc144', $safety['forbidden_claim_families'] ?? []);

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('candidate_generation_allowed: false', $goNoGo);
            $this->assertStringContainsString('no activation', $goNoGo);

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'control_packet.json',
                    'readiness_inventory.json',
                    'validation_commands.json',
                    'safety_policy.json',
                    'go_no_go.md',
                ]
            ));
            $this->assertStringNotContainsString('/Users/rainie/', $allArtifacts);
            $this->assertStringNotContainsString('attempt_id_example', $allArtifacts);
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_strict_mode_rejects_incomplete_candidate_directory(): void
    {
        $root = $this->tempDir('enneagram-agent-strict');
        $candidateDir = $root.'/candidate';
        mkdir($candidateDir, 0777, true);

        try {
            $summary = app(EnneagramResultPageAgentReadiness::class)->audit([
                'run_id' => 'strict-run',
                'artifact_dir' => $root.'/artifacts',
                'candidate_dir' => $candidateDir,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('missing_candidate_artifact:candidate_manifest.json', $summary['strict_failures']);

            $inventory = $this->readJson($root.'/artifacts/strict-run/readiness_inventory.json');
            $this->assertContains('candidate_hashes.json', data_get($inventory, 'candidate_dir.missing_required_files'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function tempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
