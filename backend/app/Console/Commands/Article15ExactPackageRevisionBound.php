<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Article15ExactPackageRevisionBoundAdapter;
use Illuminate\Console\Command;
use Throwable;

final class Article15ExactPackageRevisionBound extends Command
{
    protected $signature = 'articles:article15-exact-package
        {--phase=preflight : preflight|draft-import|readback|publish}
        {--batch=ALL : A|B|C|ALL}
        {--execution-manifest-sha256= : Exact adapter execution manifest SHA-256}
        {--expected-state-sha256= : Exact public Article/SEO state SHA-256}
        {--expected-revision-set-sha256= : Exact published/working revision-set SHA-256}
        {--dry-run : Validate and plan without writing}
        {--execute : Execute a local/testing-only write phase}
        {--json : Emit JSON}';

    protected $description = 'Preflight, draft, read back, or atomically publish the fixed Article15 exact packages.';

    public function handle(Article15ExactPackageRevisionBoundAdapter $adapter): int
    {
        try {
            $summary = $adapter->run([
                'phase' => (string) $this->option('phase'),
                'batch' => (string) $this->option('batch'),
                'execution_manifest_sha256' => (string) $this->option('execution-manifest-sha256'),
                'expected_state_sha256' => (string) $this->option('expected-state-sha256'),
                'expected_revision_set_sha256' => (string) $this->option('expected-revision-set-sha256'),
                'dry_run' => (bool) $this->option('dry-run') || ! (bool) $this->option('execute'),
                'execute' => (bool) $this->option('execute'),
            ]);
        } catch (Throwable $exception) {
            $summary = [
                'ok' => false,
                'phase' => (string) $this->option('phase'),
                'batch' => strtoupper((string) $this->option('batch')),
                'dry_run' => ! (bool) $this->option('execute'),
                'executed' => false,
                'error' => $exception->getMessage(),
                'write_boundaries' => [
                    'cms_content_write' => false,
                    'database_write' => false,
                    'publication_write' => false,
                    'sitemap_write' => false,
                    'llms_write' => false,
                    'search_channel_write' => false,
                ],
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach (['ok', 'phase', 'batch', 'dry_run', 'executed', 'action', 'target_count', 'state_sha256', 'revision_set_sha256', 'error'] as $field) {
                if (array_key_exists($field, $summary)) {
                    $this->line($field.'='.json_encode($summary[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
