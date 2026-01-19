<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class FapEmailOutboxSend extends Command
{
    protected $signature = 'email:outbox-send {--limit=50}';
    protected $description = 'Send pending email outbox rows using the log mailer.';

    public function handle(): int
    {
        if (!$this->isEnabled()) {
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
            $email = trim((string) ($row->email ?? ''));
            if ($email === '') {
                $skipped++;
                continue;
            }

            $payload = $this->decodePayload($row->payload_json ?? null);
            $subject = (string) ($payload['subject'] ?? 'Your report is ready');
            $claimUrl = (string) ($payload['claim_url'] ?? '');
            $body = (string) ($payload['body'] ?? ($claimUrl !== '' ? "Claim link: {$claimUrl}" : ''));

            try {
                Mail::mailer('log')->raw($body, function ($m) use ($email, $subject) {
                    $m->to($email)->subject($subject);
                });
            } catch (\Throwable $e) {
                $this->warn('Send failed for outbox id=' . (string) ($row->id ?? ''));
                continue;
            }

            $update = [
                'status' => 'sent',
                'updated_at' => now(),
            ];
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

    private function isEnabled(): bool
    {
        $raw = env('EMAIL_OUTBOX_SEND', '0');
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
