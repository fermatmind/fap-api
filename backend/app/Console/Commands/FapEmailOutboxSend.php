<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Email\EmailOutboxService;
use App\Support\RuntimeConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class FapEmailOutboxSend extends Command
{
    protected $signature = 'email:outbox-send {--limit=50}';

    protected $description = 'Send pending email outbox rows using the configured mailer.';

    public function __construct(
        private readonly EmailOutboxService $emailOutbox,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->outboxSendEnabled()) {
            $this->info('EMAIL_OUTBOX_SEND=0 (disabled)');

            return Command::SUCCESS;
        }

        if (! Schema::hasTable('email_outbox')) {
            $this->warn('email_outbox table missing');

            return Command::SUCCESS;
        }

        $limit = $this->normalizeLimit((int) $this->option('limit'));
        $mailer = $this->resolveMailer();
        if (! $this->canUseMailer($mailer)) {
            $this->warn("Configured mailer [{$mailer}] is not safe for this environment.");

            return Command::SUCCESS;
        }

        $result = $this->emailOutbox->sendPending($limit, $mailer);
        if (($result['processed'] ?? 0) < 1) {
            $this->info('No pending outbox rows.');

            return Command::SUCCESS;
        }

        $this->info(sprintf(
            'Mailer %s: sent %d, blocked %d, failed %d.',
            (string) ($result['mailer'] ?? $mailer),
            (int) ($result['sent'] ?? 0),
            (int) ($result['blocked'] ?? 0),
            (int) ($result['failed'] ?? 0),
        ));

        return Command::SUCCESS;
    }

    private function outboxSendEnabled(): bool
    {
        $raw = RuntimeConfig::value('EMAIL_OUTBOX_SEND', '0');

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private function normalizeLimit(int $limit): int
    {
        if ($limit <= 0) {
            return 50;
        }

        return min($limit, 500);
    }

    private function resolveMailer(): string
    {
        $configured = trim((string) config('mail.default', 'log'));
        if ($configured === '') {
            $configured = 'log';
        }

        if (app()->environment('testing') && $this->isKnownExternalMailer($configured)) {
            return 'array';
        }

        return $configured;
    }

    private function canUseMailer(string $mailer): bool
    {
        $mailers = config('mail.mailers', []);
        if (! is_array($mailers) || ! array_key_exists($mailer, $mailers)) {
            return false;
        }

        if (app()->environment('testing') && $this->isKnownExternalMailer($mailer)) {
            return false;
        }

        return true;
    }

    private function isKnownExternalMailer(string $mailer): bool
    {
        $transport = trim((string) config("mail.mailers.{$mailer}.transport", ''));

        return in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail', 'failover', 'roundrobin'], true);
    }
}
