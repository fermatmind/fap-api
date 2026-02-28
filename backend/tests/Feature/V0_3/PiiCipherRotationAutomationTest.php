<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PiiCipherRotationAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_command_reencrypts_to_target_version_and_writes_audit(): void
    {
        config()->set('services.pii.adapter', 'local');
        config()->set('services.pii.key_version', 1);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        $email = 'rotate.user@example.com';
        $phone = '+15551239999';
        $payload = json_encode(['attempt_id' => 'attempt_rotate_1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($payload);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Rotate User',
            'email' => $email,
            'password' => bcrypt('secret-123'),
            'phone_e164' => $phone,
            'email_hash' => $pii->emailHash($email),
            'email_enc' => $pii->encryptWithKeyVersion($email, 1),
            'phone_e164_hash' => $pii->phoneHash($phone),
            'phone_e164_enc' => $pii->encryptWithKeyVersion($phone, 1),
            'key_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outboxId = '22222222-2222-4222-8222-222222222222';
        DB::table('email_outbox')->insert([
            'id' => $outboxId,
            'user_id' => (string) $userId,
            'email' => $email,
            'email_hash' => $pii->emailHash($email),
            'email_enc' => $pii->encryptWithKeyVersion($email, 1),
            'to_email' => 'receiver@example.com',
            'to_email_hash' => $pii->emailHash('receiver@example.com'),
            'to_email_enc' => $pii->encryptWithKeyVersion('receiver@example.com', 1),
            'template' => 'report_claim',
            'payload_json' => $payload,
            'payload_enc' => $pii->encryptWithKeyVersion($payload, 1),
            'payload_schema_version' => 'v1-json-enc',
            'key_version' => 1,
            'claim_token_hash' => hash('sha256', 'rotate-claim-token'),
            'claim_expires_at' => now()->addDay(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-pii-encryption', [
            '--sync' => true,
            '--scope' => 'all',
            '--rotate-key-version' => 2,
            '--batch' => 'batch_rotate_v2',
        ])->assertExitCode(0);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertNotNull($user);
        $this->assertSame(2, (int) ($user->key_version ?? 0));
        $this->assertSame($email, $pii->decrypt((string) ($user->email_enc ?? '')));
        $this->assertSame($phone, $pii->decrypt((string) ($user->phone_e164_enc ?? '')));

        $outbox = DB::table('email_outbox')->where('id', $outboxId)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(2, (int) ($outbox->key_version ?? 0));
        $this->assertSame($email, $pii->decrypt((string) ($outbox->email_enc ?? '')));
        $this->assertSame('receiver@example.com', $pii->decrypt((string) ($outbox->to_email_enc ?? '')));
        $this->assertSame($payload, $pii->decrypt((string) ($outbox->payload_enc ?? '')));

        if (Schema::hasTable('rotation_audits')) {
            $audit = DB::table('rotation_audits')
                ->where('scope', 'pii')
                ->where('batch_ref', 'batch_rotate_v2')
                ->orderByDesc('created_at')
                ->first();
            $this->assertNotNull($audit);
            $this->assertSame(2, (int) ($audit->key_version ?? 0));
            $this->assertSame('ok', (string) ($audit->result ?? ''));
        }

        $this->assertSame(2, app(PiiCipher::class)->currentKeyVersion());

        $emailEncAfterFirst = (string) ($user->email_enc ?? '');
        $payloadEncAfterFirst = (string) ($outbox->payload_enc ?? '');

        $this->artisan('ops:backfill-pii-encryption', [
            '--sync' => true,
            '--scope' => 'all',
            '--rotate-key-version' => 2,
            '--batch' => 'batch_rotate_v2_repeat',
        ])->assertExitCode(0);

        $userAgain = DB::table('users')->where('id', $userId)->first();
        $outboxAgain = DB::table('email_outbox')->where('id', $outboxId)->first();
        $this->assertNotNull($userAgain);
        $this->assertNotNull($outboxAgain);
        $this->assertSame($emailEncAfterFirst, (string) ($userAgain->email_enc ?? ''));
        $this->assertSame($payloadEncAfterFirst, (string) ($outboxAgain->payload_enc ?? ''));

        if (Schema::hasTable('rotation_audits')) {
            $noopAudit = DB::table('rotation_audits')
                ->where('scope', 'pii')
                ->where('batch_ref', 'batch_rotate_v2_repeat')
                ->orderByDesc('created_at')
                ->first();
            $this->assertNotNull($noopAudit);
            $this->assertSame('noop', (string) ($noopAudit->result ?? ''));
        }
    }

    public function test_rotation_succeeds_with_external_kms_retryable_fallback_and_keeps_old_ciphertext_readable(): void
    {
        config()->set('services.pii.adapter', 'local');
        config()->set('services.pii.key_version', 1);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        $email = 'rotate.external.kms@example.com';
        $phone = '+15550002222';
        $payload = json_encode(['attempt_id' => 'attempt_rotate_external_1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($payload);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Rotate External Kms User',
            'email' => $email,
            'password' => bcrypt('secret-123'),
            'phone_e164' => $phone,
            'email_hash' => $pii->emailHash($email),
            'email_enc' => $pii->encryptWithKeyVersion($email, 1),
            'phone_e164_hash' => $pii->phoneHash($phone),
            'phone_e164_enc' => $pii->encryptWithKeyVersion($phone, 1),
            'key_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outboxId = '33333333-3333-4333-8333-333333333333';
        DB::table('email_outbox')->insert([
            'id' => $outboxId,
            'user_id' => (string) $userId,
            'email' => $email,
            'email_hash' => $pii->emailHash($email),
            'email_enc' => $pii->encryptWithKeyVersion($email, 1),
            'to_email' => 'receiver.external@example.com',
            'to_email_hash' => $pii->emailHash('receiver.external@example.com'),
            'to_email_enc' => $pii->encryptWithKeyVersion('receiver.external@example.com', 1),
            'template' => 'report_claim',
            'payload_json' => $payload,
            'payload_enc' => $pii->encryptWithKeyVersion($payload, 1),
            'payload_schema_version' => 'v1-json-enc',
            'key_version' => 1,
            'claim_token_hash' => hash('sha256', 'rotate-external-claim-token'),
            'claim_expires_at' => now()->addDay(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('services.pii.adapter', 'external_kms');
        config()->set('services.pii.external_kms.simulate', 'retryable');
        config()->set('services.pii.external_kms.max_retries', 1);
        config()->set('services.pii.external_kms.retry_backoff_ms', 1);
        config()->set('services.pii.external_kms.dry_run', false);
        config()->set('services.pii.external_kms.fallback_to_local', true);

        $this->artisan('ops:backfill-pii-encryption', [
            '--sync' => true,
            '--scope' => 'all',
            '--rotate-key-version' => 2,
            '--batch' => 'batch_rotate_external_kms',
        ])->assertExitCode(0);

        $this->app->forgetInstance(PiiCipher::class);
        /** @var PiiCipher $cipher */
        $cipher = app(PiiCipher::class);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertNotNull($user);
        $this->assertSame(2, (int) ($user->key_version ?? 0));
        $this->assertSame($email, $cipher->decrypt((string) ($user->email_enc ?? '')));
        $this->assertSame($phone, $cipher->decrypt((string) ($user->phone_e164_enc ?? '')));

        $outbox = DB::table('email_outbox')->where('id', $outboxId)->first();
        $this->assertNotNull($outbox);
        $this->assertSame(2, (int) ($outbox->key_version ?? 0));
        $this->assertSame($payload, $cipher->decrypt((string) ($outbox->payload_enc ?? '')));
    }
}
