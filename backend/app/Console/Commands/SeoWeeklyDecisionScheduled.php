<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use Illuminate\Console\Command;

final class SeoWeeklyDecisionScheduled extends Command
{
    protected $signature = 'seo:weekly-decisions
        {--trigger=manual : Receipt provenance; the scheduler supplies scheduled}
        {--json : Emit the sanitized receipt as JSON}';

    protected $description = 'Materialize the bounded ISO-week SEO selection and natural scheduler receipt';

    public function handle(SeoWeeklyDecisionReceiptService $service): int
    {
        $receipt = $service->record((string) $this->option('trigger'));
        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('status='.(string) ($receipt['status'] ?? 'MEASUREMENT_HOLD'));
            $this->line('selection_revision='.(string) ($receipt['selection_revision'] ?? ''));
        }

        return (string) $this->option('trigger') !== 'scheduled'
            || ($receipt['status'] ?? null) === 'scheduled_completed'
                ? self::SUCCESS
                : self::FAILURE;
    }
}
