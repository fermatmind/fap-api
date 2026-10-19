<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use RuntimeException;

final class SchedulerDispatchBudget
{
    public static function attach(Event $event): void
    {
        if ($event instanceof CallbackEvent) {
            return;
        }
        // Bound the child, not Laravel's mutex owner. Its ordinary finish path
        // records the failure and releases the task mutex before the watchdog.
        $command = $event->command;
        $event->createMutexNameUsing($event->mutexName());
        if ($event->runInBackground) {
            // Timeout the business child inside Laravel's background shell so
            // schedule:finish still runs and releases its native mutex.
            $seconds = min(1800, max(1, $event->expiresAt * 60 - 20));
            $event->command = 'timeout --signal=TERM --kill-after=10s '.$seconds.'s '.$command;
            $event->onFailure(static function () use ($event): void {
                \Illuminate\Support\Facades\Log::warning('scheduler_background_task_failed', [
                    'scope' => 'background_task', 'task_sha256' => hash('sha256', $event->command),
                    'exit_code' => $event->exitCode,
                ]);
            });

            return;
        }
        $event->before(static function () use ($event, $command): void {
            $deadline = getenv('FM_SCHEDULER_DEADLINE');
            if ($deadline === false) {
                return;
            }
            $remaining = (int) $deadline - time() - 1;
            if ($remaining < 1) {
                throw new RuntimeException('Scheduler dispatch budget exhausted.');
            }
            $event->command = 'timeout --signal=TERM --kill-after=1s '.$remaining.'s '.$command;
        });
    }
}
