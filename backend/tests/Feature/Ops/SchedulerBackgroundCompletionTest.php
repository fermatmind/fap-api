<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Ops\SchedulerDispatchBudget;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SchedulerBackgroundCompletionTest extends TestCase
{
    public function test_independent_native_finish_releases_mutex_and_logs_failed_business_children(): void
    {
        $root = sys_get_temp_dir().'/scheduler-background-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $root.'/cache']);
        $this->app['cache']->forgetDriver('file');
        try {
            foreach ([0, 7, 124] as $expected) {
                $code = $expected === 124 ? 'sleep(10);' : 'exit('.$expected.');';
                $command = PHP_BINARY.' -r '.escapeshellarg($code);
                $event = (new Schedule)->exec($command)->runInBackground()->withoutOverlapping(5);
                $originalMutex = $event->mutexName();
                SchedulerDispatchBudget::attach($event);
                $this->assertSame($originalMutex, $event->mutexName());
                $this->assertStringContainsString('280s', $event->command);
                $this->assertTrue($event->mutex->create($event));
                $this->assertTrue($event->mutex->exists($event));
                $business = Process::fromShellCommandline('timeout --signal=TERM --kill-after=1s 1s '.$command);
                $business->run();
                $this->assertSame($expected, $business->getExitCode());

                // Model the separate PHP process used by Laravel's background
                // shell, using the actual schedule:finish command and file mutex.
                $finish = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
                    .'$app=require '.var_export(base_path('bootstrap/app.php'), true).';'
                    .'$kernel=$app->make(Illuminate\\Contracts\\Console\\Kernel::class);$kernel->bootstrap();'
                    .'config(["cache.default"=>"file","cache.stores.file.path"=>'.var_export($root.'/cache', true).']);'
                    .'$app["cache"]->forgetDriver("file");'
                    .'Illuminate\\Support\\Facades\\Log::swap(Illuminate\\Support\\Facades\\Log::build(["driver"=>"single","path"=>'.var_export($root.'/result-'.$expected.'.log', true).']));'
                    .'$schedule=new Illuminate\\Console\\Scheduling\\Schedule;'
                    .'$event=$schedule->exec('.var_export($command, true).')->runInBackground()->withoutOverlapping(5);'
                    .'App\\Services\\Ops\\SchedulerDispatchBudget::attach($event);'
                    .'$app->instance(Illuminate\\Console\\Scheduling\\Schedule::class,$schedule);'
                    .'exit($kernel->call("schedule:finish",["id"=>$event->mutexName(),"code"=>'.var_export((string) $expected, true).']));';
                (new Process([PHP_BINARY, '-r', $finish], base_path(), ['APP_ENV' => 'testing']))->mustRun();
                $this->assertFalse($event->mutex->exists($event));
                if ($expected !== 0) {
                    $log = file_get_contents($root.'/result-'.$expected.'.log');
                    $this->assertStringContainsString('scheduler_background_task_failed', $log);
                    $this->assertStringContainsString('"exit_code":'.$expected, $log);
                }
            }
        } finally {
            (new Process(['find', $root, '-depth', '-delete']))->mustRun();
        }
    }
}
