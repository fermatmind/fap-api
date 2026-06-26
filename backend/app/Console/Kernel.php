<?php

namespace App\Console;

use App\Console\Commands\AdminBootstrapOwner;
use App\Console\Commands\ArchiveColdData;
use App\Console\Commands\ArticleCoverPropagationSmoke;
use App\Console\Commands\ArticleDiscoverabilityRelease;
use App\Console\Commands\ArticleEnsureSeoMetaBaseline;
use App\Console\Commands\ArticleImportEditorialPackage;
use App\Console\Commands\ArticleImportSeoContentPackageDraft;
use App\Console\Commands\ArticlePublishControlled;
use App\Console\Commands\ArticleReleaseCloseout;
use App\Console\Commands\ArticleReplaceInlineImageUrl;
use App\Console\Commands\ArticleSeoGateRollout;
use App\Console\Commands\ArticleTaxonomyHygiene;
use App\Console\Commands\ArticleUpdateExistingSeoContentPackage;
use App\Console\Commands\ArticleUpdateImageMetadata;
use App\Console\Commands\ArticleUpdateTranslationGroupId;
use App\Console\Commands\ArticleWeeklySeoObservationExport;
use App\Console\Commands\Batch2ResultPageDryRunGateCommand;
use App\Console\Commands\Batch2ResultPageReadbackReviewLedgerCommand;
use App\Console\Commands\Big5AttemptPurge;
// ✅ 显式注册（更稳，避免自动扫描失效/缓存导致找不到）
use App\Console\Commands\Big5PsychometricsReport;
use App\Console\Commands\Big5TelemetrySummary;
use App\Console\Commands\BigFiveExportProductionEquivalentCandidatePayloads;
use App\Console\Commands\BigFiveImportInactiveCandidateRelease;
use App\Console\Commands\BigFiveResultPageV2AssetAgentAuditCommand;
use App\Console\Commands\CareerAlignActorsAuthorityOccupation;
use App\Console\Commands\CareerAlignCareerAuthorityBatch;
use App\Console\Commands\CareerAlignD8AuthorityCrosswalks;
use App\Console\Commands\CareerAlignSelectedAuthorityCrosswalks;
use App\Console\Commands\CareerAlignSelectedOnetCrosswalks;
use App\Console\Commands\CareerApplyOccupationDirectoryReviewDecisions;
use App\Console\Commands\CareerAuditCanonicalEligibility;
use App\Console\Commands\CareerAuditDetailReady1048Candidates;
use App\Console\Commands\CareerAuditZhDisplayParity;
use App\Console\Commands\CareerCloseoutCanonicalProgressiveCohort;
use App\Console\Commands\CareerCompileAuthorityWave;
use App\Console\Commands\CareerCompileRecommendationSubjects;
use App\Console\Commands\CareerCrosswalkOps;
use App\Console\Commands\CareerExportCanonicalEligibilityDbContext;
use App\Console\Commands\CareerExportCanonicalEligibilitySurfaceContext;
use App\Console\Commands\CareerExportCanonicalExpansionManifest;
use App\Console\Commands\CareerExportCanonicalRuntimeTruth;
use App\Console\Commands\CareerExportCrosswalkBacklogConvergence;
use App\Console\Commands\CareerExportFirstWaveReleaseArtifacts;
use App\Console\Commands\CareerExportFirstWaveRolloutBundleArtifacts;
use App\Console\Commands\CareerExportFirstWaveRolloutWavePlanArtifact;
use App\Console\Commands\CareerExportFullReleaseLedger;
use App\Console\Commands\CareerExportLaunchGovernanceClosure;
use App\Console\Commands\CareerExportOccupationDirectoryReviewQueues;
use App\Console\Commands\CareerExportPublicTrustTaxonomy;
use App\Console\Commands\CareerExportRuntimePublishProjection;
use App\Console\Commands\CareerExportStrongIndexEligibility;
use App\Console\Commands\CareerFinalizeCanonicalRuntimeTruth;
use App\Console\Commands\CareerFullDisplayWorkbookValidator;
use App\Console\Commands\CareerGenerateCanonicalDeltaRolloutManifest;
use App\Console\Commands\CareerGenerateCanonicalExpansionManifestTrain;
use App\Console\Commands\CareerGenerateCanonicalProgressiveRolloutManifest;
use App\Console\Commands\CareerImportActorsDisplayAsset;
use App\Console\Commands\CareerImportAuthorityWave;
use App\Console\Commands\CareerImportDetailReadyReplacementAuthority;
use App\Console\Commands\CareerImportDetailReadyReplacementAuthoritySource;
use App\Console\Commands\CareerImportOccupationDirectoryDrafts;
use App\Console\Commands\CareerImportOccupationDirectoryDryRun;
use App\Console\Commands\CareerImportSelectedDisplayAssets;
use App\Console\Commands\CareerNormalizeLegacyDisplayAssets;
use App\Console\Commands\CareerPlanCanonical2786PublicResolutionPartition;
use App\Console\Commands\CareerPlanCanonical80CohortReadiness;
use App\Console\Commands\CareerPlanCanonical80RuntimeCandidatePool;
use App\Console\Commands\CareerPlanCanonical80TargetDelta;
use App\Console\Commands\CareerPlanCanonicalDeltaRolloutGate;
use App\Console\Commands\CareerPlanCanonicalProgressiveCohortDelta;
use App\Console\Commands\CareerPlanCanonicalProgressiveLiveVerification;
use App\Console\Commands\CareerPlanCanonicalProgressiveReadinessSelection;
use App\Console\Commands\CareerPlanCanonicalRolloutBatchTransition;
use App\Console\Commands\CareerPlanCanonicalRuntimeArtifactRefresh;
use App\Console\Commands\CareerPlanCanonicalRuntimeCandidatePrep;
use App\Console\Commands\CareerPlanCnProxyVisibleDetailPolicyAuthority;
use App\Console\Commands\CareerPrepareCanonicalRuntimeCandidates;
use App\Console\Commands\CareerReleaseGateNoindexCleanup;
use App\Console\Commands\CareerRemediateCanonicalIndexState;
use App\Console\Commands\CareerRunAssetBatch;
use App\Console\Commands\CareerSyncOccupationDirectoryDisplay;
use App\Console\Commands\CareerValidateAssetImport;
use App\Console\Commands\CareerValidateBroadGroupFamilyMap;
use App\Console\Commands\CareerValidateCanonical80TotalLiveAcceptance;
use App\Console\Commands\CareerValidateCanonicalBatchLiveAcceptance;
use App\Console\Commands\CareerValidateCanonicalPostPromotionReleaseGate;
use App\Console\Commands\CareerValidateCanonicalProgressiveLiveAcceptance;
use App\Console\Commands\CareerValidateCanonicalRolloutGovernance;
use App\Console\Commands\CareerValidateCanonicalRuntimeTruth;
use App\Console\Commands\CareerValidateCnAuthorityPolicy;
use App\Console\Commands\CareerValidateCnProxyPublicOwner;
use App\Console\Commands\CareerValidateCnTrustManifest;
use App\Console\Commands\CareerValidateDisplayAssetLineage;
use App\Console\Commands\CareerValidateDisplayBatch;
use App\Console\Commands\CareerValidateOccupationDirectoryReviewQueues;
use App\Console\Commands\CareerValidatePublicNonindexReference;
use App\Console\Commands\CareerValidateReleaseGate;
use App\Console\Commands\CareerValidateRuntimePublishProjection;
use App\Console\Commands\CareerValidateSitemapLlmsPublicTypeMatrix;
use App\Console\Commands\CareerWarmPublicAuthorityCache;
use App\Console\Commands\CiScaleImpact;
use App\Console\Commands\CommerceCompensatePendingOrders;
use App\Console\Commands\CommerceReconcile;
use App\Console\Commands\CommerceRepairPaidOrders;
use App\Console\Commands\CommerceRepairPostCommitFailed;
use App\Console\Commands\ContentCompile;
use App\Console\Commands\ContentLint;
use App\Console\Commands\ContentReleaseRevalidate;
use App\Console\Commands\EnneagramActivateInactiveCandidateRelease;
use App\Console\Commands\EnneagramActivateRegistryRelease;
use App\Console\Commands\EnneagramExportProductionEquivalentCandidatePayloads;
use App\Console\Commands\EnneagramImportInactiveCandidateRelease;
use App\Console\Commands\EnneagramResultPageAgentReadinessCommand;
use App\Console\Commands\EnneagramResultPageCandidateStagingHarnessCommand;
use App\Console\Commands\EnneagramResultPageContentBatchCommand;
use App\Console\Commands\EnneagramResultPageOpsControlPlaneCommand;
use App\Console\Commands\EnneagramResultPageOpsRunnerCommand;
use App\Console\Commands\EnneagramResultPageProductionManualGateCommand;
use App\Console\Commands\EnneagramResultPageRenderedQaSmokeHarnessCommand;
use App\Console\Commands\EnneagramResultPageReportSidecarIssueCommand;
use App\Console\Commands\EnneagramRollbackInactiveCandidateRelease;
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
use App\Console\Commands\MediaAssetsImportSeoImageBundle;
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
use App\Console\Commands\NormsIqImport;
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
use App\Console\Commands\PersonalityAgentApprovalQueueCommand;
use App\Console\Commands\PersonalityAgentApprovalQueueReviewCommand;
use App\Console\Commands\PersonalityBigFivePublicProfileAgentDraft;
use App\Console\Commands\PersonalityEnneagramCmsDraft;
use App\Console\Commands\PersonalityImportDesktopCloneBaseline;
use App\Console\Commands\PersonalityMbti64GscQueryReadonlyExport;
use App\Console\Commands\PersonalityMbti64BackendImportContract;
use App\Console\Commands\PersonalityMbti64CmsInternalLinkDraft;
use App\Console\Commands\PersonalityMbti64CmsProjectionDraft;
use App\Console\Commands\PersonalityMbti64CmsRevisionDraft;
use App\Console\Commands\PersonalityMbti64CmsRevisionPromote;
use App\Console\Commands\PersonalityTdkNextBatchApprovalDraftGateCommand;
use App\Console\Commands\PersonalityTdkRuntimePromotionSearchGateReadinessCommand;
use App\Console\Commands\PersonalityEnneagramCmsPromote;
use App\Console\Commands\QualityDailySummary;
use App\Console\Commands\RefreshCareerAttributionDailyCommand;
use App\Console\Commands\RiasecResultPageAssetAgentAuditCommand;
use App\Console\Commands\RiasecResultPageV2ProductionImportCommand;
use App\Console\Commands\SdsPsychometricsReport;
use App\Console\Commands\SeedScaleRegistry;
use App\Console\Commands\SeoAgentArticleCmsPublishCanaryCommand;
use App\Console\Commands\SeoAgentArticleDraftClaimRiskQaCommand;
use App\Console\Commands\SeoAgentArticleDraftPreviewRuntimeQaCommand;
use App\Console\Commands\SeoAgentArticlePostPublishPropagationDryRunCommand;
use App\Console\Commands\SeoAgentAutoRollbackGuardCommand;
use App\Console\Commands\SeoAgentCmsDraftPackageDryRunCommand;
use App\Console\Commands\SeoAgentCmsDraftPayloadRepairCanaryCommand;
use App\Console\Commands\SeoAgentCmsDraftReadbackQaCommand;
use App\Console\Commands\SeoAgentCmsDraftWriteCommand;
use App\Console\Commands\SeoAgentCmsFaqGapScanCommand;
use App\Console\Commands\SeoAgentCmsPublishAutoCanaryCommand;
use App\Console\Commands\SeoAgentCmsPublishCanaryCommand;
use App\Console\Commands\SeoAgentCmsTdkGapScanCommand;
use App\Console\Commands\SeoAgentCodexReviewRunnerCommand;
use App\Console\Commands\SeoAgentGscBatchDraftQaSupportCommand;
use App\Console\Commands\SeoAgentGscCohortHandoffCommand;
use App\Console\Commands\SeoAgentGscDraftPublishGateReadinessCommand;
use App\Console\Commands\SeoAgentGscOpportunityAutoDraftCommand;
use App\Console\Commands\SeoAgentGscPostPublishFeedbackCommand;
use App\Console\Commands\SeoAgentGscRemainingCandidateBatchPlanCommand;
use App\Console\Commands\SeoAgentL5aContentPagePublishCanaryCommand;
use App\Console\Commands\SeoAgentL5aIndexnowSubmitCanaryCommand;
use App\Console\Commands\SeoAgentOpportunityAggregateCommand;
use App\Console\Commands\SeoAgentPostPublishIndexnowAutoCommand;
use App\Console\Commands\SeoAgentPostPublishSearchSubmitCommand;
use App\Console\Commands\SeoAgentPriorityQueueSchedulerCommand;
use App\Console\Commands\SeoAgentRunCommand;
use App\Console\Commands\SeoAgentRuntimeSeoQaScanCommand;
use App\Console\Commands\SeoAgentWeeklyDraftWriteAutoCommand;
use App\Console\Commands\SeoAgentWeeklyReadonlyRunnerCommand;
use App\Console\Commands\SeoOpsP0CtrArticleCmsUpdateWriterCommand;
use App\Console\Commands\SeoOpsGaokaoV5CmsDraftGateCommand;
use App\Console\Commands\SeoOpsGaokaoV5PropagationGateReadinessCommand;
use App\Console\Commands\SeoOpsGaokaoV5PublishGateRepairCommand;
use App\Console\Commands\SeoOpsZhArticleQualityControlledWriterCommand;
use App\Console\Commands\SeoOpsZhArticleQualityRepairDryRunCommand;
use App\Console\Commands\SeoOpsZhArticleQualityReadbackCommand;
use App\Console\Commands\SeoIntelSearchChannelQueueCommand;
use App\Console\Commands\SeoIntelUrlTruthHandoffCommand;
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
        PersonalityAgentApprovalQueueCommand::class,
        PersonalityAgentApprovalQueueReviewCommand::class,
        PersonalityBigFivePublicProfileAgentDraft::class,
        PersonalityEnneagramCmsPromote::class,
        PersonalityEnneagramCmsDraft::class,
        PersonalityImportDesktopCloneBaseline::class,
        PersonalityMbti64GscQueryReadonlyExport::class,
        PersonalityMbti64CmsInternalLinkDraft::class,
        PersonalityMbti64CmsProjectionDraft::class,
        PersonalityMbti64CmsRevisionDraft::class,
        PersonalityMbti64CmsRevisionPromote::class,
        PersonalityTdkNextBatchApprovalDraftGateCommand::class,
        PersonalityTdkRuntimePromotionSearchGateReadinessCommand::class,
        PersonalityMbti64BackendImportContract::class,
        MetricsWeeklyValidity::class,
        MbtiPrewarm::class,
        MbtiUpgradeLegacyPartialUnlocks::class,
        AdminBootstrapOwner::class,
        OpsDeployEvent::class,
        OpsHealthzSnapshot::class,
        ArchiveColdData::class,
        Batch2ResultPageDryRunGateCommand::class,
        Batch2ResultPageReadbackReviewLedgerCommand::class,
        PaymentsPruneEvents::class,
        SeedScaleRegistry::class,
        SyncScaleSlugs::class,
        ContentLint::class,
        ContentCompile::class,
        EnneagramExportProductionEquivalentCandidatePayloads::class,
        EnneagramActivateInactiveCandidateRelease::class,
        EnneagramActivateRegistryRelease::class,
        EnneagramImportInactiveCandidateRelease::class,
        EnneagramResultPageContentBatchCommand::class,
        EnneagramResultPageAgentReadinessCommand::class,
        EnneagramResultPageCandidateStagingHarnessCommand::class,
        EnneagramResultPageOpsControlPlaneCommand::class,
        EnneagramResultPageOpsRunnerCommand::class,
        EnneagramResultPageProductionManualGateCommand::class,
        EnneagramResultPageReportSidecarIssueCommand::class,
        EnneagramResultPageRenderedQaSmokeHarnessCommand::class,
        EnneagramRollbackInactiveCandidateRelease::class,
        EnneagramRollbackRegistryRelease::class,
        BigFiveExportProductionEquivalentCandidatePayloads::class,
        BigFiveImportInactiveCandidateRelease::class,
        NormsImport::class,
        NormsIqImport::class,
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
        BigFiveResultPageV2AssetAgentAuditCommand::class,
        RiasecResultPageAssetAgentAuditCommand::class,
        RiasecResultPageV2ProductionImportCommand::class,
        CommerceReconcile::class,
        CommerceCompensatePendingOrders::class,
        CommerceRepairPaidOrders::class,
        CommerceRepairPostCommitFailed::class,
        CareerApplyOccupationDirectoryReviewDecisions::class,
        CareerAuditCanonicalEligibility::class,
        CareerAuditDetailReady1048Candidates::class,
        CareerAuditZhDisplayParity::class,
        CareerCloseoutCanonicalProgressiveCohort::class,
        CareerAlignActorsAuthorityOccupation::class,
        CareerAlignCareerAuthorityBatch::class,
        CareerAlignD8AuthorityCrosswalks::class,
        CareerAlignSelectedAuthorityCrosswalks::class,
        CareerAlignSelectedOnetCrosswalks::class,
        CareerImportActorsDisplayAsset::class,
        CareerImportAuthorityWave::class,
        CareerImportDetailReadyReplacementAuthority::class,
        CareerImportDetailReadyReplacementAuthoritySource::class,
        CareerImportOccupationDirectoryDrafts::class,
        CareerImportOccupationDirectoryDryRun::class,
        CareerImportSelectedDisplayAssets::class,
        CareerNormalizeLegacyDisplayAssets::class,
        CareerPlanCanonical80CohortReadiness::class,
        CareerPlanCanonical80RuntimeCandidatePool::class,
        CareerPlanCanonical80TargetDelta::class,
        CareerPlanCanonical2786PublicResolutionPartition::class,
        CareerPlanCanonicalDeltaRolloutGate::class,
        CareerPlanCanonicalProgressiveCohortDelta::class,
        CareerPlanCanonicalProgressiveLiveVerification::class,
        CareerPlanCanonicalProgressiveReadinessSelection::class,
        CareerPlanCanonicalRolloutBatchTransition::class,
        CareerPlanCanonicalRuntimeArtifactRefresh::class,
        CareerPlanCanonicalRuntimeCandidatePrep::class,
        CareerPlanCnProxyVisibleDetailPolicyAuthority::class,
        CareerPrepareCanonicalRuntimeCandidates::class,
        CareerReleaseGateNoindexCleanup::class,
        CareerRemediateCanonicalIndexState::class,
        CareerValidateAssetImport::class,
        CareerValidateBroadGroupFamilyMap::class,
        CareerValidateCanonicalRuntimeTruth::class,
        CareerValidateCanonicalBatchLiveAcceptance::class,
        CareerValidateCanonical80TotalLiveAcceptance::class,
        CareerValidateCanonicalProgressiveLiveAcceptance::class,
        CareerValidateCanonicalPostPromotionReleaseGate::class,
        CareerValidateCanonicalRolloutGovernance::class,
        CareerValidateCnAuthorityPolicy::class,
        CareerValidateCnProxyPublicOwner::class,
        CareerValidateCnTrustManifest::class,
        CareerValidateDisplayBatch::class,
        CareerValidateDisplayAssetLineage::class,
        CareerValidatePublicNonindexReference::class,
        CareerValidateReleaseGate::class,
        CareerValidateRuntimePublishProjection::class,
        CareerValidateSitemapLlmsPublicTypeMatrix::class,
        CareerFullDisplayWorkbookValidator::class,
        CareerCompileAuthorityWave::class,
        CareerCompileRecommendationSubjects::class,
        CareerCrosswalkOps::class,
        CareerGenerateCanonicalDeltaRolloutManifest::class,
        CareerGenerateCanonicalExpansionManifestTrain::class,
        CareerGenerateCanonicalProgressiveRolloutManifest::class,
        CareerExportCanonicalExpansionManifest::class,
        CareerExportCanonicalEligibilityDbContext::class,
        CareerExportCanonicalEligibilitySurfaceContext::class,
        CareerExportCanonicalRuntimeTruth::class,
        CareerExportCrosswalkBacklogConvergence::class,
        CareerExportFullReleaseLedger::class,
        CareerExportRuntimePublishProjection::class,
        CareerExportLaunchGovernanceClosure::class,
        CareerExportOccupationDirectoryReviewQueues::class,
        CareerExportPublicTrustTaxonomy::class,
        CareerExportStrongIndexEligibility::class,
        CareerFinalizeCanonicalRuntimeTruth::class,
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
        ArticleImportEditorialPackage::class,
        ArticleImportSeoContentPackageDraft::class,
        ArticleEnsureSeoMetaBaseline::class,
        ArticleUpdateExistingSeoContentPackage::class,
        ArticleCoverPropagationSmoke::class,
        ArticleDiscoverabilityRelease::class,
        ArticlePublishControlled::class,
        ArticleReplaceInlineImageUrl::class,
        ArticleReleaseCloseout::class,
        ArticleSeoGateRollout::class,
        ArticleTaxonomyHygiene::class,
        ArticleUpdateTranslationGroupId::class,
        ArticleUpdateImageMetadata::class,
        ArticleWeeklySeoObservationExport::class,
        MediaAssetsImportSeoImageBundle::class,
        SeoAgentCmsFaqGapScanCommand::class,
        SeoAgentAutoRollbackGuardCommand::class,
        SeoAgentArticleCmsPublishCanaryCommand::class,
        SeoAgentArticlePostPublishPropagationDryRunCommand::class,
        SeoAgentArticleDraftClaimRiskQaCommand::class,
        SeoAgentArticleDraftPreviewRuntimeQaCommand::class,
        SeoAgentCmsTdkGapScanCommand::class,
        SeoAgentCodexReviewRunnerCommand::class,
        SeoAgentCmsDraftPackageDryRunCommand::class,
        SeoAgentCmsDraftPayloadRepairCanaryCommand::class,
        SeoAgentCmsDraftReadbackQaCommand::class,
        SeoAgentCmsDraftWriteCommand::class,
        SeoAgentCmsPublishAutoCanaryCommand::class,
        SeoAgentCmsPublishCanaryCommand::class,
        SeoAgentL5aContentPagePublishCanaryCommand::class,
        SeoAgentL5aIndexnowSubmitCanaryCommand::class,
        SeoAgentGscCohortHandoffCommand::class,
        SeoAgentGscBatchDraftQaSupportCommand::class,
        SeoAgentGscOpportunityAutoDraftCommand::class,
        SeoAgentGscPostPublishFeedbackCommand::class,
        SeoAgentGscDraftPublishGateReadinessCommand::class,
        SeoAgentGscRemainingCandidateBatchPlanCommand::class,
        SeoAgentPostPublishIndexnowAutoCommand::class,
        SeoAgentPostPublishSearchSubmitCommand::class,
        SeoAgentPriorityQueueSchedulerCommand::class,
        SeoAgentRuntimeSeoQaScanCommand::class,
        SeoAgentOpportunityAggregateCommand::class,
        SeoAgentRunCommand::class,
        SeoAgentWeeklyDraftWriteAutoCommand::class,
        SeoAgentWeeklyReadonlyRunnerCommand::class,
        SeoOpsP0CtrArticleCmsUpdateWriterCommand::class,
        SeoOpsGaokaoV5CmsDraftGateCommand::class,
        SeoOpsGaokaoV5PublishGateRepairCommand::class,
        SeoOpsGaokaoV5PropagationGateReadinessCommand::class,
        SeoOpsZhArticleQualityControlledWriterCommand::class,
        SeoOpsZhArticleQualityRepairDryRunCommand::class,
        SeoOpsZhArticleQualityReadbackCommand::class,
        SeoIntelUrlTruthHandoffCommand::class,
        SeoIntelSearchChannelQueueCommand::class,
        ContentReleaseRevalidate::class,
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
        $schedule->command('commerce:compensate-pending-orders --provider=alipay --include-created --only-stale --limit=10 --older-than-minutes=60')->everyTenMinutes()->withoutOverlapping();
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
