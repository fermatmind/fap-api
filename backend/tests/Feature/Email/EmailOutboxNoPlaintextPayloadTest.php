<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailOutboxNoPlaintextPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_writes_masked_plaintext_columns_and_sanitized_payload_json(): void
    {
        $userId = 'email_outbox_user_' . random_int(1000, 9999);
        $email = 'buyer+' . random_int(1000, 9999) . '@example.com';
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-' . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_email_outbox',
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

        /** @var EmailOutboxService $service */
        $service = app(EmailOutboxService::class);
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        $claim = $service->queueReportClaim($userId, $email, $attemptId);
        $this->assertTrue((bool) ($claim['ok'] ?? false));

        $this->assertTrue($service->queuePaymentSuccess($userId, $email, $attemptId, 'ord_1001', 'MBTI Full Report')['ok']);

        $rows = DB::table('email_outbox')
            ->where('user_id', $userId)
            ->where('attempt_id', $attemptId)
            ->orderBy('template')
            ->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $template = (string) ($row->template ?? '');
            $this->assertContains($template, ['report_claim', 'payment_success']);

            $this->assertSame($pii->emailHash($email), (string) ($row->email_hash ?? ''));
            $this->assertSame($pii->emailHash($email), (string) ($row->to_email_hash ?? ''));
            $this->assertSame($email, $pii->decrypt((string) ($row->email_enc ?? '')));
            $this->assertSame($email, $pii->decrypt((string) ($row->to_email_enc ?? '')));

            $expectedMasked = $pii->legacyEmailPlaceholder($pii->emailHash($email));
            if (Schema::hasColumn('email_outbox', 'email')) {
                $this->assertSame($expectedMasked, (string) ($row->email ?? ''));
                $this->assertNotSame($email, (string) ($row->email ?? ''));
            }
            if (Schema::hasColumn('email_outbox', 'to_email')) {
                $this->assertSame($expectedMasked, (string) ($row->to_email ?? ''));
                $this->assertNotSame($email, (string) ($row->to_email ?? ''));
            }

            $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
            $this->assertIsArray($payloadJson);
            $this->assertSame($attemptId, (string) ($payloadJson['attempt_id'] ?? ''));
            $this->assertArrayNotHasKey('email', $payloadJson);
            $this->assertArrayNotHasKey('to_email', $payloadJson);
            $this->assertArrayNotHasKey('claim_token', $payloadJson);
            $this->assertArrayNotHasKey('claim_url', $payloadJson);

            $payloadEncDecoded = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
            $this->assertIsArray($payloadEncDecoded);
            $this->assertSame($attemptId, (string) ($payloadEncDecoded['attempt_id'] ?? ''));
            $this->assertSame($email, (string) ($payloadEncDecoded['to_email'] ?? ''));
        }
    }
}
