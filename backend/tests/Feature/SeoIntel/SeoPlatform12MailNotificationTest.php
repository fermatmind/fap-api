<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform12\Notification\OpsAlertNotificationTransport;
use App\Services\SeoCouncil\Platform12\Notification\Platform12DeliveryAcknowledgementUnknown;
use App\Services\SeoCouncil\Platform12\Notification\Platform12MailConfiguration;
use App\Services\SeoCouncil\Platform12\Notification\Platform12MailNotificationTransport;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class SeoPlatform12MailNotificationTest extends TestCase
{
    private array $messages = [];

    private ?\Throwable $sendError = null;

    private int $sendCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $smtp = ['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'smtp.example.test',
            'port' => 465, 'username' => 'test-sender', 'password' => 'fixture-only'];
        config()->set([
            'seo_council_mail.channel' => 'email', 'seo_council_mail.recipient' => 'owner@example.test',
            'seo_council_mail.ops_url' => 'https://staging-ops.example.test',
            'seo_council_mail.staging_smtp' => $smtp,
            'seo_council_mail.staging_from' => 'ops-staging@example.test',
            'mail.default' => 'log', 'mail.mailers.smtp' => $smtp, 'mail.from.address' => 'production@example.test',
            'seo_council.daily_read_only_enabled' => true, 'seo_council.scheduler_enabled' => false,
        ]);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('raw')->andReturnUsing(function ($body, $callback) {
            $this->sendCount++;
            if ($this->sendError) {
                throw $this->sendError;
            }
            $message = new Message(new Email);
            $callback($message);
            $this->messages[] = ['body' => $body, 'email' => $message->getSymfonyMessage()];

            return Mockery::mock(SentMessage::class);
        });
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->withArgs(function ($config) {
            $this->assertSame('smtp', $config['transport']);
            $this->assertSame(10, $config['timeout']);
            $this->assertTrue($config['require_tls']);
            $this->assertTrue($config['verify_peer']);

            return true;
        })->andReturn($mailer);
        $this->app->instance(MailManager::class, $manager);
    }

    public function test_staging_preflight_and_mail_are_isolated_sanitized_and_fixed_recipient(): void
    {
        $this->app->instance('env', 'staging');
        $before = config('mail');
        $this->assertSame('MAIL_CONFIGURATION_READY', app(Platform12MailConfiguration::class)->preflight()['state']);
        app(Platform12MailNotificationTransport::class)->send(str_repeat('a', 64), [
            'event_type' => 'DATA_FAILURE', 'to' => 'attacker@example.test', 'prompt' => 'SECRET_FIXTURE',
            'query' => 'PRIVATE_FIXTURE', 'receipt' => ['secret' => 'SECRET_FIXTURE'],
        ]);
        $mail = $this->messages[0];
        $this->assertSame('[SEO测试·无需处理]', $mail['email']->getSubject());
        $this->assertSame('owner@example.test', $mail['email']->getTo()[0]->getAddress());
        $this->assertSame('ops-staging@example.test', $mail['email']->getFrom()[0]->getAddress());
        $this->assertSame([], $mail['email']->getCc());
        $this->assertSame([], $mail['email']->getBcc());
        $this->assertSame([], $mail['email']->getAttachments());
        $this->assertStringContainsString('automation-view=agents', $mail['body']);
        $this->assertStringNotContainsString('FIXTURE', $mail['body']);
        $this->assertSame($before, config('mail'));
    }

    public function test_production_uses_existing_smtp_and_distinct_recovery_subject(): void
    {
        $this->app->instance('env', 'production');
        config()->set(['mail.default' => 'smtp', 'seo_council_mail.ops_url' => 'https://ops.fermatmind.com']);
        $transport = app(Platform12MailNotificationTransport::class);
        $transport->send(str_repeat('a', 64), ['event_type' => 'DATA_FAILURE']);
        $transport->send(str_repeat('b', 64), ['event_type' => 'DATA_FAILURE_RECOVERY']);
        $this->assertSame('[SEO生产异常]', $this->messages[0]['email']->getSubject());
        $this->assertSame('[SEO生产恢复]', $this->messages[1]['email']->getSubject());
        $this->assertSame('production@example.test', $this->messages[0]['email']->getFrom()[0]->getAddress());
        $this->artisan('seo:council-mail test')->expectsOutputToContain('MAIL_TEST_ENVIRONMENT_DENIED')->assertFailed();
        $this->assertSame(2, $this->sendCount);
    }

    public function test_staging_never_falls_back_to_production_credentials(): void
    {
        $this->app->instance('env', 'staging');
        config()->set('seo_council_mail.staging_smtp.password', '');
        $this->artisan('seo:council-mail preflight')->expectsOutputToContain('MAIL_CONFIGURATION_HOLD')->assertFailed();
        $this->artisan('seo:council-mail test')->expectsOutputToContain('MAIL_CONFIGURATION_HOLD')->assertFailed();
        $this->assertSame(0, $this->sendCount);
    }

    public function test_log_array_failover_invalid_recipient_and_url_are_configuration_holds(): void
    {
        foreach (['log', 'array', 'failover', 'roundrobin'] as $driver) {
            config()->set('seo_council_mail.staging_smtp.transport', $driver);
            $this->assertSame('MAIL_CONFIGURATION_HOLD', app(Platform12MailConfiguration::class)->preflight()['state']);
        }
        config()->set('seo_council_mail.staging_smtp.transport', 'smtp');
        foreach (['owner@example.test,other@example.test', "owner@example.test\r\nBcc: other@example.test"] as $recipient) {
            config()->set('seo_council_mail.recipient', $recipient);
            $this->assertSame('MAIL_CONFIGURATION_HOLD', app(Platform12MailConfiguration::class)->preflight()['state']);
        }
        config()->set('seo_council_mail.recipient', 'owner@example.test');
        foreach (['http://ops.example.test', 'https://user:pass@ops.example.test', 'https://ops.example.test/private?query=secret'] as $url) {
            config()->set('seo_council_mail.ops_url', $url);
            $this->assertSame('MAIL_CONFIGURATION_HOLD', app(Platform12MailConfiguration::class)->preflight()['state']);
        }
        $this->assertSame(0, $this->sendCount);
    }

    public function test_channel_selection_preserves_webhook_and_rejects_unknown_channel(): void
    {
        $this->assertInstanceOf(Platform12MailNotificationTransport::class, app(Platform12NotificationTransport::class));
        config()->set('seo_council_mail.channel', 'webhook');
        $this->assertInstanceOf(OpsAlertNotificationTransport::class, app(Platform12NotificationTransport::class));
        config()->set('seo_council_mail.channel', 'unknown');
        $this->expectExceptionMessage('NOTIFICATION_CHANNEL_INVALID');
        app(Platform12NotificationTransport::class);
    }

    public function test_unknown_or_success_event_cannot_send(): void
    {
        $this->expectExceptionMessage('MAIL_EVENT_DENIED');
        app(Platform12MailNotificationTransport::class)->send(str_repeat('a', 64), ['event_type' => 'SUCCESS']);
    }

    public function test_smtp_explicit_rejection_is_safe_failure_but_timeout_is_unknown(): void
    {
        $this->sendError = new UnexpectedResponseException('sensitive provider output', 550);
        try {
            app(Platform12MailNotificationTransport::class)->send(str_repeat('a', 64), ['event_type' => 'DATA_FAILURE']);
            $this->fail('Expected rejection');
        } catch (RuntimeException $error) {
            $this->assertSame('MAIL_SMTP_REJECTED', $error->getMessage());
        }
        $this->sendError = new TransportException('sensitive timeout output');
        $this->expectException(Platform12DeliveryAcknowledgementUnknown::class);
        app(Platform12MailNotificationTransport::class)->send(str_repeat('a', 64), ['event_type' => 'DATA_FAILURE']);
    }

    public function test_staging_test_sends_once_without_resuming_or_draining_even_after_unknown_result(): void
    {
        $this->app->instance('env', 'staging');
        $path = tempnam(sys_get_temp_dir(), 'council-mail-revision-');
        file_put_contents($path, str_repeat('a', 40));
        $store = new Repository(new ArrayStore);
        config()->set(['seo_council.release_revision_path' => $path, 'seo_council.runtime_cache_store' => 'mail_test',
            'cache.stores.mail_test.driver' => 'redis']);
        Cache::shouldReceive('store')->with('mail_test')->andReturn($store);
        Cache::shouldReceive('driver')->andReturn($store);
        $before = config('seo_council');
        try {
            $this->artisan('seo:council-mail test')->expectsOutputToContain('SMTP_ACCEPTED_INBOX_UNCONFIRMED')->assertSuccessful();
            $this->artisan('seo:council-mail test')->expectsOutputToContain('SMTP_ALREADY_ACCEPTED_INBOX_UNCONFIRMED')->assertSuccessful();
            $this->assertSame(1, $this->sendCount);
            file_put_contents($path, str_repeat('b', 40));
            $this->sendError = new TransportException('timeout');
            $this->artisan('seo:council-mail test')->expectsOutputToContain('DELIVERY_ACK_UNKNOWN')->assertFailed();
            $this->artisan('seo:council-mail test')->expectsOutputToContain('DELIVERY_ACK_UNKNOWN')->assertFailed();
            $this->assertSame(2, $this->sendCount);
            $this->assertSame($before, config('seo_council'));
        } finally {
            unlink($path);
        }
    }
}
