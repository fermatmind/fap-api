<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Tests\TestCase;

final class SchedulerDispatcherFailureTest extends TestCase
{
    public function test_a_real_laravel_caught_task_failure_marks_the_wrapper_tick(): void
    {
        $this->artisan('schedule:list', ['--json' => true])->assertSuccessful();
        $schedule = new Schedule;
        foreach ([false, true] as $background) {
            $event = $schedule->exec(PHP_BINARY.' -r '.escapeshellarg('exit(0);'))
                ->before(static fn () => throw new RuntimeException('Synthetic scheduled failure'))->everyMinute();
            if ($background) {
                $event->runInBackground();
            }
        }
        $this->app->instance(Schedule::class, $schedule);
        $path = storage_path('app/ops/scheduler-failure-test-'.bin2hex(random_bytes(8)));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, '');
        putenv('FM_SCHEDULER_FAILURE_FILE='.$path);
        try {
            // Laravel catches the event error, so its own exit code is zero.
            // The real event observer supplies the failure used by the wrapper.
            $this->artisan('schedule:run')->assertSuccessful();
            $this->assertSame('failed', file_get_contents($path));
        } finally {
            putenv('FM_SCHEDULER_FAILURE_FILE');
            @unlink($path);
        }
    }
}
