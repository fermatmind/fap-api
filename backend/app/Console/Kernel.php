<?php

namespace App\Console;

use App\Console\Commands\AdminBootstrapOwner;
use App\Console\Commands\ArchiveColdData;
// ✅ 显式注册（更稳，避免自动扫描失效/缓存导致找不到）
use App\Console\Commands\Big5AttemptPurge;
use App\Console\Commands\Big5PsychometricsReport;
use App\Console\Commands\Big5TelemetrySummary;
use App\Console\Commands\CareerApplyOccupationDirectoryReviewDecisions;
use App\Console\Commands\CareerCompileAuthorityWave;
use App\Console\Commands\CareerCompileRecommendationSubjects;
use App\Console\Commands\CareerCrosswalkOps;
use App\Console\Commands\CareerExportCrosswalkBacklogConvergence;
use App\Console\Commands\CareerExportFirstWaveReleaseArtifacts;
use App\Console\Commands\CareerExportFirstWaveRolloutBundleArtifacts;
use App\Console\Commands\CareerExportFirstWaveRolloutWavePlanArtifact;
use App\Console\Commands\CareerExportFullReleaseLedger;
use App\Console\Commands\CareerExportLaunchGovernanceClosure;
use App\Console\Commands\CareerExportOccupationDirectoryReviewQueues;
use App\Console\Commands\CareerExportStrongIndexEligibility;
use App\Console\Commands\CareerImportAuthorityWave;
use App\Console\Commands\CareerImportOccupationDirectoryDrafts;
use App\Console\Commands\CareerImportOccupationDirectoryDryRun;
use App\Console\Commands\CareerRunAssetBatch;
use App\Console\Commands\CareerSyncOccupationDirectoryDisplay;
use App\Console\Commands\CareerValidateAssetImport;
use App\Console\Commands\CareerValidateOccupationDirectoryReviewQueues;
use App\Console\Commands\CareerWarmPublicAuthorityCache;
use App\Console\Commands\CiScaleImpact;
use App\Console\Commands\CommerceCompensatePendingOrders;
use App\Console\Commands\CommerceReconcile;
use App\Console\Commands\CommerceRepairPaidOrders;
use App\Console\Commands\CommerceRepairPostCommitFailed;
use App\Console\Commands\ContentCompile;
use App\Console\Commands\ContentLint;
use App\Console\Commands\EnneagramActivateRegistryRelease;
use App\Console\Commands\EnneagramExportProductionEquivalentCandidatePayloads;
use App\Console\Commands\EnneagramImportInactiveCandidateRelease;
use App\Console\Commands\EnneagramRollbackRegistryRelease;
use App\Console\Commands\Eq60PsychometricsReport;
use App\Console\Commands\FapEmailLifecycleRollout;
use App\Console\Commands\FapEmailOutboxSend;
use App\Console\Commands\FapResolvePack;
use App\Console\Commands\FapSelfCheck;
use App\Console\Commands\FapValidateReport;
use App\Console\Commands\FapWeeklyReport;
use App\Console\Commands\MbtiPrewarm;
use App\Console\Commands\MbtiUpgradeLegacyPartialUnlocks;
use App\Console\Commands\MetricsWeeklyValidity;
use App\Console\Commands\NormsBig5BootstrapBuild;
use App\Console\Commands\NormsBig5DriftCheck;
use App\Console\Commands\NormsBig5MonthlyDriftCheck;
use App\Console\Commands\NormsBig5Rebuild;
use App\Console\Commands\NormsBig5Roll;
use App\Console\Commands\NormsEq60Activate;
use App\Console\Commands\NormsEq60DriftCheck;
use App\Console\Commands\NormsEq60Import;
use App\Console\Commands\NormsImport;
use App\Console\Commands\NormsSdsDriftCheck;
use App\Console\Commands\NormsSdsRebuild;
use App\Console\Commands\Ops\BackfillAssessmentsScaleIdentity;
use App\Console\Commands\Ops\BackfillAttemptAnswerRowsScaleIdentity;
use App\Console\Commands\Ops\BackfillAttemptAnswerSetsScaleIdentity;
use App\Console\Commands\Ops\BackfillAttemptsScaleIdentity;
use App\Console\Commands\Ops\BackfillEventsScaleIdentity;
use App\Console\Commands\Ops\BackfillOrdersScaleIdentity;
use App\Console\Commands\Ops\BackfillPaymentEventsScaleIdentity;
use App\Console\Commands\Ops\BackfillReportSnapshotsScaleIdentity;
use App\Console\Commands\Ops\BackfillResultsScaleIdentity;
use App\Console\Commands\Ops\BackfillSharesScaleIdentity;
use App\Console\Commands\Ops\ContentPathMirror;
use App\Console\Commands\Ops\EvidencePack;
use App\Console\Commands\Ops\ExperimentGuardrailsEvaluate;
use App\Console\Commands\Ops\PartitionAttemptAnswerRows;
use App\Console\Commands\Ops\QueueBacklogProbe;
use App\Console\Commands\Ops\ScaleIdentityGate;
use App\Console\Commands\Ops\ScaleIdentityModeAudit;
use App\Console\Commands\OpsDeployEvent;
use App\Console\Commands\OpsHealthzSnapshot;
use App\Console\Commands\Packs2Activate;
use App\Console\Commands\Packs2List;
use App\Console\Commands\Packs2Publish;
use App\Console\Commands\Packs2Rollback;
use App\Console\Commands\PacksPublish;
use App\Console\Commands\PacksRollback;
use App\Console\Commands\PaymentsPruneEvents;
use App\Console\Commands\PersonalityImportDesktopCloneBaseline;
use App\Console\Commands\QualityDailySummary;
use App\Console\Commands\RefreshCareerAttributionDailyCommand;
use App\Console\Commands\SdsPsychometricsReport;
use App\Console\Commands\SeedScaleRegistry;
use App\Console\Commands\StorageControlPlaneSnapshot;
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
        FapEmailLifecycleRollout::class,
        FapResolvePack::class,
        FapSelfCheck::class,
        FapValidateReport::class,
        FapWeeklyReport::class,
        PersonalityImportDesktopCloneBaseline::class,
        MetricsWeeklyValidity::class,
        MbtiPrewarm::class,
        MbtiUpgradeLegacyPartialUnlocks::class,
        AdminBootstrapOwner::class,
        OpsDeployEvent::class,
        OpsHealthzSnapshot::class,
        ArchiveColdData::class,
        PaymentsPruneEvents::class,
        SeedScaleRegistry::class,
        SyncScaleSlugs::class,
        ContentLint::class,
        ContentCompile::class,
        EnneagramExportProductionEquivalentCandidatePayloads::class,
        EnneagramActivateRegistryRelease::class,
        EnneagramImportInactiveCandidateRelease::class,
        EnneagramRollbackRegistryRelease::class,
        NormsImport::class,
        NormsBig5Roll::class,
        NormsBig5Rebuild::class,
        NormsBig5DriftCheck::class,
        NormsBig5MonthlyDriftCheck::class,
        NormsBig5BootstrapBuild::class,
        Big5PsychometricsReport::class,
        NormsEq60Import::class,
        NormsEq60Activate::class,
        NormsEq60DriftCheck::class,
        Eq60PsychometricsReport::class,
        NormsSdsRebuild::class,
        NormsSdsDriftCheck::class,
        SdsPsychometricsReport::class,
        Big5AttemptPurge::class,
        Big5TelemetrySummary::class,
        CommerceReconcile::class,
        CommerceCompensatePendingOrders::class,
        CommerceRepairPaidOrders::class,
        CommerceRepairPostCommitFailed::class,
        CareerApplyOccupationDirectoryReviewDecisions::class,
        CareerImportAuthorityWave::class,
        CareerImportOccupationDirectoryDrafts::class,
        CareerImportOccupationDirectoryDryRun::class,
        CareerValidateAssetImport::class,
        CareerCompileAuthorityWave::class,
        CareerCompileRecommendationSubjects::class,
        CareerCrosswalkOps::class,
        CareerExportCrosswalkBacklogConvergence::class,
        CareerExportFullReleaseLedger::class,
        CareerExportLaunchGovernanceClosure::class,
        CareerExportOccupationDirectoryReviewQueues::class,
        CareerExportStrongIndexEligibility::class,
        CareerRunAssetBatch::class,
        CareerSyncOccupationDirectoryDisplay::class,
        CareerValidateOccupationDirectoryReviewQueues::class,
        CareerWarmPublicAuthorityCache::class,
        CareerExportFirstWaveRolloutBundleArtifacts::class,
        CareerExportFirstWaveReleaseArtifacts::class,
        CareerExportFirstWaveRolloutWavePlanArtifact::class,
        RefreshCareerAttributionDailyCommand::class,
        PacksPublish::class,
        PacksRollback::class,
        Packs2Publish::class,
        Packs2Activate::class,
        Packs2Rollback::class,
        Packs2List::class,
        StoragePrune::class,
        StorageMigrateLegacyArtifacts::class,
        StorageInventory::class,
        StorageControlPlaneSnapshot::class,
        QualityDailySummary::class,
        CiScaleImpact::class,
        ContentPathMirror::class,
        ScaleIdentityGate::class,
        ScaleIdentityModeAudit::class,
        BackfillAssessmentsScaleIdentity::class,
        BackfillAttemptAnswerRowsScaleIdentity::class,
        BackfillAttemptAnswerSetsScaleIdentity::class,
        BackfillAttemptsScaleIdentity::class,
        BackfillEventsScaleIdentity::class,
        BackfillOrdersScaleIdentity::class,
        BackfillPaymentEventsScaleIdentity::class,
        BackfillReportSnapshotsScaleIdentity::class,
        BackfillResultsScaleIdentity::class,
        BackfillSharesScaleIdentity::class,
        PartitionAttemptAnswerRows::class,
        QueueBacklogProbe::class,
        EvidencePack::class,
        ExperimentGuardrailsEvaluate::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 示例（需要就开）：
        // $schedule->command('fap:self-check')->dailyAt('03:10');
        // $schedule->command('fap:validate-report --attempt=...')->hourly();
        $schedule->command('email:lifecycle-rollout')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('email:outbox-send')->everyMinute()->withoutOverlapping();
        $schedule->command('storage:prune --execute --scope=reports_backups --strategy=strict')->dailyAt('03:10')->withoutOverlapping();
        $schedule->command('storage:prune --execute --scope=content_releases_retention')->dailyAt('03:20')->withoutOverlapping();
        $schedule->command('storage:prune --execute --scope=legacy_private_private_cleanup')->dailyAt('03:30')->withoutOverlapping();
        $schedule->command('storage:inventory --json')->weeklyOn(1, '04:10')->withoutOverlapping();
        $schedule->exec(PHP_BINARY.' '.base_path('artisan').' storage:control-plane-snapshot --json')->dailyAt('04:20')->withoutOverlapping();
        $schedule->command('payments:prune-events --days=90')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('commerce:repair-paid-orders --limit=50')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('commerce:repair-post-commit-failed --limit=50')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('quality:daily-summary')->dailyAt('03:20')->withoutOverlapping();
        $schedule->command('sds:psychometrics --window=last_7_days')->weeklyOn(1, '04:10')->withoutOverlapping();
        $schedule->command('eq60:psychometrics --window=last_90_days')->weeklyOn(1, '04:20')->withoutOverlapping();
        $schedule->command('norms:big5:roll --window_days=365')->monthlyOn(1, '04:30')->withoutOverlapping();
        $schedule->command('norms:big5:monthly-drift-check')->monthlyOn(1, '04:50')->withoutOverlapping();
        $schedule->command('norms:eq60:drift-check --from=active --to=candidate')->monthlyOn(1, '05:00')->withoutOverlapping();
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
