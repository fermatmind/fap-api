<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

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
}
