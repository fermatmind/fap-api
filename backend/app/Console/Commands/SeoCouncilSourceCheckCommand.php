<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet;
use App\Services\SeoCouncil\Platform12\Platform12SourceCheck;
use Illuminate\Console\Command;

final class SeoCouncilSourceCheckCommand extends Command
{
    protected $signature = 'seo:council-source-check {--mission= : One formal daily Mission ID} {--json}';

    protected $description = 'Read fixed Council sources without submission, notification or activation';

    public function handle(Platform12SourceCheck $check): int
    {
        $id = $this->option('mission');
        if (! is_string($id) || ! in_array($id, Platform12DailyMissionSet::IDS, true)) {
            $this->line('{"status":"MISSION_SCOPE_DENIED"}');

            return self::FAILURE;
        }
        $handler = null;
        if (! app()->environment('testing')) {
            if (! function_exists('pcntl_alarm')) {
                $this->line('{"status":"EXECUTION_DEADLINE_UNAVAILABLE_HOLD"}');

                return self::FAILURE;
            }
            $handler = pcntl_signal_get_handler(SIGALRM);
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, static function (): never {
                exit(124);
            });
            pcntl_alarm(120);
        }
        try {
            $report = $check->check($id);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            // A completed observation of HOLD is still a successful read-only command.
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->line('{"status":"SOURCE_CHECK_UNAVAILABLE","business_write_enabled":false}');

            return self::FAILURE;
        } finally {
            if ($handler !== null) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $handler);
            }
        }
    }
}
