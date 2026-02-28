<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Contracts\Security\PiiEnvelopeAdapter;
use App\Support\Security\ExternalKmsContractException;
use App\Support\Security\ExternalKmsPiiEnvelopeAdapter;
use App\Support\Security\LocalPiiEnvelopeAdapter;
use App\Support\PiiCipher;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class PiiCipherEnvelopeTest extends TestCase
{
    public function test_encrypt_returns_envelope_and_decrypt_round_trips(): void
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        $plaintext = 'envelope.user@example.com';
        $encrypted = $pii->encrypt($plaintext);

        $this->assertIsString($encrypted);
        $this->assertNotSame('', trim((string) $encrypted));

        $envelope = json_decode((string) $encrypted, true);
        $this->assertIsArray($envelope);
        $this->assertSame($pii->currentKeyVersion(), (int) ($envelope['key_version'] ?? 0));
        $this->assertSame((string) config('services.pii.key_id', ''), (string) ($envelope['key_id'] ?? ''));
        $this->assertSame((string) config('services.pii.algo', ''), (string) ($envelope['algo'] ?? ''));
        $this->assertIsString($envelope['ciphertext'] ?? null);
        $this->assertNotSame('', trim((string) ($envelope['ciphertext'] ?? '')));

        $this->assertSame($plaintext, $pii->decrypt((string) $encrypted));
    }

    public function test_decrypt_supports_legacy_ciphertext_format(): void
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        $plaintext = 'legacy.user@example.com';
        $legacyCiphertext = Crypt::encryptString($plaintext);

        $this->assertSame($plaintext, $pii->decrypt($legacyCiphertext));
    }

    public function test_adapter_selection_supports_local_and_external_kms(): void
    {
        config()->set('services.pii.adapter', 'local');
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        $this->assertInstanceOf(LocalPiiEnvelopeAdapter::class, app(PiiEnvelopeAdapter::class));

        config()->set('services.pii.adapter', 'external_kms');
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        $this->assertInstanceOf(ExternalKmsPiiEnvelopeAdapter::class, app(PiiEnvelopeAdapter::class));
    }

    public function test_cross_adapter_decrypt_compatibility_is_preserved(): void
    {
        $plaintext = 'cross.adapter@example.com';

        config()->set('services.pii.adapter', 'local');
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $local */
        $local = app(PiiCipher::class);
        $localCiphertext = (string) $local->encrypt($plaintext);

        config()->set('services.pii.adapter', 'external_kms');
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $external */
        $external = app(PiiCipher::class);
        $this->assertSame($plaintext, $external->decrypt($localCiphertext));
        $externalCiphertext = (string) $external->encrypt($plaintext);

        config()->set('services.pii.adapter', 'local');
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $localAgain */
        $localAgain = app(PiiCipher::class);
        $this->assertSame($plaintext, $localAgain->decrypt($externalCiphertext));
    }

    public function test_external_kms_strict_timeout_throws_explicit_error_code(): void
    {
        config()->set('services.pii.adapter', 'external_kms');
        config()->set('services.pii.external_kms.simulate', 'timeout');
        config()->set('services.pii.external_kms.dry_run', false);
        config()->set('services.pii.external_kms.fallback_to_local', false);
        config()->set('services.pii.external_kms.max_retries', 0);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);

        try {
            app(PiiCipher::class)->encrypt('strict.timeout@example.com');
            self::fail('Expected ExternalKmsContractException to be thrown.');
        } catch (ExternalKmsContractException $e) {
            $this->assertSame('EXTERNAL_KMS_TIMEOUT', $e->errorCode());
            $this->assertTrue($e->isRetryable());
            $this->assertSame('timeout', $e->category());
            $this->assertSame('encrypt', $e->operation());
        }
    }

    public function test_external_kms_retryable_error_retries_then_succeeds(): void
    {
        config()->set('services.pii.adapter', 'external_kms');
        config()->set('services.pii.external_kms.simulate', 'timeout_once');
        config()->set('services.pii.external_kms.dry_run', false);
        config()->set('services.pii.external_kms.fallback_to_local', false);
        config()->set('services.pii.external_kms.max_retries', 1);
        config()->set('services.pii.external_kms.retry_backoff_ms', 1);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);

        $plaintext = 'retry.once.success@example.com';
        $cipher = (string) app(PiiCipher::class)->encrypt($plaintext);
        $this->assertNotSame('', trim($cipher));
        $this->assertSame($plaintext, app(PiiCipher::class)->decrypt($cipher));
    }

    public function test_external_kms_retryable_exhausted_can_fallback_when_enabled(): void
    {
        $plaintext = 'retryable.fallback@example.com';

        config()->set('services.pii.adapter', 'external_kms');
        config()->set('services.pii.external_kms.simulate', 'retryable');
        config()->set('services.pii.external_kms.dry_run', false);
        config()->set('services.pii.external_kms.fallback_to_local', true);
        config()->set('services.pii.external_kms.max_retries', 1);
        config()->set('services.pii.external_kms.retry_backoff_ms', 1);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $external */
        $external = app(PiiCipher::class);
        $ciphertext = (string) $external->encrypt($plaintext);
        $this->assertSame($plaintext, $external->decrypt($ciphertext));

        config()->set('services.pii.adapter', 'local');
        config()->set('services.pii.external_kms.simulate', 'none');
        config()->set('services.pii.external_kms.fallback_to_local', false);
        config()->set('services.pii.external_kms.max_retries', 0);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $local */
        $local = app(PiiCipher::class);
        $this->assertSame($plaintext, $local->decrypt($ciphertext));
    }

    public function test_external_kms_non_retryable_is_fail_closed_even_with_fallback_enabled(): void
    {
        config()->set('services.pii.adapter', 'external_kms');
        config()->set('services.pii.external_kms.simulate', 'non_retryable');
        config()->set('services.pii.external_kms.dry_run', true);
        config()->set('services.pii.external_kms.fallback_to_local', true);
        config()->set('services.pii.external_kms.max_retries', 2);
        config()->set('services.pii.external_kms.retry_backoff_ms', 1);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);

        try {
            app(PiiCipher::class)->encrypt('non.retryable.fail.closed@example.com');
            self::fail('Expected ExternalKmsContractException to be thrown.');
        } catch (ExternalKmsContractException $e) {
            $this->assertSame('EXTERNAL_KMS_NON_RETRYABLE', $e->errorCode());
            $this->assertFalse($e->isRetryable());
            $this->assertSame('non_retryable', $e->category());
            $this->assertSame('encrypt', $e->operation());
        }
    }

    public function test_external_kms_dry_run_fallback_preserves_cross_adapter_compatibility(): void
    {
        $plaintext = 'dry.run.fallback@example.com';

        config()->set('services.pii.adapter', 'local');
        config()->set('services.pii.external_kms.simulate', 'none');
        config()->set('services.pii.external_kms.dry_run', false);
        config()->set('services.pii.external_kms.fallback_to_local', false);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $local */
        $local = app(PiiCipher::class);
        $localCiphertext = (string) $local->encrypt($plaintext);

        config()->set('services.pii.adapter', 'external_kms');
        config()->set('services.pii.external_kms.simulate', 'retryable');
        config()->set('services.pii.external_kms.dry_run', true);
        config()->set('services.pii.external_kms.fallback_to_local', false);
        config()->set('services.pii.external_kms.max_retries', 1);
        config()->set('services.pii.external_kms.retry_backoff_ms', 1);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $externalDryRun */
        $externalDryRun = app(PiiCipher::class);
        $this->assertSame($plaintext, $externalDryRun->decrypt($localCiphertext));
        $externalCiphertext = (string) $externalDryRun->encrypt($plaintext);

        config()->set('services.pii.adapter', 'local');
        config()->set('services.pii.external_kms.simulate', 'none');
        config()->set('services.pii.external_kms.dry_run', false);
        config()->set('services.pii.external_kms.fallback_to_local', false);
        $this->app->forgetInstance(PiiEnvelopeAdapter::class);
        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $localAgain */
        $localAgain = app(PiiCipher::class);
        $this->assertSame($plaintext, $localAgain->decrypt($externalCiphertext));
    }
}
