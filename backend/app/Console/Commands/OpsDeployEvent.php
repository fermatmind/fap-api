<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpsDeployEvent extends Command
{
    protected $signature = 'ops:deploy-event
        {--env= : Environment name}
        {--status= : success|failed|rollback}
        {--revision= : git revision}
        {--actor= : deploy actor}
        {--meta= : json string for meta}';

    protected $description = 'Write an ops deploy event (non-blocking, for observability dashboards)';

    public function handle(): int
    {
        $status = trim((string) $this->option('status'));
        if ($status === '') {
            $this->error('Missing required --status');
            return 1;
        }

        if (!in_array($status, ['success', 'failed', 'rollback'], true)) {
            $this->error('Invalid --status, must be success|failed|rollback');
            return 1;
        }

        $env = trim((string) $this->option('env'));
        if ($env === '') {
            $env = (string) (\App\Support\RuntimeConfig::raw('FAP_ENV') ?: config('app.env') ?: 'local');
        }

        $revision = trim((string) $this->option('revision'));
        if ($revision === '') {
            $revision = $this->resolveRevision();
        }

        $actor = trim((string) $this->option('actor'));
        if ($actor === '') {
            $actor = (string) (\App\Support\RuntimeConfig::raw('GITHUB_ACTOR') ?: \App\Support\RuntimeConfig::raw('USER') ?: get_current_user());
            $actor = trim($actor);
        }
        if ($actor === '') {
            $actor = null;
        }

        $meta = $this->parseMeta((string) $this->option('meta'));
        $metaJson = $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);

        try {
            DB::table('ops_deploy_events')->insert([
                'env' => $env,
                'revision' => $revision !== '' ? $revision : 'unknown',
                'status' => $status,
                'actor' => $actor,
                'meta_json' => $metaJson,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ops:deploy-event] failed', ['error' => $e->getMessage()]);
            $this->error('Failed to write ops_deploy_events: ' . $e->getMessage());
            return 1;
        }

        $this->info('ok');
        return 0;
    }

    private function resolveRevision(): string
    {
        $envRev = \App\Support\RuntimeConfig::raw('SENTRY_RELEASE') ?: \App\Support\RuntimeConfig::raw('REVISION');
        if (is_string($envRev) && trim($envRev) !== '') {
            return trim($envRev);
        }

        $candidates = [
            base_path('REVISION'),
            base_path('../REVISION'),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $content = trim((string) file_get_contents($path));
                if ($content !== '') {
                    return $content;
                }
            }
        }

        $repoRoot = base_path('..');
        if (is_dir($repoRoot . '/.git')) {
            $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' rev-parse HEAD 2>/dev/null';
            $rev = trim((string) @shell_exec($cmd));
            if ($rev !== '') {
                return $rev;
            }
        }

        return 'unknown';
    }

    private function parseMeta(string $meta): ?array
    {
        $meta = trim($meta);
        if ($meta === '') {
            return null;
        }

        $decoded = json_decode($meta, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return ['raw' => $meta];
    }
}
