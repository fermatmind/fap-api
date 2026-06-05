<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ScienceContentPageDraftDryRunService;
use Illuminate\Console\Command;
use Throwable;

final class ScienceContentPageDraftDryRunCommand extends Command
{
    protected $signature = 'content-pages:science-draft-dry-run
        {--package= : Path to the Science ContentPage CMS draft package directory}
        {--json : Emit machine-readable JSON only}';

    protected $description = 'Validate the Science ContentPage CMS draft package mapping without writing ContentPage rows.';

    public function handle(ScienceContentPageDraftDryRunService $service): int
    {
        try {
            $package = trim((string) $this->option('package'));
            if ($package === '') {
                throw new \RuntimeException('--package is required.');
            }

            $summary = $service->dryRun($package);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->line('task='.$summary['task']);
            $this->line('mode='.$summary['mode']);
            $this->line('dry_run='.(($summary['dry_run'] ?? false) ? 'true' : 'false'));
            $this->line('would_write='.(($summary['would_write'] ?? false) ? 'true' : 'false'));
            $this->line('pages_seen='.$summary['pages_seen']);
            $this->line('pages_expected='.$summary['pages_expected']);
            $this->line('pages_ready_for_non_public_draft_import='.$summary['pages_ready_for_non_public_draft_import']);
            $this->line('pages_blocked='.$summary['pages_blocked']);
            $this->line('issue_count='.$summary['issue_count']);
            $this->line('status='.$summary['status']);

            foreach ($summary['pages'] as $page) {
                $this->line(sprintf(
                    'page=%s action=%s decision=%s slug=%s kind=%s status=%s public=%s indexable=%s',
                    (string) ($page['page_key'] ?? 'Unknown'),
                    (string) ($page['planned_action'] ?? 'Unknown'),
                    (string) ($page['draft_import_decision'] ?? 'Unknown'),
                    (string) data_get($page, 'normalized_content_page.slug', 'Unknown'),
                    (string) data_get($page, 'normalized_content_page.kind', 'Unknown'),
                    (string) data_get($page, 'normalized_content_page.status', 'Unknown'),
                    data_get($page, 'normalized_content_page.is_public') === false ? 'false' : 'true',
                    data_get($page, 'normalized_content_page.is_indexable') === false ? 'false' : 'true',
                ));
            }

            if (($summary['issue_count'] ?? 0) > 0) {
                $this->warn('dry-run completed with blockers; no writes performed.');

                return self::SUCCESS;
            }

            $this->info('dry-run validation complete; no writes performed.');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
