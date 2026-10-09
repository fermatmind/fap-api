<?php

declare(strict_types=1);

use App\Services\ContentPromotion\Adapters\IqEqTopicPromotionAdapter;
use App\Services\ContentPromotion\ExactPackagePromotionService;
use App\Services\ContentPromotion\IqEqTopicPackage;
use App\Services\ContentPromotion\PromotionContextFactory;
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
        throw new DomainException('iq_eq_topic_request_invalid');
    }
    $request = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($request) || array_diff(array_keys($request), [
        'source_commit', 'workflow_run_id', 'workflow_run_attempt',
        'package_sha256', 'executor_release_sha256', 'release_policy_sha256',
        'workflow_signature', 'mode',
    ]) !== []) {
        throw new DomainException('iq_eq_topic_request_invalid');
    }
    foreach (['source_commit' => 40, 'package_sha256' => 64, 'executor_release_sha256' => 64, 'release_policy_sha256' => 64, 'workflow_signature' => 64] as $field => $length) {
        if (! is_string($request[$field] ?? null) || preg_match('/\A[a-f0-9]{'.$length.'}\z/', $request[$field]) !== 1) {
            throw new DomainException('iq_eq_topic_request_invalid');
        }
    }
    if (! is_string($request['workflow_run_id'] ?? null)
        || preg_match('/\A[1-9][0-9]{0,19}\z/', $request['workflow_run_id']) !== 1
        || ($request['workflow_run_attempt'] ?? null) !== 1
        || ! in_array($request['mode'] ?? '', ['publish', 'recover'], true)) {
        throw new DomainException('iq_eq_topic_workflow_identity_invalid');
    }
    if (trim((string) @file_get_contents(dirname($root).'/REVISION')) !== $request['source_commit']) {
        throw new DomainException('iq_eq_topic_active_revision_mismatch');
    }
    $executorPaths = [
        'app/Console/Commands/ContentPromoteExactPackage.php',
        'app/Http/Controllers/API/V0_5/Cms/TopicController.php',
        'app/Models/Article.php',
        'app/Models/ArticleSeoMeta.php',
        'app/Models/ArticleTranslationRevision.php',
        'app/Models/TopicProfile.php',
        'app/Models/TopicProfileEntry.php',
        'app/Models/TopicProfileRevision.php',
        'app/Models/TopicProfileSection.php',
        'app/Models/TopicProfileSeoMeta.php',
        'app/Services/Cms/ArticleBodyHeadingGuard.php',
        'app/Services/Cms/IqEqTopicFaqProjection.php',
        'app/Services/Cms/TopicEntryResolverService.php',
        'app/Services/Cms/TopicProfileSeoService.php',
        'app/Services/Cms/TopicProfileService.php',
        'app/Services/ContentPromotion/Adapters/IqEqTopicPromotionAdapter.php',
        'app/Services/ContentPromotion/ExactPackagePathGuard.php',
        'app/Services/ContentPromotion/ExactPackagePromotionService.php',
        'app/Services/ContentPromotion/IqEqTopicPackage.php',
        'app/Services/ContentPromotion/IqEqTopicPrerequisites.php',
        'app/Services/ContentPromotion/IqEqTopicProjection.php',
        'app/Services/ContentPromotion/IqPublicArticlePackage.php',
        'app/Services/ContentPromotion/IqPublicEntryPackage.php',
        'app/Services/ContentPromotion/PromotionAdapterRegistry.php',
        'app/Services/ContentPromotion/PromotionAdapterResultFactory.php',
        'app/Services/ContentPromotion/PromotionContext.php',
        'app/Services/ContentPromotion/PromotionContextFactory.php',
        'app/Services/ContentPromotion/PromotionExecutionContext.php',
        'app/Services/ContentPromotion/PromotionReceiptStore.php',
        'app/Services/ContentPromotion/PromotionRollbackSnapshotService.php',
        'app/Services/ContentPromotion/PromotionTargetSet.php',
        'app/Services/Scale/ScaleRegistry.php',
        'app/Support/ControlledReceiptWriter.php',
        'config/content_promotion.php',
        'scripts/deploy/run_iq_eq_topic_publish.php',
    ];
    sort($executorPaths, SORT_STRING);
    $material = '';
    foreach ($executorPaths as $path) {
        if (! is_file($root.'/'.$path) || is_link($root.'/'.$path) || (stat($root.'/'.$path)['nlink'] ?? 0) !== 1) {
            throw new DomainException('iq_eq_topic_executor_invalid');
        }
        $material .= $path."\n".hash_file('sha256', $root.'/'.$path)."\n";
    }
    if (! hash_equals(hash('sha256', $material), $request['executor_release_sha256'])) {
        throw new DomainException('iq_eq_topic_executor_digest_mismatch');
    }
    foreach (['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'executor_release_sha256', 'release_policy_sha256', 'workflow_signature'] as $field) {
        $name = 'CONTENT_PROMOTION_'.strtoupper($field);
        putenv($name.'='.$request[$field]);
        $_SERVER[$name] = $_ENV[$name] = (string) $request[$field];
    }
    putenv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT=2');
    $_SERVER['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'] = $_ENV['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'] = '2';
    // Previous receipts come solely from this driver's immutable phase chain.
    putenv('CONTENT_PROMOTION_PREVIOUS_RECEIPT');
    unset($_SERVER['CONTENT_PROMOTION_PREVIOUS_RECEIPT'], $_ENV['CONTENT_PROMOTION_PREVIOUS_RECEIPT']);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $context = $app->make(PromotionContextFactory::class)->make(
        IqEqTopicPackage::PACKAGE, $request['package_sha256'], 'W3', IqEqTopicPromotionAdapter::SUBSCOPE,
    );
    $app->make(IqEqTopicPackage::class)->read($root, $context->packageSha256);
    $receiptRoot = $root.'/storage/app/content-promotion/iq-eq-topic';
    if (is_link($receiptRoot) || (! is_dir($receiptRoot) && ! mkdir($receiptRoot, 0700, true))) {
        throw new DomainException('iq_eq_topic_receipt_root_invalid');
    }
    $lockPath = $receiptRoot.'/execution.lock';
    if (is_link($lockPath)) {
        throw new DomainException('iq_eq_topic_execution_lock_invalid');
    }
    $mutex = fopen($lockPath, 'c');
    if (! is_resource($mutex)) {
        throw new DomainException('iq_eq_topic_execution_lock_failed');
    }
    $lockDeadline = microtime(true) + ($request['mode'] === 'recover' ? 180 : 0);
    while (! flock($mutex, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $lockDeadline) {
            throw new DomainException('iq_eq_topic_execution_lock_failed');
        }
        usleep(100000);
    }
    $directory = $receiptRoot.'/'.$context->sourceCommit.'-'.$context->workflowRunId.'-1';
    if (is_link($directory) || ($request['mode'] === 'publish' && file_exists($directory))
        || (! is_dir($directory) && ! mkdir($directory, 0700))) {
        throw new DomainException('iq_eq_topic_execution_already_claimed_or_invalid');
    }
    $adapter = $app->make(IqEqTopicPromotionAdapter::class);
    if ($request['mode'] === 'recover') {
        $restored = $adapter->recoverFailedPublication($context);
        echo json_encode(['ok' => true, 'mode' => 'recover', 'restored' => $restored, 'recovery_status' => $restored ? 'restored' : 'not_required', 'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => 1, 'package_sha256' => $context->packageSha256, 'executor_release_sha256' => $context->executorReleaseSha256, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $previous = '';
    $completed = [];
    foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
        config(['content_promotion.execution.previous_receipt' => $previous]);
        if ($phase === 'publish') {
            $publicationAttempted = true;
        }
        $path = $directory.'/'.$phase.'.json';
        $result = $app->make(ExactPackagePromotionService::class)->execute($context, $phase, $path);
        if (($result['receipt']['expected_count'] ?? null) !== 2 || ($result['receipt']['readback_count'] ?? null) !== 2) {
            throw new DomainException('iq_eq_topic_phase_count_invalid');
        }
        $completed[] = ['phase' => $phase, 'receipt_sha256' => $result['receipt_sha256'], 'readback_count' => 2];
        $previous = $path;
    }
    echo json_encode([
        'schema' => 'iq.eq.topic.publish.v1', 'ok' => true,
        'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
        'workflow_run_attempt' => 1, 'package_sha256' => $context->packageSha256,
        'published_count' => 2, 'phases' => $completed,
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
    echo json_encode(['ok' => false, 'error_code' => 'iq_eq_topic_publication_failed', 'recovery_completed' => $restored, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
