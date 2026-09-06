<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use App\Services\Ops\OpsAlertService;

final class OpsAlertNotificationTransport implements Platform12NotificationTransport
{
    public function send(string $notificationId, array $sanitizedPayload): void
    {
        $type = (string) ($sanitizedPayload['event_type'] ?? '');
        $recovering = str_ends_with($type, '_RECOVERY');
        $base = $recovering ? substr($type, 0, -9) : $type;
        $description = match ($base) {
            'PRIVATE_OR_SAFETY' => '隐私或安全检查异常，请优先查看证据并保持业务写入关闭。',
            'AUTHORITY_INDEXABILITY_P0', 'AUTHORITY_INDEXABILITY_P1' => 'canonical 或 indexability 检查异常，请核对 URL Truth 与权威来源。',
            'DATA_FAILURE' => '数据来源失败或过期，请检查 GSC 与运行证据的新鲜度。',
            'POLICY_HASH_DRIFT' => 'Policy 或依赖版本漂移，请核对当前版本。',
            'UNAUTHORIZED_TOOL' => '检测到未授权工具请求，请检查调用来源。',
            default => '运营检查需要处理，请打开 Operations 查看依据。',
        };
        $link = rtrim((string) config('app.url'), '/').'/ops/seo-operations?workspace=automation';
        OpsAlertService::send(sprintf(
            "[SEO Council %s] %s\n%s\nreference=%s",
            $recovering ? '已恢复' : '异常',
            $recovering ? '此前问题已恢复，本次只通知一次。' : $description,
            $link, substr($notificationId, 0, 16),
        ), true);
    }
}
