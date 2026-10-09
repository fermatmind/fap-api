<?php

declare(strict_types=1);

// Called only by the existing exact-SHA deployment workflow. No editorial
// payload, credential or remote path is emitted to workflow logs.
use App\Services\ContentPromotion\Adapters\EqNewSourceArticlePromotionAdapter;
use App\Services\ContentPromotion\EqPublicArticlePackage;
use App\Services\ContentPromotion\EqSourceExecutionMutex;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Contracts\Console\Kernel;

umask(0077);
$root = dirname(__DIR__, 2);
$context = null;
$adapter = null;
$completed = [];
$publicationAttempted = false;
$started = microtime(true);
try {
    $requestBytes = stream_get_contents(STDIN, 16385);
    if (! is_string($requestBytes) || strlen($requestBytes) > 16384) {
        throw new DomainException('eq_source_request_invalid');
    }
    $request = json_decode($requestBytes, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($request) || array_diff(array_keys($request), [
        'source_commit', 'workflow_run_id', 'workflow_run_attempt',
        'package_sha256', 'executor_release_sha256', 'release_policy_sha256',
        'workflow_signature', 'mode',
    ]) !== []) {
        throw new DomainException('eq_source_request_invalid');
    }
    foreach (['source_commit' => 40, 'package_sha256' => 64, 'executor_release_sha256' => 64, 'release_policy_sha256' => 64, 'workflow_signature' => 64] as $field => $length) {
        if (! is_string($request[$field] ?? null) || preg_match('/\A[a-f0-9]{'.$length.'}\z/', $request[$field]) !== 1) {
            throw new DomainException('eq_source_request_invalid');
        }
    }
    if (! is_string($request['workflow_run_id'] ?? null)
        || preg_match('/\A[1-9][0-9]{0,19}\z/', $request['workflow_run_id']) !== 1
        || ($request['workflow_run_attempt'] ?? null) !== 1
        || ! in_array($request['mode'] ?? '', ['publish', 'recover'], true)) {
        throw new DomainException('eq_source_workflow_identity_invalid');
    }
    $revision = @file_get_contents(dirname($root).'/REVISION');
    if (! is_string($revision) || trim($revision) !== $request['source_commit']) {
        throw new DomainException('eq_source_active_revision_mismatch');
    }
    $executorPaths = [
        'app/Console/Commands/ContentPromoteExactPackage.php',
        'app/Services/ContentPromotion/Adapters/EqNewSourceArticlePromotionAdapter.php',
        'app/Services/ContentPromotion/EqPublicArticlePackage.php',
        'app/Services/ContentPromotion/EqSourceExecutionMutex.php',
        'app/Services/ContentPromotion/ExactPackagePromotionService.php',
        'app/Services/ContentPromotion/PromotionContextFactory.php',
        'scripts/deploy/run_eq_new_source_article_publish.php',
    ];
    sort($executorPaths, SORT_STRING);
    $executorMaterial = '';
    foreach ($executorPaths as $path) {
        $file = $root.'/'.$path;
        if (is_link($file) || ! is_file($file)) {
            throw new DomainException('eq_source_executor_invalid');
        }
        $executorMaterial .= $path."\n".hash_file('sha256', $file)."\n";
    }
    if (! hash_equals(hash('sha256', $executorMaterial), $request['executor_release_sha256'])) {
        throw new DomainException('eq_source_executor_digest_mismatch');
    }
    foreach (['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'executor_release_sha256', 'release_policy_sha256', 'workflow_signature'] as $field) {
        $name = 'CONTENT_PROMOTION_'.strtoupper($field);
        putenv($name.'='.$request[$field]);
        $_SERVER[$name] = (string) $request[$field];
        $_ENV[$name] = (string) $request[$field];
    }
    putenv('CONTENT_PROMOTION_EXPECTED_ROW_COUNT=3');
    $_SERVER['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'] = $_ENV['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'] = '3';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $context = $app->make(PromotionContextFactory::class)->make(
        package: EqPublicArticlePackage::PACKAGE,
        packageSha256: $request['package_sha256'], lane: 'W3',
        subscope: EqNewSourceArticlePromotionAdapter::SUBSCOPE,
    );
    $adapter = $app->make(EqNewSourceArticlePromotionAdapter::class);
    $app->make(EqPublicArticlePackage::class)->read($root, $context->packageSha256);
    $receiptRoot = $root.'/storage/app/content-promotion/eq-new-source';
    $execution = $context->sourceCommit.'-'.$context->workflowRunId.'-1';
    $mutex = EqSourceExecutionMutex::acquire($receiptRoot, $execution);
    // Recovery reserves the same one-shot execution directory even when no
    // phase has started, so a disconnected, delayed publisher cannot follow it.
    $directory = EqSourceExecutionMutex::claimExecution($receiptRoot, $execution, $request['mode'] === 'recover');
    if ($request['mode'] === 'recover') {
        $restored = $adapter->recoverFailedPublication($context);
        echo json_encode(['ok' => true, 'mode' => 'recover', 'restored' => $restored, 'recovery_status' => $restored ? 'restored' : 'not_required', 'source_commit' => $context->sourceCommit], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    $previous = '';
    $environment = getenv();
    foreach (['preflight' => 'content_promotion_preflight_receipt', 'draft-import' => 'cms_draft_import_receipt', 'publish' => 'cms_publication_receipt', 'live-qa' => 'cms_live_qa_receipt'] as $phase => $kind) {
        $receiptPath = $directory.'/'.$phase.'.json';
        if ($phase === 'publish') {
            $publicationAttempted = true;
        }
        $environment['CONTENT_PROMOTION_PREVIOUS_RECEIPT'] = $previous;
        $process = proc_open([
            PHP_BINARY, $root.'/artisan', 'content:promote-exact-package',
            '--package='.EqPublicArticlePackage::PACKAGE,
            '--expected-package-sha256='.$context->packageSha256,
            '--lane=W3', '--subscope='.EqNewSourceArticlePromotionAdapter::SUBSCOPE,
            '--phase='.$phase, '--receipt='.$receiptPath, '--json',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => $mutex], $pipes, $root, $environment);
        if (! is_resource($process)) {
            throw new DomainException('eq_source_phase_start_failed');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $phaseStart = microtime(true);
        do {
            $stdout .= stream_get_contents($pipes[1]);
            // Drain stderr privately; errors are reported through sanitized JSON.
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (strlen($stdout) > 65536 || microtime(true) - $phaseStart > 180) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new DomainException('eq_source_phase_timeout_or_output_limit');
            }
            if ($status['running']) {
                usleep(10000);
            }
        } while ($status['running']);
        $stdout .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        $output = json_decode(trim($stdout), true, 32, JSON_THROW_ON_ERROR);
        if ($exit !== 0 || ($output['ok'] ?? null) !== true || ($output['receipt_kind'] ?? null) !== $kind) {
            throw new DomainException('eq_source_phase_failed');
        }
        $bytes = file_get_contents($receiptPath);
        $receipt = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (! hash_equals(hash('sha256', $bytes), (string) ($output['receipt_sha256'] ?? ''))
            || ($receipt['receipt_kind'] ?? null) !== $kind
            || ($receipt['source_commit'] ?? null) !== $context->sourceCommit
            || ($receipt['workflow_run_id'] ?? null) !== $context->workflowRunId
            || ($receipt['workflow_run_attempt'] ?? null) !== 1
            || ($receipt['package_sha256'] ?? null) !== $context->packageSha256
            || ($receipt['readback_count'] ?? null) !== 3
            || ($receipt['expected_count'] ?? null) !== 3
            || ($receipt['result'] ?? null) !== 'SUCCEEDED') {
            throw new DomainException('eq_source_phase_receipt_invalid');
        }
        $completed[] = ['phase' => $phase, 'receipt_sha256' => hash('sha256', $bytes), 'readback_count' => 3];
        $previous = $receiptPath;
    }
    echo json_encode([
        'schema' => 'eq.new_source_articles.publish.v1', 'ok' => true,
        'source_commit' => $context->sourceCommit, 'workflow_run_id' => $context->workflowRunId,
        'workflow_run_attempt' => 1, 'package_sha256' => $context->packageSha256,
        'published_count' => 3, 'phases' => $completed,
        'elapsed_seconds' => round(microtime(true) - $started, 3), 'sanitized' => true,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $recovered = null;
    if ($publicationAttempted && $context !== null && $adapter !== null) {
        try {
            $recovered = $adapter->recoverFailedPublication($context);
        } catch (Throwable) {
            $recovered = false;
        }
    }
    $code = $error->getMessage();
    if (preg_match('/\A[a-z0-9_]{1,96}\z/', $code) !== 1) {
        $code = 'eq_source_execution_failed';
    }
    echo json_encode(['ok' => false, 'error_code' => $code, 'recovery_completed' => $recovered, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
