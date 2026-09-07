<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Platform12DailyScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

final class SeoCouncilScheduledCommand extends Command
{
    protected $signature = 'seo:council-scheduled {--json} {--acceptance= : One allowlisted Mission; never a natural slot}';

    protected $description = 'Discover at most one due read-only Council Mission from the versioned Catalog';

    public function handle(Platform12DailyScheduler $scheduler): int
    {
        if (! (bool) config('seo_council.scheduler_enabled', false)) {
            $this->line('{"status":"SCHEDULER_DISABLED","execution_allowed":false}');

            return self::SUCCESS;
        }
        if (! app()->environment('testing') && ! function_exists('pcntl_alarm')) {
            $this->line('{"status":"EXECUTION_DEADLINE_UNAVAILABLE_HOLD","execution_allowed":false}');

            return self::FAILURE;
        }
        // Managed cron runs as the deploy identity; the application owns the
        // private activation files. Delegate only this fixed natural tick, using
        // the existing non-interactive PHP capability, without changing file ACLs.
        if (app()->environment('production')
            && (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid') || (posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'www-data')) {
            if ($this->option('acceptance') !== null) {
                $this->line('{"status":"SCHEDULED_IDENTITY_HOLD","execution_allowed":false}');

                return self::FAILURE;
            }
            try {
                $result = Process::timeout(120)->run([
                    '/usr/bin/sudo', '-n', '-u', 'www-data', '--', PHP_BINARY,
                    base_path('artisan'), 'seo:council-scheduled', '--json',
                ]);
                if ($result->successful()) {
                    $this->line(trim($result->output()));

                    return self::SUCCESS;
                }
            } catch (Throwable) {
                // No stderr, private paths or environment values enter receipts.
            }
            $this->line('{"status":"SCHEDULED_IDENTITY_HOLD","execution_allowed":false}');

            return self::FAILURE;
        }
        $previousHandler = null;
        if (! app()->environment('testing')) {
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_async_signals(true);
            // A hard wall-clock ceiling also stops a blocked database read.
            // Unfinished frozen deliveries are reconciled by the next bounded tick.
            pcntl_signal(SIGALRM, static function (): never {
                exit(124);
            });
            pcntl_alarm(120);
        }
        try {
            $acceptance = $this->option('acceptance');
            $this->line(json_encode($scheduler->tick(is_string($acceptance) && $acceptance !== '' ? $acceptance : null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('{"status":"SCHEDULED_REQUEST_INVALID","execution_allowed":false}');

            return self::FAILURE;
        } finally {
            if ($previousHandler !== null) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previousHandler);
            }
        }
    }
}
