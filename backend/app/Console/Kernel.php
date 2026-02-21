<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

// ✅ 显式注册（更稳，避免自动扫描失效/缓存导致找不到）
use App\Console\Commands\FapResolvePack;
use App\Console\Commands\FapSelfCheck;
use App\Console\Commands\FapValidateReport;
use App\Console\Commands\FapWeeklyReport;
use App\Console\Commands\FapEmailOutboxSend;
use App\Console\Commands\MetricsWeeklyValidity;
use App\Console\Commands\AdminBootstrapOwner;
use App\Console\Commands\OpsDeployEvent;
use App\Console\Commands\OpsHealthzSnapshot;
use App\Console\Commands\ArchiveColdData;
use App\Console\Commands\PaymentsPruneEvents;
use App\Console\Commands\SeedScaleRegistry;
use App\Console\Commands\SyncScaleSlugs;
use App\Console\Commands\ContentLint;
use App\Console\Commands\ContentCompile;
use App\Console\Commands\CiScaleImpact;
use App\Console\Commands\NormsImport;
use App\Console\Commands\NormsBig5Roll;
use App\Console\Commands\NormsBig5Rebuild;
use App\Console\Commands\NormsBig5DriftCheck;
use App\Console\Commands\Big5PsychometricsReport;
use App\Console\Commands\Ops\PartitionAttemptAnswerRows;
use App\Console\Commands\PacksPublish;
use App\Console\Commands\PacksRollback;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * 说明：
     * - 你也可以只保留 commands() 里的 $this->load(__DIR__.'/Commands') 自动加载。
     * - 但显式注册更像“长期运营/CI 稳”的风格：不怕缓存、不怕目录扫描没命中。
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        FapEmailOutboxSend::class,
        FapResolvePack::class,
        FapSelfCheck::class,
        FapValidateReport::class,
        FapWeeklyReport::class,
        MetricsWeeklyValidity::class,
        AdminBootstrapOwner::class,
        OpsDeployEvent::class,
        OpsHealthzSnapshot::class,
        ArchiveColdData::class,
        PaymentsPruneEvents::class,
        SeedScaleRegistry::class,
        SyncScaleSlugs::class,
        ContentLint::class,
        ContentCompile::class,
        NormsImport::class,
        NormsBig5Roll::class,
        NormsBig5Rebuild::class,
        NormsBig5DriftCheck::class,
        Big5PsychometricsReport::class,
        PacksPublish::class,
        PacksRollback::class,
        CiScaleImpact::class,
        PartitionAttemptAnswerRows::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 示例（需要就开）：
        // $schedule->command('fap:self-check')->dailyAt('03:10');
        // $schedule->command('fap:validate-report --attempt=...')->hourly();
        $schedule->command('payments:prune-events --days=90')->daily()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // ✅ 自动加载 app/Console/Commands 目录下的所有命令类
        $this->load(__DIR__ . '/Commands');

        // ✅ console routes（如果你用得到）
        require base_path('routes/console.php');
    }
}
