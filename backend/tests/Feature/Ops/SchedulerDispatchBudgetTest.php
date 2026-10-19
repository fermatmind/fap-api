<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Ops\SchedulerDispatchBudget;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class SchedulerDispatchBudgetTest extends TestCase
{
    public function test_child_timeout_finishes_and_releases_the_native_event_mutex(): void
    {
        config(['cache.default' => 'array']);
        $schedule = new Schedule;
        $event = $schedule->exec(PHP_BINARY.' -r '.escapeshellarg('sleep(10);'))
            ->name('synthetic-budget-mutex')->withoutOverlapping();
        $originalMutex = $event->mutexName();
        SchedulerDispatchBudget::attach($event);
        putenv('FM_SCHEDULER_DEADLINE='.(time() + 2));
        try {
            $event->run($this->app);
            $this->assertSame(124, $event->exitCode);
            $this->assertSame($originalMutex, $event->mutexName());
            $this->assertFalse($event->mutex->exists($event));
            $next = $schedule->exec(PHP_BINARY.' -r '.escapeshellarg('exit(0);'))
                ->name('synthetic-budget-next')->withoutOverlapping();
            $next->run($this->app);
            $this->assertSame(0, $next->exitCode);
        } finally {
            putenv('FM_SCHEDULER_DEADLINE');
        }
    }
}
