<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\InternalLink\InternalLinkGraphDryRun;
use Illuminate\Console\Command;

final class SeoIntelInternalLinkGraphCommand extends Command
{
    protected $signature = 'seo-intel:internal-link-graph
        {--dry-run : Required. Keep graph inspection non-mutating}
        {--no-write : Required. Prevent all persistence}
        {--json : Required. Output safe machine-readable JSON}';

    protected $description = 'Run a non-mutating internal link graph readiness dry-run.';

    public function handle(InternalLinkGraphDryRun $dryRun): int
    {
        if (! (bool) $this->option('dry-run') || ! (bool) $this->option('no-write') || ! (bool) $this->option('json')) {
            $this->emit([
                'runtime' => InternalLinkGraphDryRun::RUNTIME,
                'status' => 'blocked',
                'dry_run' => (bool) $this->option('dry-run'),
                'no_write' => (bool) $this->option('no-write'),
                'writes_attempted' => false,
                'cms_mutation_attempted' => false,
                'link_mutation_attempted' => false,
                'search_channel_enqueue_attempted' => false,
                'search_submission_attempted' => false,
                'blockers' => [
                    [
                        'field' => 'command.options',
                        'code' => 'dry_run_no_write_json_required',
                        'message' => '--dry-run, --no-write, and --json are required.',
                    ],
                ],
            ]);

            return self::FAILURE;
        }

        $this->emit($dryRun->report());

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('status='.(string) ($payload['status'] ?? 'blocked'));
        $this->line('dry_run='.(($payload['dry_run'] ?? false) ? '1' : '0'));
        $this->line('no_write='.(($payload['no_write'] ?? false) ? '1' : '0'));
        $this->line('writes_attempted='.(($payload['writes_attempted'] ?? true) ? '1' : '0'));
    }
}
