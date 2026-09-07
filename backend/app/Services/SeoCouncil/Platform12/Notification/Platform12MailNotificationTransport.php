<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use RuntimeException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

final readonly class Platform12MailNotificationTransport implements Platform12NotificationTransport
{
    public function __construct(private Platform12MailConfiguration $configuration, private MailManager $mail) {}

    public function send(string $notificationId, array $sanitizedPayload): void
    {
        $type = (string) ($sanitizedPayload['event_type'] ?? '');
        $recovering = str_ends_with($type, '_RECOVERY');
        $base = $recovering ? substr($type, 0, -9) : $type;
        $description = match ($base) {
            'PRIVATE_OR_SAFETY' => '隐私或安全检查异常。',
            'AUTHORITY_INDEXABILITY_P0', 'AUTHORITY_INDEXABILITY_P1' => 'canonical 或 indexability 检查异常。',
            'DATA_FAILURE' => '数据来源失败或过期。',
            'CANARY_ROLLBACK_FAILURE' => 'Canary 或回滚检查异常。',
            'POLICY_HASH_DRIFT' => 'Policy 或依赖版本漂移。',
            'UNAUTHORIZED_TOOL' => '检测到未授权工具请求。',
            'HIGH_VALUE_TIME_SENSITIVE_DECISION' => '有通过固定价值与时效门槛的决策待处理。',
            default => throw new RuntimeException('MAIL_EVENT_DENIED'),
        };
        $this->deliver($notificationId, $recovering ? '此前问题已恢复，本次只通知一次。' : $description, $recovering);
    }

    public function sendTest(string $notificationId): void
    {
        if (! app()->environment('staging')) {
            throw new RuntimeException('MAIL_TEST_ENVIRONMENT_DENIED');
        }
        $this->deliver($notificationId, '这是一封 staging 邮件通道验收测试，不代表真实事故，无需处理。Council 仍保持暂停。', false);
    }

    private function deliver(string $notificationId, string $description, bool $recovering): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $notificationId) !== 1) {
            throw new RuntimeException('MAIL_REFERENCE_INVALID');
        }
        $settings = $this->configuration->settings();
        $subject = app()->environment('production')
            ? ($recovering ? '[SEO生产恢复]' : '[SEO生产异常]') : '[SEO测试·无需处理]';
        $body = $description."\n通知生成时间（UTC）：".now('UTC')->toIso8601String()
            ."\n来源观测时间：请查看 Operations 中的原始观测时间。"
            ."\n影响：".($recovering ? '此前异常已恢复。' : '请结合证据确认受影响的数据或检查范围。')
            ."\n下一步：打开 Operations 查看依据与处理建议；本邮件不授权执行任何业务写操作。"
            ."\n".$settings['ops_url']."\nreference=".substr($notificationId, 0, 16);
        // Build an isolated SMTP mailer; never alter the application's default mailer.
        $mailer = $this->mail->build($settings['smtp']);
        try {
            $sent = $mailer->raw($body, static function (Message $message) use ($settings, $subject): void {
                $message->from($settings['from'], 'FermatMind SEO')->to($settings['recipient'])->subject($subject);
            });
            if ($sent === null) {
                throw new Platform12DeliveryAcknowledgementUnknown;
            }
        } catch (UnexpectedResponseException $error) {
            if ($error->getCode() >= 400 && $error->getCode() <= 599) {
                throw new RuntimeException('MAIL_SMTP_REJECTED');
            }
            throw new Platform12DeliveryAcknowledgementUnknown;
        } catch (Throwable) {
            // Never leak provider debug output or retry a potentially accepted message.
            throw new Platform12DeliveryAcknowledgementUnknown;
        }
    }
}
