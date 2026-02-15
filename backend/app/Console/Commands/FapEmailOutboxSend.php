<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

class FapEmailOutboxSend extends Command
{
    protected $signature = 'email:outbox-send {--limit=50}';
    protected $description = 'Send pending email outbox rows using the log mailer.';

    public function handle(): int
    {
        if (!$this->outboxSendEnabled()) {
            $this->info('EMAIL_OUTBOX_SEND=0 (disabled)');
            return Command::SUCCESS;
        }

        if (!Schema::hasTable('email_outbox')) {
            $this->warn('email_outbox table missing');
            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 500) {
            $limit = 500;
        }

        $rows = DB::table('email_outbox')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('claim_expires_at')
                    ->orWhere('claim_expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No pending outbox rows.');
            return Command::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $payload = $this->decodePayload($row->payload_json ?? null);
            $email = $this->resolveRecipientEmail($row, $payload);
            if ($email === '') {
                $skipped++;
                continue;
            }

            $locale = $this->normalizeLocale((string) ($row->locale ?? ($payload['locale'] ?? 'en')));
            $templateKey = $this->resolveTemplateKey($row, $payload);
            $subject = $this->resolveSubject($row, $payload, $templateKey, $locale);
            $bodyHtml = $this->resolveBodyHtml($row, $payload, $templateKey, $locale);
            $bodyText = $this->resolveBodyText($payload);

            try {
                if ($bodyHtml !== '') {
                    Mail::mailer('log')->html($bodyHtml, function ($m) use ($email, $subject): void {
                        $m->to($email)->subject($subject);
                    });
                } else {
                    Mail::mailer('log')->raw($bodyText, function ($m) use ($email, $subject): void {
                        $m->to($email)->subject($subject);
                    });
                }
            } catch (\Throwable $e) {
                $this->warn('Send failed for outbox id=' . (string) ($row->id ?? ''));
                continue;
            }

            $update = [
                'status' => 'sent',
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('email_outbox', 'locale')) {
                $update['locale'] = $locale;
            }
            if (Schema::hasColumn('email_outbox', 'template_key')) {
                $update['template_key'] = $templateKey;
            }
            if (Schema::hasColumn('email_outbox', 'to_email')) {
                $update['to_email'] = $email;
            }
            if (Schema::hasColumn('email_outbox', 'subject')) {
                $update['subject'] = $subject;
            }
            if (Schema::hasColumn('email_outbox', 'body_html') && $bodyHtml !== '') {
                $update['body_html'] = $bodyHtml;
            }
            if (Schema::hasColumn('email_outbox', 'sent_at')) {
                $update['sent_at'] = now();
            }

            DB::table('email_outbox')
                ->where('id', $row->id)
                ->where('status', 'pending')
                ->update($update);

            $sent++;
        }

