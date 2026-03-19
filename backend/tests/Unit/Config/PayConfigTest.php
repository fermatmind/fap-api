<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class PayConfigTest extends TestCase
{
    public function test_alipay_app_secret_cert_is_resolved_from_private_key_path_when_inline_key_is_blank(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nunit-test-key\n-----END PRIVATE KEY-----";
        $path = tempnam(sys_get_temp_dir(), 'alipay_private_key_');

        if (! is_string($path) || trim($path) === '') {
            self::fail('failed to create temporary private key file.');
        }

        file_put_contents($path, $pem);
        $snapshot = $this->snapshotEnv([
            'ALIPAY_MERCHANT_PRIVATE_KEY',
            'ALIPAY_MERCHANT_PRIVATE_KEY_PATH',
        ]);

        try {
            $this->setEnvValue('ALIPAY_MERCHANT_PRIVATE_KEY', '');
            $this->setEnvValue('ALIPAY_MERCHANT_PRIVATE_KEY_PATH', $path);

            config(['pay' => require base_path('config/pay.php')]);

            $appSecretCert = (string) config('pay.alipay.default.app_secret_cert');

            $this->assertNotSame('', $appSecretCert);
            $this->assertStringContainsString('BEGIN PRIVATE KEY', $appSecretCert);
            $this->assertSame(trim($pem), $appSecretCert);
            $this->assertSame($path, config('pay.alipay.default.merchant_private_key_path'));
            $this->assertArrayHasKey('alipay_public_cert_path', config('pay.alipay.default', []));
        } finally {
            $this->restoreEnv($snapshot);
            @unlink($path);
            config(['pay' => require base_path('config/pay.php')]);
        }
    }

    public function test_alipay_app_secret_cert_prefers_inline_private_key_over_private_key_path(): void
    {
        $inlinePem = "-----BEGIN PRIVATE KEY-----\ninline-test-key\n-----END PRIVATE KEY-----";
        $path = tempnam(sys_get_temp_dir(), 'alipay_private_key_');

        if (! is_string($path) || trim($path) === '') {
            self::fail('failed to create temporary private key file.');
        }

        file_put_contents($path, "-----BEGIN PRIVATE KEY-----\npath-test-key\n-----END PRIVATE KEY-----");
        $snapshot = $this->snapshotEnv([
            'ALIPAY_MERCHANT_PRIVATE_KEY',
            'ALIPAY_MERCHANT_PRIVATE_KEY_PATH',
        ]);

        try {
            $this->setEnvValue('ALIPAY_MERCHANT_PRIVATE_KEY', $inlinePem);
            $this->setEnvValue('ALIPAY_MERCHANT_PRIVATE_KEY_PATH', $path);

            config(['pay' => require base_path('config/pay.php')]);

            $this->assertSame(trim($inlinePem), config('pay.alipay.default.app_secret_cert'));
            $this->assertSame(trim($inlinePem), config('pay.alipay.default.merchant_private_key'));
        } finally {
            $this->restoreEnv($snapshot);
            @unlink($path);
            config(['pay' => require base_path('config/pay.php')]);
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, array{getenv: string|false, env: mixed, server: mixed}>
     */
    private function snapshotEnv(array $keys): array
    {
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = [
                'getenv' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string, array{getenv: string|false, env: mixed, server: mixed}>  $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $values) {
            $getenvValue = $values['getenv'];
            if ($getenvValue === false) {
                putenv($key);
            } else {
                putenv($key.'='.$getenvValue);
            }

            if ($values['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $values['env'];
            }

            if ($values['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $values['server'];
            }
        }
    }

    private function setEnvValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
