<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use Illuminate\Console\Command;
use RuntimeException;

final class SeoWeeklyDecisionScheduled extends Command
{
    protected $signature = 'seo:weekly-decisions
        {--trigger=manual : Receipt provenance; the scheduler supplies scheduled}
        {--json : Emit the sanitized receipt as JSON}';

    protected $description = 'Materialize the bounded ISO-week SEO selection and natural scheduler receipt';

    public function handle(SeoWeeklyDecisionReceiptService $service): int
    {
        $trigger = (string) $this->option('trigger');
        $deadlineArmed = $trigger === 'scheduled'
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_alarm');
        if ($deadlineArmed) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function (): never {
                throw new RuntimeException('Weekly decision transaction exceeded its 50 second deadline.');
            });
            pcntl_alarm(SeoWeeklyDecisionReceiptService::TRANSACTION_DEADLINE_SECONDS);
        }

        try {
            $receipt = $service->record($trigger);
        } finally {
            if ($deadlineArmed) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, SIG_DFL);
            }
        }
        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('status='.(string) ($receipt['status'] ?? 'MEASUREMENT_HOLD'));
            $this->line('selection_revision='.(string) ($receipt['selection_revision'] ?? ''));
        }

        return $trigger !== 'scheduled'
            || ($receipt['status'] ?? null) === 'scheduled_completed'
                ? self::SUCCESS
                : self::FAILURE;
    }
}
