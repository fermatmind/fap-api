<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BackfillPiiEncryptionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_dual_track_pii_columns_and_is_idempotent(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'PII Backfill User',
            'email' => 'Backfill.User@example.com',
            'password' => bcrypt('secret-123'),
            'phone_e164' => '+15551230000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outboxId = '11111111-1111-4111-8111-111111111111';
        DB::table('email_outbox')->insert([
            'id' => $outboxId,
            'user_id' => (string) $userId,
            'email' => 'Backfill.User@example.com',
            'to_email' => 'receiver@example.com',
            'template' => 'report_claim',
            'payload_json' => json_encode([
                'subject' => 'hello',
                'attempt_id' => 'attempt_demo_1',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'claim_token_hash' => hash('sha256', 'claim-token-demo'),
            'claim_expires_at' => now()->addDay(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-pii-encryption --sync --scope=all --chunk=100')
            ->expectsOutputToContain('pii encryption backfill completed (sync)')
            ->assertExitCode(0);

        $pii = app(PiiCipher::class);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertNotNull($user);
        $this->assertSame($pii->emailHash('Backfill.User@example.com'), (string) ($user->email_hash ?? ''));
        $this->assertSame($pii->phoneHash('+15551230000'), (string) ($user->phone_e164_hash ?? ''));
        $this->assertSame('Backfill.User@example.com', $pii->decrypt((string) ($user->email_enc ?? '')));
        $this->assertSame('+15551230000', $pii->decrypt((string) ($user->phone_e164_enc ?? '')));
        if (Schema::hasColumn('users', 'key_version')) {
            $this->assertSame($pii->currentKeyVersion(), (int) ($user->key_version ?? 0));
        }
        $this->assertNotNull($user->pii_migrated_at ?? null);

        $outbox = DB::table('email_outbox')->where('id', $outboxId)->first();
        $this->assertNotNull($outbox);
        $this->assertSame($pii->emailHash('Backfill.User@example.com'), (string) ($outbox->email_hash ?? ''));
        $this->assertSame($pii->emailHash('receiver@example.com'), (string) ($outbox->to_email_hash ?? ''));
        $this->assertSame('Backfill.User@example.com', $pii->decrypt((string) ($outbox->email_enc ?? '')));
        $this->assertSame('receiver@example.com', $pii->decrypt((string) ($outbox->to_email_enc ?? '')));
        $this->assertSame('v1-json-enc', (string) ($outbox->payload_schema_version ?? ''));
        if (Schema::hasColumn('email_outbox', 'key_version')) {
            $this->assertSame($pii->currentKeyVersion(), (int) ($outbox->key_version ?? 0));
        }
        $payloadDecoded = $pii->decrypt((string) ($outbox->payload_enc ?? ''));
        $this->assertIsString($payloadDecoded);
        $this->assertTrue(str_contains((string) $payloadDecoded, '"attempt_id":"attempt_demo_1"'));

        $this->artisan('ops:backfill-pii-encryption --sync --scope=all --chunk=100')
            ->expectsOutputToContain('pii encryption backfill completed (sync)')
            ->assertExitCode(0);

        $userAgain = DB::table('users')->where('id', $userId)->first();
        $outboxAgain = DB::table('email_outbox')->where('id', $outboxId)->first();
        $this->assertNotNull($userAgain);
        $this->assertNotNull($outboxAgain);
        $this->assertSame((string) ($user->email_hash ?? ''), (string) ($userAgain->email_hash ?? ''));
        $this->assertSame((string) ($outbox->to_email_hash ?? ''), (string) ($outboxAgain->to_email_hash ?? ''));

        $keys = DB::table('migration_backfills')
            ->whereIn('key', ['pii_backfill_users_v2', 'pii_backfill_email_outbox_v2'])
            ->pluck('key')
            ->all();

        $this->assertContains('pii_backfill_users_v2', $keys);
        $this->assertContains('pii_backfill_email_outbox_v2', $keys);
    }
}
