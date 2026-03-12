<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportClaimTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localeProvider')]
    public function test_report_claim_renders_required_links_for_each_supported_locale(string $locale, string $expectedTitle): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt($locale);
        $queued = app(EmailOutboxService::class)->queueReportClaim(
            'report_claim_template_user',
            'claim+template@example.com',
            $attemptId,
            'ord_template_001',
            [
                'entrypoint' => 'order_lookup',
                'landing_path' => '/orders/lookup',
                'utm' => [
                    'source' => 'help',
                    'medium' => 'owned',
                    'campaign' => 'report-recovery',
                ],
            ],
            $locale
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString($expectedTitle, $html);
        $this->assertStringContainsString("https://api.example.test/api/v0.3/attempts/{$attemptId}/report", $html);
        $this->assertStringContainsString("https://api.example.test/api/v0.3/attempts/{$attemptId}/report.pdf", $html);
        $this->assertStringContainsString('https://app.example.test/orders/lookup?', $html);
        $this->assertStringContainsString('https://app.example.test/email/preferences?token=', $html);
        $this->assertStringContainsString('https://app.example.test/email/unsubscribe?token=', $html);
        $this->assertStringContainsString('ord_template_001', $html);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function localeProvider(): array
    {
        return [
            'en' => ['en', 'Report recovery'],
            'zh-CN' => ['zh-CN', '报告恢复'],
        ];
    }

    private function createAttempt(string $locale): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_template_claim',
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

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
