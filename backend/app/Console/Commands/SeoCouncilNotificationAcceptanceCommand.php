<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationOutbox;
use App\Services\SeoCouncil\Platform12\Notification\Platform12NotificationPolicyContract;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Console\Command;

final class SeoCouncilNotificationAcceptanceCommand extends Command
{
    protected $signature = 'seo:council-notification-acceptance
        {--operation-ref= : Exact staging deploy run/attempt/SHA reference}
        {--json}';

    protected $description = 'Send one deduplicated, clearly labelled staging-only Council notification acceptance';

    public function handle(
        Platform12RuntimeControl $runtime,
        Platform12NotificationPolicyContract $policy,
        Platform12NotificationOutbox $outbox,
    ): int {
        $operation = (string) $this->option('operation-ref');
        $status = $runtime->status();
        if (! app()->environment('staging')
            || ! ($status['controlled_acceptance_enabled'] ?? false)
            || ! hash_equals((string) ($status['operation_ref'] ?? ''), $operation)) {
            $this->line('{"status":"ACCEPTANCE_AUTHORITY_DENIED","business_write_enabled":false}');

            return self::FAILURE;
        }

        $subject = hash('sha256', 'seo-council-staging-acceptance|'.$operation);
        $classification = $policy->evaluate([
            'event_type' => 'STAGING_ACCEPTANCE',
            'severity' => 'INFO',
            'subject_hash' => $subject,
            'evidence_refs' => [['id' => 'staging:a08:controlled-acceptance', 'hash' => $subject]],
            'policy_revision' => $policy->reference()['hash'],
            'state' => 'ACTIVE',
            'expires_at' => now('UTC')->addHour()->format('Y-m-d\TH:i:s\Z'),
            'decision_metrics' => null,
        ]);
        $enqueued = $outbox->enqueue($classification, 'active', 'DAILY_MISSION_READY');
        if (($enqueued['status'] ?? null) === 'suppressed') {
            $this->line(json_encode(['status' => 'DEDUPLICATED', 'notification_id' => $enqueued['notification_id'],
                'business_write_enabled' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $notificationId = $enqueued['notification_id'] ?? null;
        $claim = is_string($notificationId)
            ? $outbox->claim('staging-a08:'.substr($subject, 0, 16), notificationId: $notificationId)
            : ['status' => 'CLAIM_FAILED', 'claim' => null];
        $result = is_array($claim['claim'] ?? null)
            ? $outbox->dispatch($claim['claim'], 'DAILY_MISSION_READY')
            : ['status' => 'failed', 'reason_code' => $claim['status'] ?? 'CLAIM_FAILED'];
        $this->line(json_encode([...$result, 'notification_id' => $enqueued['notification_id'] ?? null,
            'business_write_enabled' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return ($result['status'] ?? null) === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
