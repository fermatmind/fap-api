<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PiiKeyVersionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_pii_writes_include_key_version(): void
    {
        $phone = '+86139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $send = $this->postJson('/api/v0.3/auth/phone/send_code', [
            'phone' => $phone,
            'consent' => true,
        ]);
        $send->assertOk();

        $verify = $this->postJson('/api/v0.3/auth/phone/verify', [
            'phone' => $phone,
            'code' => (string) $send->json('dev_code', ''),
            'consent' => true,
            'scene' => 'login',
            'anon_id' => 'pii-key-version-test',
        ]);
        $verify->assertOk();

        $this->assertTrue(Schema::hasColumn('users', 'key_version'));
        $user = DB::table('users')->orderByDesc('id')->first();
        $this->assertNotNull($user);

        $pii = app(PiiCipher::class);
        $expectedKeyVersion = $pii->currentKeyVersion();
        $this->assertSame($expectedKeyVersion, (int) ($user->key_version ?? 0));

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_pii_key_version',
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'calculation_snapshot_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $email = 'buyer+'.random_int(1000, 9999).'@example.com';
        $queued = app(EmailOutboxService::class)->queueReportClaim((string) ($user->id ?? ''), $email, $attemptId);
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->assertTrue(Schema::hasColumn('email_outbox', 'key_version'));
        $outbox = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'report_claim')
            ->orderByDesc('updated_at')
            ->first();
        $this->assertNotNull($outbox);
        $this->assertSame($expectedKeyVersion, (int) ($outbox->key_version ?? 0));
    }

    public function test_claim_report_read_path_supports_legacy_null_key_version(): void
    {
        $this->assertTrue(Schema::hasColumn('email_outbox', 'key_version'));

        $pii = app(PiiCipher::class);
        $attemptId = (string) Str::uuid();
        $token = 'claim_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);
        $email = 'legacy-key-version@example.com';

        DB::table('email_outbox')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => 'legacy-key-version-user',
            'email' => $pii->legacyEmailPlaceholder($pii->emailHash($email)),
            'email_hash' => $pii->emailHash($email),
            'email_enc' => $pii->encrypt($email),
            'to_email_hash' => $pii->emailHash($email),
            'to_email_enc' => $pii->encrypt($email),
            'payload_json' => json_encode(['attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_enc' => $pii->encrypt(json_encode([
                'attempt_id' => $attemptId,
                'report_url' => "/api/v0.3/attempts/{$attemptId}/report",
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'payload_schema_version' => 'v1',
            'key_version' => null,
            'template' => 'report_claim',
            'claim_token_hash' => $tokenHash,
            'claim_expires_at' => now()->addMinutes(15),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $claimed = app(EmailOutboxService::class)->claimReport($token);
        $this->assertTrue((bool) ($claimed['ok'] ?? false));
        $this->assertSame($attemptId, (string) ($claimed['attempt_id'] ?? ''));
        $this->assertSame("/api/v0.3/attempts/{$attemptId}/report.pdf", (string) ($claimed['report_pdf_url'] ?? ''));
    }
}
