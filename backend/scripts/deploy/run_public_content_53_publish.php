<?php

declare(strict_types=1);

use App\Services\ContentPromotion\Adapters\PublicContent53PromotionAdapter;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PublicContent53Package;
use Illuminate\Contracts\Console\Kernel;

// This exact-package driver runs only from the existing deployment workflow.
// All four phases execute in this process, under one lock; there is no child
// publisher that can outlive its lock after a transport disconnect.
umask(0077);
$root = dirname(__DIR__, 2);
$context = null;
$adapter = null;
$publicationAttempted = false;
$started = microtime(true);
try {
    $bytes = stream_get_contents(STDIN, 16385);
    if (! is_string($bytes) || strlen($bytes) > 16384) {
        throw new DomainException('public_content_53_request_invalid');
    }
    $request = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($request) || array_diff(array_keys($request), [
        'source_commit', 'workflow_run_id', 'workflow_run_attempt',
        'package_sha256', 'executor_release_sha256', 'release_policy_sha256',
        'workflow_signature', 'mode', 'environment',
    ]) !== []) {
        throw new DomainException('public_content_53_request_invalid');
    }
    foreach (['source_commit' => 40, 'package_sha256' => 64, 'executor_release_sha256' => 64, 'release_policy_sha256' => 64, 'workflow_signature' => 64] as $field => $length) {
        if (! is_string($request[$field] ?? null) || preg_match('/\A[a-f0-9]{'.$length.'}\z/', $request[$field]) !== 1) {
            throw new DomainException('public_content_53_request_invalid');
        }
    }
    if (! is_string($request['workflow_run_id'] ?? null)
        || preg_match('/\A[1-9][0-9]{0,19}\z/', $request['workflow_run_id']) !== 1
        || ($request['workflow_run_attempt'] ?? null) !== 1
        || ! in_array($request['environment'] ?? '', ['staging', 'production'], true)
        || ! in_array($request['mode'] ?? '', ['publish', 'recover'], true)) {
        throw new DomainException('public_content_53_workflow_identity_invalid');
    }
    if (trim((string) @file_get_contents(dirname($root).'/REVISION')) !== $request['source_commit']) {
        throw new DomainException('public_content_53_active_revision_mismatch');
    }
    $executorPaths = [
        'app/Console/Commands/ContentPromoteExactPackage.php',
        'app/Filament/Ops/Resources/CareerGuideResource/Support/CareerGuideWorkspace.php',
        'app/Http/Controllers/API/V0_5/Cms/ArticleController.php',
        'app/Http/Controllers/API/V0_5/Cms/CareerGuideController.php',
        'app/Http/Controllers/API/V0_5/Cms/ContentPageController.php',
        'app/Http/Controllers/API/V0_5/Cms/LandingSurfaceController.php',
        'app/Http/Controllers/API/V0_5/Cms/TopicController.php',
        'app/Models/Article.php',
        'app/Models/ArticleSeoMeta.php',
        'app/Models/ArticleTranslationRevision.php',
        'app/Models/CareerGuide.php',
        'app/Models/CareerGuideRevision.php',
        'app/Models/CareerGuideSeoMeta.php',
        'app/Models/CmsTranslationRevision.php',
        'app/Models/ContentPage.php',
        'app/Models/LandingSurface.php',
        'app/Models/TopicProfile.php',
        'app/Models/TopicProfileEntry.php',
        'app/Models/TopicProfileRevision.php',
        'app/Models/TopicProfileSection.php',
        'app/Models/TopicProfileSeoMeta.php',
        'app/Services/Cms/ArticleBodyHeadingGuard.php',
        'app/Services/Cms/ArticleSeoGateRollout.php',
        'app/Services/Cms/CareerGuideSeoService.php',
        'app/Services/Cms/CareerGuideService.php',
        'app/Services/ContentPromotion/Adapters/PublicContent53PromotionAdapter.php',
        'app/Services/ContentPromotion/ExactPackagePathGuard.php',
        'app/Services/ContentPromotion/ExactPackagePromotionService.php',
        'app/Services/ContentPromotion/PromotionAdapterRegistry.php',
        'app/Services/ContentPromotion/PromotionAdapterResultFactory.php',
        'app/Services/ContentPromotion/PromotionContext.php',
        'app/Services/ContentPromotion/PromotionContextFactory.php',
        'app/Services/ContentPromotion/PromotionExecutionContext.php',
        'app/Services/ContentPromotion/PromotionReceiptStore.php',
        'app/Services/ContentPromotion/PromotionRollbackSnapshotService.php',
        'app/Services/ContentPromotion/PromotionTargetSet.php',
        'app/Services/ContentPromotion/PublicContent53FrontendRevalidator.php',
        'app/Services/ContentPromotion/PublicContent53Package.php',
        'app/Services/ContentPromotion/PublicContent53State.php',
        'app/Services/ContentPromotion/PublicContent53Writer.php',
        'app/Services/ContentPromotion/PublicContent53MbtiResultCopy.php',
        'app/Services/Cms/MbtiEnglishVariantSectionEnrichmentService.php',
        'app/Services/Mbti/Adapters/MbtiPersonalityProfileAuthoritySourceAdapter.php',
        'app/Models/PersonalityProfile.php',
        'app/Models/PersonalityProfileVariant.php',
        'app/Models/PersonalityProfileVariantSection.php',
        'app/Models/PersonalityProfileVariantRevision.php',
        'app/Services/ContentPromotion/RiasecCodeSampleArticles.php',
        'config/content_promotion.php',
        'scripts/deploy/run_public_content_53_publish.php',
    ];
    sort($executorPaths, SORT_STRING);
    $material = '';
    foreach ($executorPaths as $path) {
        if (! is_file($root.'/'.$path) || is_link($root.'/'.$path) || (stat($root.'/'.$path)['nlink'] ?? 0) !== 1) {
            throw new DomainException('public_content_53_executor_invalid');
        }
        $material .= $path."\n".hash_file('sha256', $root.'/'.$path)."\n";
    }
    if (! hash_equals(hash('sha256', $material), $request['executor_release_sha256'])) {
        throw new DomainException('public_content_53_executor_digest_mismatch');
    }
    foreach (['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'executor_release_sha256', 'release_policy_sha256', 'workflow_signature'] as $field) {
        $name = 'CONTENT_PROMOTION_'.strtoupper($field);
        putenv($name.'='.$request[$field]);
        $_SERVER[$name] = $_ENV[$name] = (string) $request[$field];
    }
    putenv('PUBLIC_CONTENT_53_ENVIRONMENT='.$request['environment']);
    $_SERVER['PUBLIC_CONTENT_53_ENVIRONMENT'] = $_ENV['PUBLIC_CONTENT_53_ENVIRONMENT'] = $request['environment'];
    require $root.'/vendor/autoload.php';
    $expectedCount = count((new PublicContent53Package)->read($root, $request['package_sha256'])['candidates']);
    putenv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT='.$expectedCount);
    $_SERVER['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'] = $_ENV['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'] = (string) $expectedCount;
    // Previous receipts come solely from this driver's immutable phase chain.
    putenv('CONTENT_PROMOTION_PREVIOUS_RECEIPT');
    unset($_SERVER['CONTENT_PROMOTION_PREVIOUS_RECEIPT'], $_ENV['CONTENT_PROMOTION_PREVIOUS_RECEIPT']);
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    // The validated per-run environment must override the release config cache.
    config(['content_promotion.public_content_53_environment' => $request['environment']]);
    $context = $app->make(PromotionContextFactory::class)->make(
        PublicContent53Package::PACKAGE, $request['package_sha256'], 'W3', PublicContent53PromotionAdapter::SUBSCOPE,
    );
    $app->make(PublicContent53Package::class)->read($root, $context->packageSha256);
    $receiptRoot = $root.'/storage/app/content-promotion/public-content-53';
    if (is_link($receiptRoot) || (! is_dir($receiptRoot) && ! mkdir($receiptRoot, 0700, true))) {
        throw new DomainException('public_content_53_receipt_root_invalid');
    }
    $lockPath = $receiptRoot.'/execution.lock';
    if (is_link($lockPath)) {
        throw new DomainException('public_content_53_execution_lock_invalid');
    }
    $mutex = fopen($lockPath, 'c');
    if (! is_resource($mutex)) {
        throw new DomainException('public_content_53_execution_lock_failed');
    }
    $lockDeadline = microtime(true) + ($request['mode'] === 'recover' ? 180 : 0);
    while (! flock($mutex, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $lockDeadline) {
            throw new DomainException('public_content_53_execution_lock_failed');
        }
        usleep(100000);
    }
    $directory = $receiptRoot.'/'.$context->sourceCommit.'-'.$context->workflowRunId.'-1';
    if (is_link($directory) || ($request['mode'] === 'publish' && file_exists($directory))
        || (! is_dir($directory) && ! mkdir($directory, 0700))) {
        throw new DomainException('public_content_53_execution_already_claimed_or_invalid');
    }
    $adapter = $app->make(PublicContent53PromotionAdapter::class);
    if ($request['mode'] === 'recover') {
        $restored = $adapter->recoverFailedPublication($context);
        echo json_encode(['ok' => true, 'mode' => 'recover', 'restored' => $restored, 'recovery_status' => $restored ? 'restored' : 'not_required', 'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => 1, 'package_sha256' => $context->packageSha256, 'executor_release_sha256' => $context->executorReleaseSha256, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $previous = '';
    $completed = [];
    $stagingInitialized = \Illuminate\Support\Facades\DB::transaction(fn () => $app->make(\App\Services\ContentPromotion\PublicContent53Writer::class)->initializeStagingBaseline($context));
    foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
        config(['content_promotion.execution.previous_receipt' => $previous]);
        if ($phase === 'publish') {
            $publicationAttempted = true;
        }
        $path = $directory.'/'.$phase.'.json';
        $result = $app->make(ExactPackagePromotionService::class)->execute($context, $phase, $path);
        if (($result['receipt']['expected_count'] ?? null) !== $expectedCount || ($result['receipt']['readback_count'] ?? null) !== $expectedCount) {
            throw new DomainException('public_content_53_phase_count_invalid');
        }
        $completed[] = ['phase' => $phase, 'receipt_sha256' => $result['receipt_sha256'], 'readback_count' => $expectedCount];
        $previous = $path;
    }
    echo json_encode([
        'schema' => 'public.content53.publish.v1', 'ok' => true,
        'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
        'workflow_run_attempt' => 1, 'package_sha256' => $context->packageSha256,
        'published_count' => $expectedCount, 'phases' => $completed,
        'staging_initialized_count' => $stagingInitialized,
        'elapsed_seconds' => round(microtime(true) - $started, 3), 'sanitized' => true,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $restored = null;
    if ($publicationAttempted && $context !== null && $adapter !== null) {
        try {
            $restored = $adapter->recoverFailedPublication($context);
        } catch (Throwable) {
            $restored = false;
        }
    }
    echo json_encode(['ok' => false, 'error_code' => ($error instanceof DomainException && preg_match('/\Apublic_content_53_[a-z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'public_content_53_publication_failed'), 'recovery_completed' => $restored, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
