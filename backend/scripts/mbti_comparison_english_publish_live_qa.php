<?php

declare(strict_types=1);

use App\Services\Cms\Mbti64CrossTypeComparisonPublicReadModel;
use App\Services\Cms\MbtiComparisonEnglishPublishService;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use Illuminate\Contracts\Console\Kernel;

const REQUIRED_ACTIVE_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53';

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, "comparison_english_publish_live_qa_failed\n");
    exit(1);
});

$options = getopt('', ['backend-root:', 'source-backend-root:', 'receipt-dir:', 'control-plane-sha:', 'active-revision:']);
foreach (['backend-root', 'source-backend-root', 'receipt-dir', 'control-plane-sha', 'active-revision'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required]) || trim($options[$required]) === '') {
        throw new RuntimeException('missing_required_option: '.$required);
    }
}
if (preg_match('/\\A[a-f0-9]{40}\\z/', $options['control-plane-sha']) !== 1
    || ! hash_equals(REQUIRED_ACTIVE_REVISION, $options['active-revision'])) {
    throw new RuntimeException('invalid_or_unapproved_revision');
}

$backendRoot = rtrim($options['backend-root'], '/');
$sourceBackendRoot = rtrim($options['source-backend-root'], '/');
$receiptDir = rtrim($options['receipt-dir'], '/');
if (! is_file($backendRoot.'/artisan') || ! is_file($backendRoot.'/vendor/autoload.php')
    || ! is_file($sourceBackendRoot.'/app/Services/Cms/MbtiComparisonEnglishPublishService.php')
    || ! is_dir($receiptDir) && ! mkdir($receiptDir, 0700, true) && ! is_dir($receiptDir)) {
    throw new RuntimeException('source_or_backend_not_bootstrapable');
}

require $backendRoot.'/vendor/autoload.php';
require $sourceBackendRoot.'/app/Services/Cms/MbtiComparisonEnglishPublishService.php';
$app = require $backendRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$approvalPath = $sourceBackendRoot.'/content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-COMPARISONS/publish-live-approval-2026-08-01.json';
$publisher = new MbtiComparisonEnglishPublishService;
$receipt = $publisher->publish($approvalPath, MbtiComparisonEnglishPublishService::APPROVAL_SHA256);

$readModel = $app->make(Mbti64CrossTypeComparisonPublicReadModel::class);
$runtimeRows = [];
foreach (MbtiComparisonEnglishPublishService::exactSlugs() as $slug) {
    $projection = $readModel->find($slug, 'en');
    if (! is_array($projection)
        || ($projection['comparison_slug'] ?? null) !== $slug
        || ($projection['locale'] ?? null) !== 'en'
        || ($projection['source_package_id'] ?? null) !== MbtiComparisonEnglishPackageImporter::PACKAGE_ID
        || ($projection['is_indexable'] ?? null) !== false
        || ($projection['sitemap_eligible'] ?? null) !== false
        || ($projection['llms_eligible'] ?? null) !== false) {
        throw new RuntimeException('runtime_projection_contract_mismatch');
    }
    $runtimeRows[] = [
        'slug' => $slug,
        'source_sha256' => $projection['_source_sha256'] ?? null,
        'meta_robots' => 'noindex,follow',
        'sitemap_included' => false,
        'llms_included' => false,
    ];
}

$receipt['control_plane_sha'] = $options['control-plane-sha'];
$receipt['active_revision'] = $options['active-revision'];
$receipt['runtime_live_qa'] = [
    'exact_row_count' => count($runtimeRows),
    'english_only' => true,
    'meta_robots' => 'noindex,follow',
    'sitemap_excluded' => true,
    'llms_withheld' => true,
    'rows' => $runtimeRows,
];
$receiptBytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
file_put_contents($receiptDir.'/comparison-english-publish-live-qa-receipt.json', $receiptBytes, LOCK_EX);
fwrite(STDOUT, json_encode(['status' => 'PASS', 'receipt_sha256' => hash('sha256', $receiptBytes)], JSON_UNESCAPED_SLASHES).PHP_EOL);
