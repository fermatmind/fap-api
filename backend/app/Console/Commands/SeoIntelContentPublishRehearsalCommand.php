<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\ContentOps\ContentPublishRehearsalDryRun;
use Illuminate\Console\Command;

final class SeoIntelContentPublishRehearsalCommand extends Command
{
    protected $signature = 'seo-intel:content-publish-rehearsal
        {--article=* : Article id to rehearse}
        {--ack-claim-warning=* : Article id whose boundary-context claim warning is acknowledged for dry-run}
        {--make-indexable : Include indexability transition in the dry-run plan}
        {--dry-run : Required. Keep rehearsal non-mutating}
        {--no-write : Required. Prevent all persistence}
        {--json : Required. Output safe machine-readable JSON}';

    protected $description = 'Run a non-mutating content publish rehearsal dry-run.';

    public function handle(ContentPublishRehearsalDryRun $dryRun): int
    {
        if (! (bool) $this->option('dry-run') || ! (bool) $this->option('no-write') || ! (bool) $this->option('json')) {
            $this->emit([
                'runtime' => ContentPublishRehearsalDryRun::RUNTIME,
                'status' => 'blocked',
                'rehearsal_state' => 'blocked',
                'dry_run' => (bool) $this->option('dry-run'),
                'no_write' => (bool) $this->option('no-write'),
                'writes_attempted' => false,
                'cms_mutation_attempted' => false,
                'article_publish_attempted' => false,
                'search_channel_enqueue_attempted' => false,
                'search_submission_attempted' => false,
                'sitemap_mutation_attempted' => false,
                'llms_mutation_attempted' => false,
                'observation_queue_write_attempted' => false,
                'blockers' => [
                    [
                        'field' => 'command.options',
                        'code' => 'dry_run_no_write_json_required',
                        'message' => '--dry-run, --no-write, and --json are required.',
                    ],
                ],
                'warnings' => [],
            ]);

            return self::FAILURE;
        }

        $report = $dryRun->report(
            $this->positiveIntegerOptions('article'),
            $this->positiveIntegerOptions('ack-claim-warning'),
            (bool) $this->option('make-indexable'),
        );

        $this->emit($report);

        return ($report['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function positiveIntegerOptions(string $key): array
    {
        $ids = [];

        foreach ((array) $this->option($key) as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
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
        $this->line('rehearsal_state='.(string) ($payload['rehearsal_state'] ?? 'blocked'));
        $this->line('dry_run='.(($payload['dry_run'] ?? false) ? '1' : '0'));
        $this->line('no_write='.(($payload['no_write'] ?? false) ? '1' : '0'));
        $this->line('writes_attempted='.(($payload['writes_attempted'] ?? true) ? '1' : '0'));
    }
}
