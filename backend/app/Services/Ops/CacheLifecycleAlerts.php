<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/** Bounded, direct delivery; failure/ack state survives Redis and queue outages. */
final class CacheLifecycleAlerts
{
    public function observe(string $component, bool $healthy, bool $urgent = false): void
    {
        if (! in_array($component, ['career_retention', 'sitemap_refresh', 'llms_refresh', 'redis_capacity', 'projection_integrity'], true)) {
            throw new \InvalidArgumentException('Unknown cache lifecycle component.');
        }
        $root = storage_path('app/ops/cache-lifecycle');
        File::ensureDirectoryExists($root, 0770);
        $lease = fopen($root.'/'.$component.'.lock', 'c');
        if ($lease !== false) {
            clearstatcache(true, $root.'/'.$component.'.lock');
            if ((fileperms($root.'/'.$component.'.lock') & 0777) !== 0660) {
                chmod($root.'/'.$component.'.lock', 0660);
            }
        }
        if ($lease === false || ! flock($lease, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Cache lifecycle observation lock unavailable.');
        }
        try {
            $path = $root.'/'.$component.'.json';
            $state = is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
            $state['failures'] = $healthy ? 0 : (int) ($state['failures'] ?? 0) + 1;
            $state['observed_at'] = time();
            $state['healthy'] = $healthy;
            $wasNotified = (bool) ($state['fault_notified'] ?? false);
            if (! $healthy && ($urgent || $state['failures'] >= 3)) {
                $state['pending_fault'] = true;
            }
            if ($healthy && ! $wasNotified && ($state['pending_fault'] ?? false)) {
                try {
                    $this->deliver($component, false);
                    $state['fault_notified'] = $wasNotified = true;
                    $state['pending_fault'] = false;
                    $state['sent_at'] = time();
                    $state['delivery_failed'] = false;
                } catch (\Throwable) {
                    $state['delivery_failed'] = true;
                    Log::warning('cache_lifecycle_email_failed', ['component' => $component]);
                }
            }
            $due = $healthy ? $wasNotified : ($urgent || $state['failures'] >= 3)
                && (! $wasNotified || time() - (int) ($state['sent_at'] ?? 0) >= 86400);
            if ($due) {
                try {
                    $this->deliver($component, $healthy);
                    $state['fault_notified'] = ! $healthy;
                    $state['pending_fault'] = false;
                    $state['sent_at'] = time();
                    $state['delivery_failed'] = false;
                } catch (\Throwable) {
                    // Keep unacknowledged state retryable; never mark an attempted send as delivered.
                    $state['delivery_failed'] = true;
                    Log::warning('cache_lifecycle_email_failed', ['component' => $component]);
                }
            }
            $temporary = $path.'.tmp';
            if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($temporary, $path)) {
                throw new \RuntimeException('Cache lifecycle observation write failed.');
            }
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0660) {
                chmod($path, 0660);
            }
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    /** @return array{ok:bool, checks:array<string,bool>} */
    public function health(): array
    {
        $checks = [];
        $components = ['career_retention' => 900, 'sitemap_refresh' => 900, 'llms_refresh' => 2400];
        if (is_file(storage_path('app/ops/cache-lifecycle/projection_integrity.json'))) {
            $components['projection_integrity'] = 900;
        }
        foreach ($components as $component => $maximumAge) {
            $path = storage_path('app/ops/cache-lifecycle/'.$component.'.json');
            try {
                $state = is_file($path) && filesize($path) < 65536
                    ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : null;
                $age = time() - (int) ($state['observed_at'] ?? 0);
                $checks[$component] = is_array($state) && ($state['healthy'] ?? false) === true
                    && ($state['delivery_failed'] ?? false) === false && $age >= 0 && $age <= $maximumAge;
            } catch (\Throwable) {
                $checks[$component] = false;
            }
        }

        return ['ok' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    public function verifyDelivery(): void
    {
        $root = storage_path('app/ops/cache-lifecycle');
        File::ensureDirectoryExists($root, 0770);
        $lock = fopen($root.'/mail_verification.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Mail verification lock unavailable.');
        }
        clearstatcache(true, $root.'/mail_verification.lock');
        if ((fileperms($root.'/mail_verification.lock') & 0777) !== 0660) {
            chmod($root.'/mail_verification.lock', 0660);
        }
        try {
            $path = $root.'/mail_verification.json';
            $identity = hash('sha256', json_encode([config('ops.cache_lifecycle.mail_recipient'), config('mail.from.address'),
                config('mail.mailers.smtp.host')], JSON_THROW_ON_ERROR));
            $state = is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
            if (($state['identity'] ?? null) !== $identity) {
                $state = ['identity' => $identity];
            }
            foreach (['fault' => false, 'recovery' => true] as $phase => $recovered) {
                if ($state[$phase] ?? false) {
                    continue;
                }
                $this->deliver('mail_delivery_verification', $recovered, true);
                $state[$phase] = true;
                $state['verified_at'] = time();
                if (file_put_contents($path.'.tmp', json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                    || ! rename($path.'.tmp', $path)) {
                    throw new \RuntimeException('Mail verification state write failed.');
                }
                clearstatcache(true, $path);
                if ((fileperms($path) & 0777) !== 0660) {
                    chmod($path, 0660);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function deliver(string $component, bool $recovered, bool $verification = false): void
    {
        $recipient = trim((string) config('ops.cache_lifecycle.mail_recipient'));
        $from = trim((string) config('mail.from.address'));
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Cache lifecycle mail configuration unavailable.');
        }
        $smtp = (array) config('mail.mailers.smtp');
        $smtp['timeout'] = 10;
        $mailer = app(MailManager::class)->build($smtp);
        $subject = '[FermatMind '.app()->environment().'] 缓存与 SEO '.($recovered ? '已恢复' : '异常');
        if ($verification) {
            $subject = '[FermatMind] 告警通道验证：'.($recovered ? '恢复通知' : '异常通知');
        }
        $body = ($verification ? "这是一封上线验收测试邮件，不代表生产故障。\n" : '').'组件：'.$component."\n".($recovered ? '连续检查中的此前故障已恢复。' : '自动维护未完成，请查看现有 Ops 和部署日志。')
            ."\n时间（UTC）：".gmdate('c');
        if ($mailer->raw($body, static function (Message $message) use ($recipient, $from, $subject): void {
            $message->from($from, 'FermatMind Ops')->to($recipient)->subject($subject);
        }) === null) {
            throw new \RuntimeException('Cache lifecycle mail acknowledgement unavailable.');
        }
    }
}
