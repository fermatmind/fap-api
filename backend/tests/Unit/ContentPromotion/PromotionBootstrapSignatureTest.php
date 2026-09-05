<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PromotionBootstrapSignatureTest extends TestCase
{
    public function test_each_bootstrap_validates_live_signatures_with_or_without_cached_configuration(): void
    {
        $directory = sys_get_temp_dir().'/promotion-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $key = str_repeat('test-key', 8);
        $policy = hash('sha256', PromotionContextFactory::canonicalJson(config('content_promotion.release_policy')));
        $environment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'CONTENT_PROMOTION_AUTOMATION_KEY' => $key,
            'CONTENT_PROMOTION_SOURCE_COMMIT' => str_repeat('a', 40),
            'CONTENT_PROMOTION_WORKFLOW_RUN_ID' => '123456',
            'CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT' => '2',
            'CONTENT_PROMOTION_EXPECTED_ROW_COUNT' => '7',
            'CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256' => str_repeat('b', 64),
            'CONTENT_PROMOTION_RELEASE_POLICY_SHA256' => $policy,
        ];
        $sign = static fn (array $env): string => hash_hmac('sha256', implode('|', [
            'content-promotion-v2', $env['CONTENT_PROMOTION_SOURCE_COMMIT'],
            $env['CONTENT_PROMOTION_WORKFLOW_RUN_ID'], $env['CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT'],
            'W1', 'mbti-results', str_repeat('c', 64), $env['CONTENT_PROMOTION_RELEASE_POLICY_SHA256'],
            $env['CONTENT_PROMOTION_EXPECTED_ROW_COUNT'],
        ]), $key);
        $environment['CONTENT_PROMOTION_WORKFLOW_SIGNATURE'] = $sign($environment);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $context = $app->make(App\Services\ContentPromotion\PromotionContextFactory::class)
        ->make('content_packs', str_repeat('c', 64), 'W1', 'mbti-results');
    echo $context->sourceCommit.'|'.$context->workflowRunId.'|'.$context->workflowRunAttempt;
} catch (DomainException $exception) {
    echo $exception->getMessage();
}
PHP;
        try {
            foreach ([false, true] as $cached) {
                if ($cached) {
                    $stale = array_replace($environment, [
                        'CONTENT_PROMOTION_SOURCE_COMMIT' => str_repeat('f', 40),
                        'CONTENT_PROMOTION_WORKFLOW_SIGNATURE' => str_repeat('0', 64),
                        'CONTENT_PROMOTION_RELEASE_POLICY_SHA256' => str_repeat('0', 64),
                    ]);
                    (new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $stale))->mustRun();
                }
                $cases = [
                    [[], str_repeat('a', 40).'|123456|2'],
                    [['CONTENT_PROMOTION_WORKFLOW_SIGNATURE' => str_repeat('0', 64)], 'workflow_identity_signature_invalid'],
                    [['CONTENT_PROMOTION_EXPECTED_ROW_COUNT' => '8'], 'workflow_identity_signature_invalid'],
                    [['CONTENT_PROMOTION_RELEASE_POLICY_SHA256' => str_repeat('0', 64)], 'release_policy_sha256_mismatch'],
                ];
                $invalidRun = array_replace($environment, ['CONTENT_PROMOTION_WORKFLOW_RUN_ID' => '0']);
                $cases[] = [array_replace($invalidRun, ['CONTENT_PROMOTION_WORKFLOW_SIGNATURE' => $sign($invalidRun)]), 'workflow_identity_invalid'];
                foreach ($cases as [$override, $expected]) {
                    $process = new Process([PHP_BINARY, '-r', $script], base_path(), array_replace($environment, $override));
                    $process->mustRun();
                    $this->assertSame($expected, $process->getOutput());
                }
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
