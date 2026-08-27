<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionCloseoutService;
use Illuminate\Console\Command;

final class SeoWeeklyDecisionCloseout extends Command
{
    protected $signature = 'seo:weekly-decision-closeout
        {--expected-sha= : Exact active release SHA}
        {--wait-seconds=0 : Bounded wait for the natural scheduler receipt}
        {--allow-unproven : Return success while staging has no natural receipt}
        {--json : Emit the sanitized closeout as JSON}';

    protected $description = 'Read and verify the natural weekly SEO decision receipt without creating one';

    public function handle(SeoWeeklyDecisionCloseoutService $service): int
    {
        $waitSeconds = max(0, min(1200, (int) $this->option('wait-seconds')));
        $deadline = time() + $waitSeconds;
        do {
            $receipt = $service->evaluate((string) $this->option('expected-sha'));
            if (($receipt['state'] ?? null) === 'production_proven' || time() >= $deadline) {
                break;
            }
            sleep(10);
        } while (true);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('state='.(string) ($receipt['state'] ?? 'production_unproven'));
        }

        return ($receipt['state'] ?? null) === 'production_proven' || (bool) $this->option('allow-unproven')
            ? self::SUCCESS
            : self::FAILURE;
    }
}
