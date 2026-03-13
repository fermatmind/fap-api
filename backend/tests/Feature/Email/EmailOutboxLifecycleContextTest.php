<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailOutboxLifecycleContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_success_and_report_claim_payloads_include_lifecycle_foundation(): void
    {
        $this->seedCommerce();

        $attemptId = $this->createAttempt();
        $email = 'outbox+'.random_int(1000, 9999).'@example.com';

        $checkout = $this->postJson('/api/v0.3/orders/checkout', [
            'sku' => 'MBTI_CREDIT',
            'provider' => 'billing',
            'email' => $email,
            'attempt_id' => $attemptId,
            'surface' => 'checkout',
            'marketing_consent' => true,
            'transactional_recovery_enabled' => false,
            'share_id' => 'share_outbox',
            'compare_invite_id' => (string) Str::uuid(),
            'share_click_id' => 'clk_outbox',
            'entrypoint' => 'share_page',
            'referrer' => 'https://example.com/share/outbox',
            'landing_path' => '/zh/share/outbox',
            'utm' => [
                'source' => 'share',
                'medium' => 'organic',
                'campaign' => 'outbox-lifecycle',
                'term' => 'mbti',
                'content' => 'hero',
            ],
        ]);

        $checkout->assertOk();
        $orderNo = (string) $checkout->json('order_no');
        $this->assertNotSame('', $orderNo);

        /** @var EmailOutboxService $outbox */
        $outbox = app(EmailOutboxService::class);
        $this->assertTrue((bool) ($outbox->queuePaymentSuccess(
            'outbox_lifecycle_user',
            $email,
            $attemptId,
            $orderNo,
            'MBTI Full Report'
        )['ok'] ?? false));
        $claim = $outbox->queueReportClaim(
            'outbox_lifecycle_user',
            $email,
            $attemptId,
            $orderNo
        );
        $this->assertTrue((bool) ($claim['ok'] ?? false));
        $this->assertNotSame('', (string) ($claim['claim_url'] ?? ''));

        $paymentRow = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->first();
        $claimRow = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'report_claim')
            ->first();

        $this->assertNotNull($paymentRow);
        $this->assertNotNull($claimRow);

        $this->assertLifecyclePayload($paymentRow, $email, 'share_outbox', 'clk_outbox', false);
        $this->assertLifecyclePayload($claimRow, $email, 'share_outbox', 'clk_outbox', true);
    }

    public function test_refund_and_support_lifecycle_context_builder_include_status_and_attribution(): void
    {
        $this->seedCommerce();

        $attemptId = $this->createAttempt();
        $email = 'builder+'.random_int(1000, 9999).'@example.com';

        $checkout = $this->postJson('/api/v0.3/orders/checkout', [
            'sku' => 'MBTI_CREDIT',
            'provider' => 'billing',
            'email' => $email,
            'attempt_id' => $attemptId,
            'surface' => 'checkout',
            'marketing_consent' => false,
            'transactional_recovery_enabled' => true,
            'share_id' => 'share_builder',
            'share_click_id' => 'clk_builder',
            'entrypoint' => 'help_center',
            'referrer' => 'https://example.com/help',
            'landing_path' => '/zh/help/orders',
            'utm' => [
                'source' => 'help',
                'medium' => 'owned',
                'campaign' => 'support-context',
            ],
        ]);

        $checkout->assertOk();
        $orderNo = (string) $checkout->json('order_no');

        /** @var EmailOutboxService $outbox */
        $outbox = app(EmailOutboxService::class);

        $refundContext = $outbox->buildLifecyclePayloadContext($email, $orderNo, [
            'surface' => 'refund_notice',
        ]);
        $supportContext = $outbox->buildLifecyclePayloadContext($email, $orderNo, [
            'surface' => 'support_contact',
        ]);

        $this->assertSame('active', (string) ($refundContext['subscriber_status'] ?? ''));
        $this->assertSame('share_builder', (string) data_get($refundContext, 'attribution.share_id'));
        $this->assertSame('refund_notice', (string) ($refundContext['surface'] ?? ''));
        $this->assertSame('active', (string) ($supportContext['subscriber_status'] ?? ''));
        $this->assertSame('owned', (string) data_get($supportContext, 'attribution.utm.medium'));
        $this->assertSame('support_contact', (string) ($supportContext['surface'] ?? ''));
    }

    private function seedCommerce(): void
    {
        (new Pr19CommerceSeeder)->run();
    }

    private function createAttempt(): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_outbox_context',
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attemptId;
    }

    private function assertLifecyclePayload(object $row, string $email, string $shareId, string $shareClickId, bool $isClaim): void
    {
        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('active', (string) ($payloadJson['subscriber_status'] ?? ''));
        $this->assertSame(true, $payloadJson['marketing_consent'] ?? null);
        $this->assertSame(false, $payloadJson['transactional_recovery_enabled'] ?? null);
        $this->assertSame('checkout', (string) ($payloadJson['surface'] ?? ''));
        $this->assertSame($shareId, (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame($shareClickId, (string) data_get($payloadJson, 'attribution.share_click_id'));
        $this->assertSame('hero', (string) data_get($payloadJson, 'attribution.utm.content'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('claim_token', $payloadJson);
        $this->assertArrayNotHasKey('claim_url', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('active', (string) ($payloadEnc['subscriber_status'] ?? ''));
        $this->assertSame($shareId, (string) data_get($payloadEnc, 'attribution.share_id'));
        $this->assertSame($shareClickId, (string) data_get($payloadEnc, 'attribution.share_click_id'));
        $this->assertArrayNotHasKey('claim_token', $payloadEnc);
        $this->assertArrayNotHasKey('claim_url', $payloadEnc);

        if ($isClaim) {
            $this->assertNotSame('', (string) ($payloadEnc['claim_expires_at'] ?? ''));
        }
    }
}
