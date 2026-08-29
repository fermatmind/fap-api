<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use Illuminate\Console\Command;
use Throwable;

final class SeoCouncilScheduledCommand extends Command
{
    protected $signature = 'seo:council-scheduled {--json}';

    protected $description = 'Submit the fixed scheduled SEO Council MissionRequest when the production-off gate is enabled';

    public function handle(ScheduledMissionAdapter $adapter): int
    {
        if (app()->environment('production') || ! (bool) config('seo_council.scheduler_enabled', false)) {
            $this->line('{"status":"SCHEDULER_DISABLED","execution_allowed":false}');

            return self::SUCCESS;
        }
        try {
            $input = json_decode((string) file_get_contents((string) config('seo_council.scheduled_request')), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw new \RuntimeException('SCHEDULED_REQUEST_INVALID');
            }
            $this->line(json_encode($adapter->submit($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"status":"SCHEDULED_REQUEST_INVALID","execution_allowed":false}');

            return self::FAILURE;
        }
    }
}
