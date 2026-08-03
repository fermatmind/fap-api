<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\PromotionContextFactory;
use Tests\TestCase;

/**
 * The promotion workflow passes its runtime context (run id, signature,
 * release-policy hash, previous-receipt path, ...) through process env at
 * dispatch time. Production runs `artisan config:cache` at deploy time, so
 * any read of `config('content_promotion.execution.*')` returns the value
 * frozen into `bootstrap/cache/config.php` (typically null) instead of the
 * value the workflow just exported.
 *
 * PromotionContextFactory::runtimeEnv resolves the value in priority
 * order: `$_SERVER` → `$_ENV` → `getenv()` → `config()` fallback → default.
 * The config fallback keeps tests and non-cached local environments
 * working; in production with `config:cache` the frozen value is null for
 * runtime-dispatched variables, so the fallback is inert there.
 */
final class PromotionContextRuntimeEnvTest extends TestCase
{
    /** @var list<string> */
    private const RUNTIME_ENV_NAMES = [
        'CONTENT_PROMOTION_SOURCE_COMMIT',
        'CONTENT_PROMOTION_WORKFLOW_RUN_ID',
        'CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT',
        'CONTENT_PROMOTION_EXPECTED_ROW_COUNT',
        'CONTENT_PROMOTION_EXECUTOR_RELEASE_SHA256',
        'CONTENT_PROMOTION_RELEASE_POLICY_SHA256',
        'CONTENT_PROMOTION_WORKFLOW_SIGNATURE',
        'CONTENT_PROMOTION_PREVIOUS_RECEIPT',
    ];

    protected function tearDown(): void
    {
        foreach (self::RUNTIME_ENV_NAMES as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function test_runtime_env_reads_server_superglobal(): void
    {
        $_SERVER['CONTENT_PROMOTION_RELEASE_POLICY_SHA256'] = 'aabbcc';

        self::assertSame('aabbcc', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_RELEASE_POLICY_SHA256',
            'content_promotion.execution.release_policy_sha256',
        ));
    }

    public function test_runtime_env_reads_env_superglobal_when_server_missing(): void
    {
        $_ENV['CONTENT_PROMOTION_WORKFLOW_RUN_ID'] = '99887766';

        self::assertSame('99887766', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_WORKFLOW_RUN_ID',
            'content_promotion.execution.workflow_run_id',
        ));
    }

    public function test_runtime_env_reads_getenv_when_superglobals_missing(): void
    {
        putenv('CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT=7');

        self::assertSame('7', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_WORKFLOW_RUN_ATTEMPT',
            'content_promotion.execution.workflow_run_attempt',
        ));
    }

    public function test_runtime_env_prefers_server_over_env_and_getenv(): void
    {
        $_SERVER['CONTENT_PROMOTION_SOURCE_COMMIT'] = 'from-server';
        $_ENV['CONTENT_PROMOTION_SOURCE_COMMIT'] = 'from-env';
        putenv('CONTENT_PROMOTION_SOURCE_COMMIT=from-getenv');

        self::assertSame('from-server', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_SOURCE_COMMIT',
            'content_promotion.execution.source_commit',
        ));
    }

    public function test_runtime_env_falls_back_to_config_when_env_absent(): void
    {
        // Simulates the test harness and non-cached local dev: no process
        // env, value set directly into the config repository.
        config(['content_promotion.execution.previous_receipt' => '/tmp/receipt.json']);

        self::assertSame('/tmp/receipt.json', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_PREVIOUS_RECEIPT',
            'content_promotion.execution.previous_receipt',
        ));
    }

    public function test_runtime_env_prefers_process_env_over_config(): void
    {
        // Production: config cache holds the deploy-time value (e.g. null
        // or a stale hash), but the workflow just exported the live value
        // through process env. The process env must win.
        config(['content_promotion.execution.release_policy_sha256' => 'frozen-at-deploy-time']);
        $_SERVER['CONTENT_PROMOTION_RELEASE_POLICY_SHA256'] = 'from-workflow-dispatch';

        self::assertSame('from-workflow-dispatch', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_RELEASE_POLICY_SHA256',
            'content_promotion.execution.release_policy_sha256',
        ));
    }

    public function test_runtime_env_returns_default_when_variable_absent_everywhere(): void
    {
        self::assertSame('', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_PREVIOUS_RECEIPT',
            'content_promotion.execution.previous_receipt',
        ));
        self::assertSame('fallback', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_PREVIOUS_RECEIPT',
            'content_promotion.execution.previous_receipt',
            'fallback',
        ));
    }

    public function test_runtime_env_treats_empty_string_as_unset(): void
    {
        $_SERVER['CONTENT_PROMOTION_WORKFLOW_SIGNATURE'] = '';
        putenv('CONTENT_PROMOTION_WORKFLOW_SIGNATURE=from-getenv');

        self::assertSame('from-getenv', PromotionContextFactory::runtimeEnv(
            'CONTENT_PROMOTION_WORKFLOW_SIGNATURE',
            'content_promotion.execution.workflow_signature',
        ));
    }
}
