<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ScienceContentPagePreImportQaService;
use Illuminate\Console\Command;
use Throwable;

final class ScienceContentPagePreImportQaCommand extends Command
{
    protected $signature = 'content-pages:science-pre-import-qa
        {--package= : Path to the Science ContentPage CMS draft package directory}
        {--json : Emit machine-readable JSON only}';

    protected $description = 'Read-only pre-real-import QA gate for Science ContentPage drafts.';

    public function handle(ScienceContentPagePreImportQaService $service): int
    {
        try {
            $package = trim((string) $this->option('package'));
            if ($package === '') {
                throw new \RuntimeException('--package is required.');
            }

            $summary = $service->check($package);

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }

            $this->line('task='.$summary['task']);
            $this->line('mode='.$summary['mode']);
            $this->line('decision='.$summary['decision']);
            $this->line('cms_mutation_performed='.(($summary['cms_mutation_performed'] ?? true) ? 'true' : 'false'));
            $this->line('database_writes_allowed='.(($summary['database_writes_allowed'] ?? true) ? 'true' : 'false'));
            $this->line('content_import_performed='.(($summary['content_import_performed'] ?? true) ? 'true' : 'false'));
            $this->line('publish_performed='.(($summary['publish_performed'] ?? true) ? 'true' : 'false'));
            $this->line('non_public_draft_import_qa_passed='.(($summary['non_public_draft_import_qa_passed'] ?? false) ? 'true' : 'false'));
            $this->line('real_import_allowed='.(($summary['real_import_allowed'] ?? true) ? 'true' : 'false'));
            $this->line('publish_allowed='.(($summary['publish_allowed'] ?? true) ? 'true' : 'false'));
            $this->line('natural_distribution_allowed='.(($summary['natural_distribution_allowed'] ?? true) ? 'true' : 'false'));
            $this->line('real_import_contract_locked='.(data_get($summary, 'real_import_contract.locked') === true ? 'true' : 'false'));
            $this->line('real_import_dry_run_only='.(data_get($summary, 'real_import_contract.dry_run_only') === true ? 'true' : 'false'));
            $this->line('real_import_command_authorized='.(data_get($summary, 'real_import_contract.real_import_command_authorized') === true ? 'true' : 'false'));
            $this->line('real_import_requires_separate_import_command_pr='.(data_get($summary, 'real_import_contract.requires_separate_import_command_pr') === true ? 'true' : 'false'));
            $this->line('package_pre_import_qa_issue_count='.$summary['package_pre_import_qa_issue_count']);
            $this->line('dry_run_pages_blocked='.data_get($summary, 'dry_run.pages_blocked', 'Unknown'));
            $this->line('operator_publish_decision_ready='.(data_get($summary, 'operator_review.operator_publish_decision_ready') === true ? 'true' : 'false'));

            foreach (($summary['blocking_reasons'] ?? []) as $reason) {
                $this->line('blocking_reason='.$reason);
            }

            foreach (($summary['issues'] ?? []) as $issue) {
                $this->line(sprintf(
                    'issue scope=%s code=%s message=%s',
                    (string) ($issue['scope'] ?? 'Unknown'),
                    (string) ($issue['code'] ?? 'Unknown'),
                    (string) ($issue['message'] ?? 'Unknown'),
                ));
            }

            $this->warn('pre-import QA is read-only; real import and publish remain blocked unless all external approval gates are satisfied.');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
