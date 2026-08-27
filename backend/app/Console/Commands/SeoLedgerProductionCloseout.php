<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\Ledger\SeoLedgerProductionCloseoutService;
use Illuminate\Console\Command;

final class SeoLedgerProductionCloseout extends Command
{
    protected $signature = 'seo-ledger:production-closeout
        {--expected-sha= : Exact deployed repository SHA}
        {--permission-negative-status= : HTTP status from the unauthenticated protected snapshot probe}
        {--allow-unproven : Return success for a non-production staging observation}
        {--json : Emit the sanitized receipt as JSON}';

    protected $description = 'Read and verify the deployed SEO ledger capability without writes';

    public function handle(SeoLedgerProductionCloseoutService $service): int
    {
        $status = filter_var($this->option('permission-negative-status'), FILTER_VALIDATE_INT);
        $receipt = $service->evaluate(
            (string) $this->option('expected-sha'),
            is_int($status) ? $status : 0,
        );

        $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->line($encoded);

        if (($receipt['state'] ?? null) === 'production_proven' || (bool) $this->option('allow-unproven')) {
            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
