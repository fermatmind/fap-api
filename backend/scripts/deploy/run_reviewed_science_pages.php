<?php

declare(strict_types=1);

use App\Services\ContentPages\ReviewedSciencePageDelivery;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Contracts\Console\Kernel;

umask(0077);
$root = dirname(__DIR__, 2);
$binding = null;
$delivery = null;
try {
    $bytes = stream_get_contents(STDIN, 4097);
    $request = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
    if (strlen($bytes) > 4096 || ! is_array($request) || array_keys($request) !== ['binding', 'signature', 'mode']
        || ! is_array($request['binding']) || ! in_array($request['mode'], ['publish', 'recover'], true)) {
        throw new DomainException('science_request_invalid');
    }
    $binding = $request['binding'];
    if (trim((string) @file_get_contents(dirname($root).'/REVISION')) !== ($binding['source_commit'] ?? null)) {
        throw new DomainException('science_active_revision_mismatch');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $key = (string) config('content_promotion.workflow_identity_key');
    if (strlen($key) < 32 || ! is_string($request['signature'])
        || ! hash_equals(hash_hmac('sha256', 'reviewed-science-pages-v1|'.PromotionContextFactory::canonicalJson($binding), $key), $request['signature'])) {
        throw new DomainException('science_workflow_identity_invalid');
    }
    $path = $root.'/'.ReviewedSciencePageDelivery::PACKAGE;
    if (! is_file($path) || is_link($path) || ! hash_equals(ReviewedSciencePageDelivery::SHA, hash_file('sha256', $path))) {
        throw new DomainException('science_candidate_hash_invalid');
    }
    $lockPath = $root.'/storage/app/reviewed-science-pages.lock';
    if (is_link($lockPath)) {
        throw new DomainException('science_lock_invalid');
    }
    $lock = fopen($lockPath, 'c');
    $deadline = microtime(true) + ($request['mode'] === 'recover' ? 120 : 0);
    while (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $deadline) {
            throw new DomainException('science_execution_busy');
        }
        usleep(200000);
    }
    $delivery = $app->make(ReviewedSciencePageDelivery::class);
    $result = $request['mode'] === 'recover' ? $delivery->recover($binding) : $delivery->publish($binding);
    // Internal snapshot IDs are not deployment output.
    unset($result['snapshot_id']);
    echo json_encode(['ok' => true, 'schema' => 'science.reviewed_pages.delivery.v1', 'mode' => $request['mode'],
        'binding' => $binding, ...$result, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $restored = null;
    if ($delivery !== null && $binding !== null) {
        try {
            $restored = $delivery->recover($binding);
        } catch (Throwable) {
            $restored = ['restored' => false, 'recovery_status' => 'failed'];
        }
    }
    echo json_encode(['ok' => false, 'schema' => 'science.reviewed_pages.delivery.v1',
        'error_code' => $error instanceof DomainException && preg_match('/\A(?:science_|existing_science_)[a-z0-9_:]{1,140}\z/', $error->getMessage()) === 1
            ? $error->getMessage() : 'science_delivery_failed', 'recovery' => $restored, 'sanitized' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