        $this->info("Sent {$sent}, skipped {$skipped}.");
        return Command::SUCCESS;
    }

    private function decodePayload($raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || $raw === '') return [];

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function outboxSendEnabled(): bool
    {
        $raw = \App\Support\RuntimeConfig::value('EMAIL_OUTBOX_SEND', '0');
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveRecipientEmail(object $row, array $payload): string
    {
        $candidates = [
            $row->to_email ?? null,
            $row->email ?? null,
            $payload['to_email'] ?? null,
            $payload['email'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveTemplateKey(object $row, array $payload): string
    {
        $candidates = [
            $row->template_key ?? null,
            $row->template ?? null,
            $payload['template_key'] ?? null,
            $payload['template'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if ($value !== '') {
                return $value;
            }
        }

        return 'report_claim';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveSubject(object $row, array $payload, string $templateKey, string $locale): string
    {
        $candidates = [
            $row->subject ?? null,
            $payload['subject'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        $isZh = $this->languageFromLocale($locale) === 'zh';
        return match ($templateKey) {
            'payment_success' => $isZh ? '支付成功与报告交付通知' : 'Payment successful and report delivered',
            'refund_notice' => $isZh ? '退款处理通知' : 'Refund processing notice',
            'support_contact' => $isZh ? '客服联系信息' : 'Support contact details',
            'report_claim' => $isZh ? '你的报告链接已准备好' : 'Your report link is ready',
            default => $isZh ? 'FermatMind 通知' : 'FermatMind notification',
        };
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveBodyHtml(object $row, array $payload, string $templateKey, string $locale): string
    {
        $existing = trim((string) ($row->body_html ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $inline = trim((string) ($payload['body_html'] ?? ''));
        if ($inline !== '') {
            return $inline;
        }

        $view = $this->resolveEmailViewName($templateKey, $locale);
        if ($view === '') {
            return '';
        }
        if (!View::exists($view)) {
            return '';
        }

        return trim((string) view($view, $this->buildTemplateData($row, $payload, $locale))->render());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resolveBodyText(array $payload): string
    {
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body !== '') {
            return $body;
        }

        $claimUrl = trim((string) ($payload['claim_url'] ?? ''));
        if ($claimUrl !== '') {
            return "Claim link: {$claimUrl}";
        }

        $reportUrl = trim((string) ($payload['report_url'] ?? ''));
        if ($reportUrl !== '') {
            return "Report link: {$reportUrl}";
        }

        return 'Please contact support@fermatmind.com for assistance.';
    }

    private function resolveEmailViewName(string $templateKey, string $locale): string
    {
        $lang = $this->languageFromLocale($locale);
        $supported = ['payment_success', 'refund_notice', 'support_contact'];
        if (!in_array($templateKey, $supported, true)) {
            return '';
        }

        $view = "emails.{$lang}.{$templateKey}";
        if (View::exists($view)) {
            return $view;
        }

        $fallback = "emails.en.{$templateKey}";
        return View::exists($fallback) ? $fallback : '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildTemplateData(object $row, array $payload, string $locale): array
    {
        $base = rtrim((string) config('app.url', 'http://localhost'), '/');
        $legalUrls = $this->resolveLegalUrls($locale);
        $supportEmail = $this->resolveSupportEmail();

        $reportUrl = trim((string) ($payload['report_url'] ?? ''));
        $claimUrl = trim((string) ($payload['claim_url'] ?? ''));
        if ($reportUrl === '' && $claimUrl !== '') {
            $reportUrl = $claimUrl;
        }

        return [
            'locale' => $locale,
            'orderNo' => trim((string) ($payload['order_no'] ?? $payload['orderNo'] ?? '')),
            'attemptId' => trim((string) ($payload['attempt_id'] ?? '')),
            'productSummary' => trim((string) ($payload['product_summary'] ?? $payload['item_summary'] ?? '')),
            'reportUrl' => $this->absoluteUrl($reportUrl, $base),
            'refundStatus' => trim((string) ($payload['refund_status'] ?? '')),
            'refundEta' => trim((string) ($payload['refund_eta'] ?? '')),
            'supportEmail' => $supportEmail,
            'supportTicketUrl' => $this->absoluteUrl((string) ($payload['support_ticket_url'] ?? ''), $base),
            'privacyUrl' => $legalUrls['privacy'],
            'termsUrl' => $legalUrls['terms'],
            'refundUrl' => $legalUrls['refund'],
            'outboxId' => trim((string) ($row->id ?? '')),
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));
        if ($locale === '') {
            return 'en';
        }

        $lang = strtolower((string) explode('-', $locale)[0]);
        return $lang === 'zh' ? 'zh-CN' : 'en';
    }

    private function languageFromLocale(string $locale): string
    {
        return strtolower((string) explode('-', $this->normalizeLocale($locale))[0]) === 'zh'
            ? 'zh'
            : 'en';
    }

    /**
     * @return array{terms:string,privacy:string,refund:string}
     */
    private function resolveLegalUrls(string $locale): array
    {
        $appUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $global = [
            'terms' => trim((string) config('regions.regions.US.legal_urls.terms', $appUrl . '/terms')),
            'privacy' => trim((string) config('regions.regions.US.legal_urls.privacy', $appUrl . '/privacy')),
            'refund' => trim((string) config('regions.regions.US.legal_urls.refund', $appUrl . '/refund')),
        ];
        $cn = [
            'terms' => trim((string) config('regions.regions.CN_MAINLAND.legal_urls.terms', $appUrl . '/zh/terms')),
            'privacy' => trim((string) config('regions.regions.CN_MAINLAND.legal_urls.privacy', $appUrl . '/zh/privacy')),
            'refund' => trim((string) config('regions.regions.CN_MAINLAND.legal_urls.refund', $appUrl . '/zh/refund')),
        ];

        $target = $this->languageFromLocale($locale) === 'zh' ? $cn : $global;
        return [
            'terms' => $target['terms'] !== '' ? $target['terms'] : $global['terms'],
            'privacy' => $target['privacy'] !== '' ? $target['privacy'] : $global['privacy'],
            'refund' => $target['refund'] !== '' ? $target['refund'] : $global['refund'],
        ];
    }

    private function resolveSupportEmail(): string
    {
        $support = trim((string) config('fap.support_email', ''));
        if ($support !== '') {
            return $support;
        }

        $from = trim((string) config('mail.from.address', ''));
        if ($from !== '') {
            return $from;
        }

        return 'support@fermatmind.com';
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }
}
