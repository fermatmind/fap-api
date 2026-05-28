<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\AttemptEmailBinding;
use App\Services\Email\EmailOutboxService;
use App\Services\Results\ResultAccessTokenService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ResultAccessLinkTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localeProvider')]
    public function test_result_access_link_renders_signed_frontend_link_without_persisting_plaintext_token(
        string $locale,
        string $expectedTitle,
        string $expectedPathPrefix
    ): void {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt($locale);
        $binding = $this->createBinding($attemptId, 'recover@example.test');
        $token = app(ResultAccessTokenService::class)->issueForBinding($binding);
        $resultUrl = $expectedPathPrefix.$attemptId.'?access_token='.rawurlencode((string) $token['token']);

        $queued = app(EmailOutboxService::class)->queueResultAccessLink(
            $binding,
            'recover@example.test',
            $token,
            $resultUrl,
            $locale,
            ['surface' => 'result_email_recovery']
        );

        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $absoluteResultUrl = 'https://app.example.test'.$resultUrl;
        $this->assertStringContainsString($expectedTitle, $html);
        $this->assertStringContainsString($absoluteResultUrl, $html);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'result_access_link')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('sent', (string) ($row->status ?? ''));
        $this->assertSame('', (string) ($row->body_html ?? ''));
        $this->assertStringNotContainsString((string) $token['token'], (string) ($row->payload_json ?? ''));

        $payload = json_decode((string) app(PiiCipher::class)->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payload);
        $this->assertSame($absoluteResultUrl, (string) $this->absolutePayloadUrl($payload['result_access_url'] ?? ''));
        $this->assertSame((string) $token['token'], (string) ($payload['result_access_token'] ?? ''));
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function localeProvider(): array
    {
        return [
            'en' => ['en', 'Your result access link', '/en/result/'],
            'zh-CN' => ['zh-CN', '你的结果访问链接', '/zh/result/'],
        ];
    }

    private function createAttempt(string $locale): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_result_access_link',
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
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

    private function createBinding(string $attemptId, string $email): AttemptEmailBinding
    {
        $cipher = app(PiiCipher::class);
        $normalizedEmail = $cipher->normalizeEmail($email);

        return AttemptEmailBinding::create([
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'pii_email_key_version' => (string) $cipher->currentKeyVersion(),
            'email_hash' => $cipher->emailHash($normalizedEmail),
            'email_enc' => $cipher->encrypt($normalizedEmail),
            'bound_anon_id' => 'anon_result_access_link',
            'bound_user_id' => null,
            'status' => AttemptEmailBinding::STATUS_ACTIVE,
            'source' => 'result_gate',
            'first_bound_at' => now(),
            'last_accessed_at' => now(),
        ]);
    }

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    private function absolutePayloadUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return 'https://app.example.test/'.ltrim($url, '/');
    }
}
