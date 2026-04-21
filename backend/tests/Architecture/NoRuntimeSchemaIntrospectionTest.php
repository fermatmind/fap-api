<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class NoRuntimeSchemaIntrospectionTest extends TestCase
{
    /**
     * Existing compatibility and ops surfaces that predate this gate. New runtime
     * schema probing outside this baseline still fails the architecture test.
     *
     * @var list<string>
     */
    private const BASELINE_ALLOWED_FILES = [
        'app/Filament/Tenant/Resources/OrderResource.php',
        'app/Internal/Commerce/PaymentWebhookHandlerCore.php',
        'app/Jobs/Ops/BackfillPiiEncryptionJob.php',
        'app/Models/EmailSubscriber.php',
        'app/Services/Assessments/AssessmentService.php',
        'app/Services/Commerce/OrderManager.php',
        'app/Services/Content/ContentPathAliasResolver.php',
        'app/Services/Content/Publisher/ContentPackPublisher.php',
        'app/Services/Email/EmailLifecycleRolloutService.php',
        'app/Services/Experiments/ExperimentGovernanceService.php',
        'app/Services/Ops/AttemptChainAuditService.php',
        'app/Services/Ops/AttemptSubmissionRecoveryService.php',
        'app/Services/Ops/QueueBacklogProbeService.php',
        'app/Services/Partners/PartnerApiService.php',
        'app/Services/Psychometrics/Eq60/NormGroupResolver.php',
        'app/Services/Psychometrics/Sds/NormGroupResolver.php',
        'app/Services/Scale/ScaleIdentityResolver.php',
        'app/Services/Scale/ScaleRegistry.php',
        'app/Services/Scale/ScaleRegistryWriter.php',
        'app/Services/Storage/ArtifactLedgerBackfillService.php',
        'app/Services/Storage/AttemptReceiptBackfillService.php',
        'app/Services/Storage/MaterializedCacheJanitorService.php',
        'app/Services/Storage/OffloadLocalCopyShrinkService.php',
        'app/Services/Storage/ReportArtifactsArchiveService.php',
        'app/Services/Storage/ReportArtifactsRehydrateService.php',
        'app/Services/Storage/ReportArtifactsShrinkService.php',
        'app/Services/Storage/RuntimeTempJanitorService.php',
        'app/Services/Storage/StorageControlPlaneArtifactsJanitorService.php',
        'app/Services/Storage/StorageControlPlaneRefreshService.php',
        'app/Services/Storage/StorageControlPlaneSnapshotService.php',
        'app/Services/Storage/StorageControlPlaneStatusService.php',
        'app/Services/Storage/UnifiedAccessProjectionBackfillService.php',
        'app/Support/SchemaBaseline.php',
        'app/Support/WritesEvents.php',
    ];

    #[Test]
    public function app_runtime_code_does_not_use_schema_has_table_or_column(): void
    {
        $offenders = [];

        foreach ($this->appPhpFiles() as $filePath) {
            $relative = ltrim(str_replace(base_path().DIRECTORY_SEPARATOR, '', $filePath), DIRECTORY_SEPARATOR);
            if (str_starts_with($relative, 'app/Console/Commands/')) {
                continue;
            }
            if (str_starts_with($relative, 'app/Services/SelfCheck/')) {
                continue;
            }
            if (in_array($relative, self::BASELINE_ALLOWED_FILES, true)) {
                continue;
            }

            $source = (string) file_get_contents($filePath);
            if (str_contains($source, 'Schema::hasTable') || str_contains($source, 'Schema::hasColumn')) {
                $offenders[] = $relative;
            }
        }

        if ($offenders !== []) {
            sort($offenders);
            self::fail("Runtime schema introspection is forbidden:\n".implode("\n", $offenders));
        }

        self::assertTrue(true);
    }

    /**
     * @return array<int, string>
     */
    private function appPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
