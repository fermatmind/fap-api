<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ScienceContentPageOperatorReviewReadinessService;
use Illuminate\Console\Command;
use Throwable;

final class ScienceContentPageOperatorReviewReadinessCommand extends Command
{
    protected $signature = 'content-pages:science-operator-review-readiness
        {--package= : Optional path to the Science ContentPage CMS draft package directory}
        {--json : Emit machine-readable JSON only}';

    protected $description = 'Read-only check for Science ContentPage CMS operator review field readiness.';

    public function handle(ScienceContentPageOperatorReviewReadinessService $service): int
    {
        try {
            $package = trim((string) $this->option('package'));
            $summary = $service->review($package !== '' ? $package : null);

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
            $this->line('operator_review_ready_for_non_public_draft='.(($summary['operator_review_ready_for_non_public_draft'] ?? false) ? 'true' : 'false'));
            $this->line('operator_publish_decision_ready='.(($summary['operator_publish_decision_ready'] ?? true) ? 'true' : 'false'));
            $this->line('publish_allowed_default='.(($summary['publish_allowed_default'] ?? true) ? 'true' : 'false'));
            $this->line('draft_pages_reviewable='.data_get($summary, 'draft_package.pages_reviewable_as_non_public_draft', 'Unknown'));
            $this->line('draft_pages_requiring_authority_reconciliation='.data_get($summary, 'draft_package.pages_requiring_authority_reconciliation', 'Unknown'));

            foreach (($summary['missing_first_class_publish_safety_fields'] ?? []) as $field) {
                $this->line('missing_first_class_publish_safety_field='.$field);
            }

            $this->warn((string) $summary['reason']);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
