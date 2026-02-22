<?php

namespace App\Console;

use App\Console\Commands\AdminBootstrapOwner;
use App\Console\Commands\ArchiveColdData;
// ✅ 显式注册（更稳，避免自动扫描失效/缓存导致找不到）
use App\Console\Commands\Big5AttemptPurge;
use App\Console\Commands\Big5PsychometricsReport;
use App\Console\Commands\Big5TelemetrySummary;
use App\Console\Commands\CiScaleImpact;
use App\Console\Commands\CommerceReconcile;
use App\Console\Commands\ContentCompile;
use App\Console\Commands\ContentLint;
use App\Console\Commands\FapEmailOutboxSend;
use App\Console\Commands\FapResolvePack;
use App\Console\Commands\FapSelfCheck;
use App\Console\Commands\FapValidateReport;
use App\Console\Commands\FapWeeklyReport;
use App\Console\Commands\MetricsWeeklyValidity;
use App\Console\Commands\NormsBig5BootstrapBuild;
use App\Console\Commands\NormsBig5DriftCheck;
use App\Console\Commands\NormsBig5MonthlyDriftCheck;
use App\Console\Commands\NormsBig5Rebuild;
use App\Console\Commands\NormsBig5Roll;
use App\Console\Commands\NormsImport;
use App\Console\Commands\NormsSdsDriftCheck;
use App\Console\Commands\NormsSdsRebuild;
use App\Console\Commands\Ops\PartitionAttemptAnswerRows;
use App\Console\Commands\OpsDeployEvent;
use App\Console\Commands\OpsHealthzSnapshot;
use App\Console\Commands\Packs2Activate;
use App\Console\Commands\Packs2List;
use App\Console\Commands\Packs2MigrateStoragePath;
use App\Console\Commands\Packs2Publish;
use App\Console\Commands\Packs2Rollback;
use App\Console\Commands\PacksPublish;
use App\Console\Commands\PacksRollback;
use App\Console\Commands\PaymentsPruneEvents;
use App\Console\Commands\QualityDailySummary;
use App\Console\Commands\SdsPsychometricsReport;
use App\Console\Commands\SeedScaleRegistry;
use App\Console\Commands\StorageInventory;
use App\Console\Commands\StorageMigrateLegacyArtifacts;
use App\Console\Commands\StoragePrune;
use App\Console\Commands\SyncScaleSlugs;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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
        NormsBig5MonthlyDriftCheck::class,
        NormsBig5BootstrapBuild::class,
        Big5PsychometricsReport::class,
        NormsSdsRebuild::class,
        NormsSdsDriftCheck::class,
        SdsPsychometricsReport::class,
        Big5AttemptPurge::class,
        Big5TelemetrySummary::class,
        CommerceReconcile::class,
        PacksPublish::class,
        PacksRollback::class,
        Packs2Publish::class,
        Packs2Activate::class,
        Packs2Rollback::class,
        Packs2List::class,
        Packs2MigrateStoragePath::class,
        StoragePrune::class,
        StorageInventory::class,
        StorageMigrateLegacyArtifacts::class,
        QualityDailySummary::class,
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
        $schedule->command('payments:prune-events --days=90')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('storage:prune --execute')->dailyAt('03:10')->withoutOverlapping();
        $schedule->command('quality:daily-summary')->dailyAt('03:20')->withoutOverlapping();
        $schedule->command('storage:inventory --json')->weeklyOn(1, '04:10')->withoutOverlapping();
        $schedule->command('sds:psychometrics --window=last_7_days')->weeklyOn(1, '04:10')->withoutOverlapping();
        $schedule->command('norms:big5:roll --window_days=365')->monthlyOn(1, '04:30')->withoutOverlapping();
        $schedule->command('norms:big5:monthly-drift-check')->monthlyOn(1, '04:50')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // ✅ 自动加载 app/Console/Commands 目录下的所有命令类
        $this->load(__DIR__.'/Commands');

        // ✅ console routes（如果你用得到）
        require base_path('routes/console.php');
    }
}
