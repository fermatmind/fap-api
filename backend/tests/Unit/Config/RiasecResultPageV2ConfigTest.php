<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class RiasecResultPageV2ConfigTest extends TestCase
{
    private const TENANT_IDS_ENV = 'RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_TENANT_IDS';

    public function test_production_allowed_tenant_ids_preserves_zero(): void
    {
        $this->assertTenantIdsFromEnv('0', ['0']);
    }

    public function test_production_allowed_tenant_ids_filters_only_empty_values(): void
    {
        $this->assertTenantIdsFromEnv('0,, 42, ', ['0', '42']);
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertTenantIdsFromEnv(string $value, array $expected): void
    {
        $snapshot = [
            'getenv' => getenv(self::TENANT_IDS_ENV),
            'env' => $_ENV[self::TENANT_IDS_ENV] ?? null,
            'server' => $_SERVER[self::TENANT_IDS_ENV] ?? null,
        ];

        try {
            $this->setEnvValue($value);
            config(['riasec_result_page_v2' => require base_path('config/riasec_result_page_v2.php')]);

            $this->assertSame(
                $expected,
                config('riasec_result_page_v2.production_rollout_allowed_tenant_ids')
            );
        } finally {
            $this->restoreEnv($snapshot);
            config(['riasec_result_page_v2' => require base_path('config/riasec_result_page_v2.php')]);
        }
    }

    private function setEnvValue(string $value): void
    {
        putenv(self::TENANT_IDS_ENV.'='.$value);
        $_ENV[self::TENANT_IDS_ENV] = $value;
        $_SERVER[self::TENANT_IDS_ENV] = $value;
    }

    /**
     * @param  array{getenv: string|false, env: mixed, server: mixed}  $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        if ($snapshot['getenv'] === false) {
            putenv(self::TENANT_IDS_ENV);
        } else {
            putenv(self::TENANT_IDS_ENV.'='.$snapshot['getenv']);
        }

        if ($snapshot['env'] === null) {
            unset($_ENV[self::TENANT_IDS_ENV]);
        } else {
            $_ENV[self::TENANT_IDS_ENV] = $snapshot['env'];
        }

        if ($snapshot['server'] === null) {
            unset($_SERVER[self::TENANT_IDS_ENV]);
        } else {
            $_SERVER[self::TENANT_IDS_ENV] = $snapshot['server'];
        }
    }
}
