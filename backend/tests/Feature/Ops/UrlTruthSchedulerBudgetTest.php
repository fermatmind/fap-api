<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Ops\SchedulerDispatchBudget;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class UrlTruthSchedulerBudgetTest extends TestCase
{
    public function test_existing_daily_truth_event_uses_the_native_bounded_background_path(): void
    {
        $command = 'seo-intel:url-truth-controlled-reconcile --execute --no-http --max-records=5000 --batch-size=250';
        \Illuminate\Support\Facades\Artisan::call('schedule:list', ['--json' => true]);
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($e) => str_contains((string) $e->command, $command)));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('10 6 * * *', $event->expression);
        $this->assertSame('Asia/Shanghai', $event->timezone);
        $this->assertStringContainsString('1800s', $event->command);
        $original = (new Schedule)->command($command)->timezone('Asia/Shanghai')->dailyAt('06:10')->withoutOverlapping()->onOneServer();
        $this->assertSame($original->mutexName(), $event->mutexName(), 'Changing dispatch mode must preserve the existing lock identity.');
    }

    public function test_background_events_share_the_native_lock_across_scheduler_instances(): void
    {
        config(['cache.default' => 'array']);
        $command = PHP_BINARY.' -r '.escapeshellarg('exit(0);');
        $first = (new Schedule)->exec($command)->runInBackground()->withoutOverlapping()->onOneServer();
        $second = (new Schedule)->exec($command)->runInBackground()->withoutOverlapping()->onOneServer();
        SchedulerDispatchBudget::attach($first, time() - 1);
        SchedulerDispatchBudget::attach($second, time() - 1);
        $this->assertStringContainsString('1800s', $first->command);
        $this->assertTrue($first->mutex->create($first));
        $this->assertFalse($second->mutex->create($second));
        $first->finish($this->app, 0);
        $this->assertTrue($second->mutex->create($second));
        $second->finish($this->app, 124);
        $this->assertFalse($first->mutex->exists($first));
    }
}
