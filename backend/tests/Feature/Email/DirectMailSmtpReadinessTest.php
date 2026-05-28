<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use Tests\TestCase;

final class DirectMailSmtpReadinessTest extends TestCase
{
    public function test_env_example_documents_directmail_without_enabling_external_mail_by_default(): void
    {
        $env = $this->repoFile('backend/.env.example');

        $this->assertStringContainsString('MAIL_MAILER=log', $env);
        $this->assertStringContainsString('MAIL_HOST=127.0.0.1', $env);
        $this->assertStringContainsString('MAIL_PORT=2525', $env);
        $this->assertStringContainsString('MAIL_PASSWORD=null', $env);
        $this->assertStringContainsString('EMAIL_OUTBOX_SEND=false', $env);
        $this->assertStringContainsString('OPS_GATE_SPF_DKIM_DMARC_OK=false', $env);

        $this->assertStringContainsString('# Production DirectMail SMTP readiness template.', $env);
        $this->assertStringContainsString('# MAIL_MAILER=smtp', $env);
        $this->assertStringContainsString('# MAIL_SCHEME=smtps', $env);
        $this->assertStringContainsString('# MAIL_HOST=smtpdm.aliyun.com', $env);
        $this->assertStringContainsString('# MAIL_PORT=465', $env);
        $this->assertStringContainsString('# MAIL_USERNAME=noreply@mail.fermatmind.com', $env);
        $this->assertStringContainsString('# MAIL_PASSWORD=<DirectMail SMTP password>', $env);
        $this->assertStringContainsString('# MAIL_FROM_ADDRESS=noreply@mail.fermatmind.com', $env);
        $this->assertStringContainsString('# MAIL_FROM_NAME=FermatMind', $env);
        $this->assertStringContainsString('# MAIL_EHLO_DOMAIN=mail.fermatmind.com', $env);
        $this->assertStringContainsString('# EMAIL_OUTBOX_SEND=true', $env);
        $this->assertStringContainsString('# OPS_GATE_SPF_DKIM_DMARC_OK=true', $env);

        $this->assertDoesNotMatchRegularExpression('/^MAIL_PASSWORD=(?!null$).+/m', $env);
    }

    public function test_runbook_records_directmail_dns_smtp_and_smoke_readiness(): void
    {
        $runbook = $this->repoFile('docs/RUNBOOK_SMTP_DNS.md');

        foreach ([
            'mail.fermatmind.com',
            'noreply@mail.fermatmind.com',
            'smtpdm.aliyun.com',
            'MAIL_SCHEME=smtps',
            'MAIL_PORT=465',
            'MAIL_EHLO_DOMAIN=mail.fermatmind.com',
            'EMAIL_OUTBOX_SEND=true',
            'OPS_GATE_SPF_DKIM_DMARC_OK=true',
            'v=spf1 include:spf1.dm.aliyun.com -all',
            'aliyun-cn-hangzhou._domainkey.mail',
            'v=DMARC1;p=none;rua=mailto:dmarc_report@service.aliyun.com',
            'mx01.dm.aliyun.com',
            'php artisan email:outbox-send --limit=1',
            'Mailer smtp: sent 1, blocked 0, failed 0.',
            '250 Send Mail OK',
            'delivered to Outlook inbox',
        ] as $needle) {
            $this->assertStringContainsString($needle, $runbook);
        }

        foreach ([
            'Do not commit `MAIL_PASSWORD`',
            'without raw credentials or private user data',
            'did not mutate code, publish content, deploy, submit URLs, enqueue',
        ] as $safetyBoundary) {
            $this->assertStringContainsString($safetyBoundary, $runbook);
        }
    }

    private function repoFile(string $relativePath): string
    {
        $path = dirname(base_path()).'/'.$relativePath;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
