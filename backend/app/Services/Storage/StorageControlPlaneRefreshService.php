<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageControlPlaneRefreshService
{
    private const SCHEMA = 'storage_refresh_control_plane.v1';

    public function __construct(
        private readonly ?Closure $commandInvoker = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $generatedAt = now()->toIso8601String();
        $steps = [];

        foreach ($this->stepDefinitions() as $definition) {
            ['exit_code' => $exitCode, 'stdout' => $stdout] = $this->invokeCommand(
                $definition['command'],
                $definition['arguments']
            );
            $status = $exitCode === 0 ? 'success' : 'failure';
            $parsedOutput = $this->parseOutput($stdout);

            $step = [
                'name' => $definition['name'],
                'command' => $definition['command'],
                'arguments' => $definition['arguments'],
                'invocation' => $this->renderInvocation($definition['command'], $definition['arguments']),
                'exit_code' => $exitCode,
                'status' => $status,
                'stdout' => $stdout,
                'parsed_output' => $parsedOutput,
            ];

            $steps[] = $step;

            if ($status !== 'success') {
                $payload = [
                    'schema' => self::SCHEMA,
                    'mode' => 'dry_run',
                    'generated_at' => $generatedAt,
                    'status' => 'failure',
                    'failed_step' => $definition['name'],
                    'snapshot_path' => null,
                    'step_count' => count($steps),
                    'steps' => $steps,
                    'summary' => [
                        'successful_step_count' => count(array_filter($steps, static fn (array $entry): bool => (string) ($entry['status'] ?? '') === 'success')),
                        'failed_step_count' => 1,
                    ],
                ];

                $this->recordAudit($payload);

                return $payload;
            }
        }

        $snapshotPath = (string) data_get($steps, '11.parsed_output.snapshot_path', '');
        if ($snapshotPath === '') {
            $snapshotPath = null;
        }

        $payload = [
            'schema' => self::SCHEMA,
            'mode' => 'dry_run',
            'generated_at' => $generatedAt,
            'status' => 'success',
            'failed_step' => null,
            'snapshot_path' => $snapshotPath,
            'step_count' => count($steps),
            'steps' => $steps,
            'summary' => [
                'successful_step_count' => count($steps),
                'failed_step_count' => 0,
            ],
        ];

        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @return list<array{name:string,command:string,arguments:array<string,mixed>}>
     */
    private function stepDefinitions(): array
    {
        return [
            [
                'name' => 'inventory',
                'command' => 'storage:inventory',
                'arguments' => ['--json' => true],
            ],
            [
                'name' => 'prune_reports_backups',
                'command' => 'storage:prune',
                'arguments' => ['--dry-run' => true, '--scope' => 'reports_backups'],
            ],
            [
                'name' => 'prune_content_releases_retention',
                'command' => 'storage:prune',
                'arguments' => ['--dry-run' => true, '--scope' => 'content_releases_retention'],
            ],
            [
                'name' => 'prune_legacy_private_private_cleanup',
                'command' => 'storage:prune',
                'arguments' => ['--dry-run' => true, '--scope' => 'legacy_private_private_cleanup'],
            ],
            [
                'name' => 'blob_gc',
                'command' => 'storage:blob-gc',
                'arguments' => ['--dry-run' => true],
            ],
            [
                'name' => 'blob_offload',
                'command' => 'storage:blob-offload',
                'arguments' => ['--dry-run' => true],
            ],
            [
                'name' => 'backfill_release_metadata',
                'command' => 'storage:backfill-release-metadata',
                'arguments' => ['--dry-run' => true],
            ],
            [
                'name' => 'backfill_exact_release_file_sets',
                'command' => 'storage:backfill-exact-release-file-sets',
                'arguments' => ['--dry-run' => true],
            ],
            [
                'name' => 'quarantine_exact_roots',
                'command' => 'storage:quarantine-exact-roots',
                'arguments' => ['--dry-run' => true],
            ],
            [
                'name' => 'retire_exact_roots_quarantine',
                'command' => 'storage:retire-exact-roots',
                'arguments' => ['--dry-run' => true, '--action' => 'quarantine'],
            ],
            [
                'name' => 'retire_exact_roots_purge',
                'command' => 'storage:retire-exact-roots',
                'arguments' => ['--dry-run' => true, '--action' => 'purge'],
            ],
            [
                'name' => 'control_plane_snapshot',
                'command' => 'storage:control-plane-snapshot',
                'arguments' => ['--json' => true],
            ],
        ];
    }

    private function renderInvocation(string $command, array $arguments): string
    {
        $parts = [$command];

        foreach ($arguments as $key => $value) {
            if ($value === true) {
                $parts[] = $key;

                continue;
            }

            if ($value === false || $value === null) {
                continue;
            }

            $parts[] = $key.'='.$value;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array{exit_code:int,stdout:string}
     */
    private function invokeCommand(string $command, array $arguments): array
    {
        if ($this->commandInvoker instanceof Closure) {
            $result = ($this->commandInvoker)($command, $arguments);

            return [
                'exit_code' => (int) ($result['exit_code'] ?? 1),
                'stdout' => trim((string) ($result['stdout'] ?? '')),
            ];
        }

        $exitCode = Artisan::call($command, $arguments);

        return [
            'exit_code' => $exitCode,
            'stdout' => trim(Artisan::output()),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseOutput(string $stdout): array
    {
        if ($stdout === '') {
            return [];
        }

        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $parsed = [];
        foreach (preg_split('/\r\n|\r|\n/', $stdout) as $line) {
            $line = trim((string) $line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $parsed[$key] = $this->coerceScalar(trim($value));
        }

        return $parsed;
    }

    private function coerceScalar(string $value): mixed
    {
        if ($value === '') {
            return '';
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordAudit(array $payload): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $stepSummary = array_map(static fn (array $step): array => [
            'name' => (string) ($step['name'] ?? ''),
            'command' => (string) ($step['command'] ?? ''),
            'invocation' => (string) ($step['invocation'] ?? ''),
            'exit_code' => (int) ($step['exit_code'] ?? 1),
            'status' => (string) ($step['status'] ?? 'failure'),
        ], (array) ($payload['steps'] ?? []));

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_refresh_control_plane',
            'target_type' => 'storage',
            'target_id' => 'control_plane_refresh',
            'meta_json' => json_encode([
                'schema' => self::SCHEMA,
                'mode' => $payload['mode'] ?? null,
                'generated_at' => $payload['generated_at'] ?? null,
                'status' => $payload['status'] ?? null,
                'failed_step' => $payload['failed_step'] ?? null,
                'snapshot_path' => $payload['snapshot_path'] ?? null,
                'step_count' => $payload['step_count'] ?? 0,
                'summary' => $payload['summary'] ?? [],
                'steps' => $stepSummary,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_refresh_control_plane',
            'request_id' => null,
            'reason' => 'control_plane_refresh',
            'result' => (string) ($payload['status'] ?? 'failure') === 'success' ? 'success' : 'failure',
            'created_at' => now(),
        ]);
    }
}
