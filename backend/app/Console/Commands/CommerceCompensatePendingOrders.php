<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Commerce\Compensation\PendingOrderCompensationService;
use Illuminate\Console\Command;

final class CommerceCompensatePendingOrders extends Command
{
    protected $signature = 'commerce:compensate-pending-orders
        {--provider= : Limit to one provider}
        {--order= : Compensate one order_no}
        {--attempt= : Compensate one payment_attempt id}
        {--limit=20 : Max candidates to process}
        {--older-than-minutes=30 : Minimum stale window}
        {--dry-run : Evaluate without writing}
        {--close-expired : Close stale unpaid orders when provider supports it}
        {--only-stale : Require stale threshold even for targeted runs}
        {--include-created : Include created orders in candidate set}';

    protected $description = 'Command-driven pending lifecycle compensation for stale payment orders.';

    public function __construct(
        private readonly PendingOrderCompensationService $compensation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->compensation->compensate([
            'provider' => $this->option('provider'),
            'order' => $this->option('order'),
            'attempt' => $this->option('attempt'),
            'limit' => $this->option('limit'),
            'older_than_minutes' => $this->option('older-than-minutes'),
            'dry_run' => $this->option('dry-run'),
            'close_expired' => $this->option('close-expired'),
            'only_stale' => $this->option('only-stale'),
            'include_created' => $this->option('include-created'),
        ]);

        $this->line('Commerce pending compensation summary');
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('candidate_count='.(string) ($summary['candidate_count'] ?? 0));
        $this->line('processed_count='.(string) ($summary['processed_count'] ?? 0));
        $this->line('queried_count='.(string) ($summary['queried_count'] ?? 0));
        $this->line('paid_count='.(string) ($summary['paid_count'] ?? 0));
        $this->line('failed_count='.(string) ($summary['failed_count'] ?? 0));
        $this->line('canceled_count='.(string) ($summary['canceled_count'] ?? 0));
        $this->line('expired_count='.(string) ($summary['expired_count'] ?? 0));
        $this->line('unresolved_count='.(string) ($summary['unresolved_count'] ?? 0));
        $this->line('unsupported_count='.(string) ($summary['unsupported_count'] ?? 0));
        $this->line('close_attempted_count='.(string) ($summary['close_attempted_count'] ?? 0));
        $this->line('close_success_count='.(string) ($summary['close_success_count'] ?? 0));

        return self::SUCCESS;
    }
}
