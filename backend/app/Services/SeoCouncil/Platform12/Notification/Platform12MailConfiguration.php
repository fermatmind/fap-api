<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use RuntimeException;

final class Platform12MailConfiguration
{
    public function settings(): array
    {
        $production = app()->environment('production');
        $smtp = $production ? config('mail.mailers.smtp', []) : config('seo_council_mail.staging_smtp', []);
        $recipient = (string) config('seo_council_mail.recipient', '');
        $from = (string) ($production ? config('mail.from.address') : config('seo_council_mail.staging_from'));
        $url = rtrim((string) config('seo_council_mail.ops_url', ''), '/');
        $parts = parse_url($url);
        if ((! app()->environment(['production', 'staging', 'testing']))
            || config('seo_council_mail.channel') !== 'email'
            || ($smtp['transport'] ?? null) !== 'smtp'
            || ($production && config('mail.default') !== 'smtp')
            || filled($smtp['url'] ?? null)
            || ! in_array($smtp['scheme'] ?? null, ['smtp', 'smtps'], true)
            || empty($smtp['host']) || in_array($smtp['host'], ['localhost', '127.0.0.1'], true)
            || ($smtp['port'] ?? 0) < 1 || ($smtp['port'] ?? 0) > 65535
            || empty($smtp['username']) || empty($smtp['password'])
            || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || ! filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with($from, '@example.com')
            || filled(config('mail.to.address'))
            || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ($production && $url !== 'https://ops.fermatmind.com')) {
            throw new RuntimeException('MAIL_CONFIGURATION_HOLD');
        }

        $smtp = array_intersect_key($smtp, array_flip(['transport', 'scheme', 'host', 'port', 'username', 'password', 'local_domain']));
        $smtp += ['timeout' => 10, 'verify_peer' => true, 'auto_tls' => true, 'require_tls' => true];

        return ['smtp' => $smtp, 'recipient' => $recipient, 'from' => $from,
            'ops_url' => $url.'/ops/seo-operations?workspace=automation&automation-view=agents'];
    }

    public function preflight(): array
    {
        try {
            $this->settings();

            return ['state' => 'MAIL_CONFIGURATION_READY', 'smtp_acceptance' => 'NOT_TESTED', 'inbox_delivery' => 'NOT_VERIFIED'];
        } catch (RuntimeException) {
            return ['state' => 'MAIL_CONFIGURATION_HOLD', 'smtp_acceptance' => 'NOT_TESTED', 'inbox_delivery' => 'NOT_VERIFIED'];
        }
    }
}
