<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class SchedulerEvidenceMonitorService
{
    public const CONTRACT_VERSION = 'scheduler-evidence-monitor.v1';

    private const HEARTBEAT_MAX_AGE_SECONDS = 180;

    private const WEEKLY_GRACE_MINUTES = 15;

    public function __construct(
        private readonly SchedulerHeartbeatService $heartbeat,
        private readonly string $connection = 'seo_intel',
        private readonly ?string $statePath = null,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(bool $notify = false, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $heartbeat = $this->heartbeat->check(self::HEARTBEAT_MAX_AGE_SECONDS, $now);
        $weekly = $this->evaluateWeekly($now);
        $alerts = $this->updateAlertState($heartbeat, $weekly, $notify);
        $healthy = ($heartbeat['ok'] ?? false) === true
            && in_array($weekly['state'] ?? null, ['not_due', 'healthy'], true);

        return [
            'schema_version' => self::CONTRACT_VERSION,
            'status' => $healthy ? 'pass' : 'fail',
            'observed_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'heartbeat' => $heartbeat,
            'weekly' => $weekly,
            'alerts' => $alerts,
            'read_only' => true,
            'deployment_action' => false,
            'lkg_action' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function evaluateWeekly(CarbonImmutable $now): array
    {
        $slot = SeoWeeklyDecisionReceiptService::naturalSlotForWeek($now);
        $dueAt = $slot->addMinutes(self::WEEKLY_GRACE_MINUTES);
        $isoWeek = $slot->format('o-\WW');
        $capabilityRevision = SeoWeeklyDecisionReceiptService::capabilityRevision();
        $base = [
            'state' => 'not_due',
            'reason' => 'grace_window_open',
            'iso_week' => $isoWeek,
            'due_at' => $dueAt->format('Y-m-d\TH:i:s\Z'),
            'capability_revision' => $capabilityRevision,
            'scheduler_contract_revision_alias' => $capabilityRevision,
            'manual_receipts_excluded' => true,
        ];
        if ($now->lessThan($dueAt)) {
            return $base;
        }

        try {
            $schema = Schema::connection($this->connection);
            if (! $schema->hasTable('seo_weekly_decision_capability_receipts')
                || ! $schema->hasTable('seo_weekly_decision_receipts')) {
                return $this->weeklyFailure($base, 'receipt_store_unavailable');
            }
            $row = DB::connection($this->connection)->table('seo_weekly_decision_capability_receipts')
                ->where('iso_week', $isoWeek)
                ->where('capability_revision', $capabilityRevision)
                ->orderByDesc('scheduled_for')
                ->first();
            if ($row === null) {
                return $this->weeklyFailure($base, 'weekly_receipt_missing');
            }

            $receiptJson = (string) ($row->receipt_json ?? '');
            $receipt = json_decode($receiptJson, true, 32, JSON_THROW_ON_ERROR);
            $selectionRevision = (string) ($receipt['selection_revision'] ?? '');
            $selectionRow = DB::connection($this->connection)->table('seo_weekly_decision_receipts')
                ->where('selection_revision', $selectionRevision)
                ->first();
            $selectionJson = (string) ($selectionRow->receipt_json ?? '');
            $selectionReceipt = json_decode($selectionJson, true, 32, JSON_THROW_ON_ERROR);
            $scheduledFor = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s\Z',
                (string) ($receipt['scheduled_for'] ?? ''),
                'UTC',
            );

            if (! is_array($receipt)
                || ! is_array($selectionReceipt)
                || $scheduledFor === false
                || ! hash_equals((string) ($row->receipt_hash ?? ''), hash('sha256', $receiptJson))
                || ! hash_equals((string) ($selectionRow->receipt_hash ?? ''), hash('sha256', $selectionJson))
                || ($receipt['schema_version'] ?? null) !== SeoWeeklyDecisionReceiptService::CONTRACT_VERSION
                || ($receipt['trigger'] ?? null) !== 'scheduled'
                || ($receipt['manual_receipts_excluded'] ?? null) !== true
                || (string) ($receipt['iso_week'] ?? '') !== $isoWeek
                || ! hash_equals($capabilityRevision, (string) ($row->capability_revision ?? ''))
                || ! hash_equals($capabilityRevision, (string) ($receipt['capability_revision'] ?? ''))
                || $scheduledFor->getTimestamp() !== $slot->getTimestamp()
                || preg_match('/\A[a-f0-9]{64}\z/', $selectionRevision) !== 1
                || ! hash_equals((string) ($row->selection_revision ?? ''), $selectionRevision)
                || ! hash_equals((string) ($selectionReceipt['selection_revision'] ?? ''), $selectionRevision)
                || ($selectionReceipt['trigger'] ?? null) !== 'scheduled'
                || ($selectionReceipt['manual_receipts_excluded'] ?? null) !== true) {
                return $this->weeklyFailure($base, 'receipt_contract_mismatch');
            }

            return array_merge($base, [
                'state' => 'healthy',
                'reason' => 'natural_receipt_verified',
                'scheduled_for' => $scheduledFor->format('Y-m-d\TH:i:s\Z'),
                'selection_revision' => $selectionRevision,
                'receipt_hash' => (string) $row->receipt_hash,
                'evidence_release_sha' => (string) ($receipt['release_sha'] ?? ''),
                'trigger' => 'scheduled',
            ]);
        } catch (Throwable) {
            return $this->weeklyFailure($base, 'receipt_read_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function weeklyFailure(array $base, string $reason): array
    {
        return array_merge($base, ['state' => 'failed', 'reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $heartbeat
     * @param  array<string, mixed>  $weekly
     * @return array<string, mixed>
     */
    private function updateAlertState(array $heartbeat, array $weekly, bool $notify): array
    {
        if (! $notify) {
            return [
                'heartbeat_transition' => 'none',
                'weekly_alert_sent' => false,
                'notification_requested' => false,
            ];
        }

        $state = $this->readState();
        $previousHeartbeat = is_string($state['heartbeat_status'] ?? null)
            ? $state['heartbeat_status']
            : 'healthy';
        $currentHeartbeat = ($heartbeat['ok'] ?? false) === true ? 'healthy' : 'failed';
        $heartbeatTransition = 'none';
        if ($previousHeartbeat === 'healthy' && $currentHeartbeat === 'failed') {
            $heartbeatTransition = 'failed';
            $this->sendAlert('[Scheduler evidence alert] heartbeat failed reason='.(string) ($heartbeat['reason'] ?? 'unknown'));
        } elseif ($previousHeartbeat === 'failed' && $currentHeartbeat === 'healthy') {
            $heartbeatTransition = 'recovered';
            $this->sendAlert('[Scheduler evidence recovery] heartbeat healthy');
        }

        $weeklyAlertKeys = array_values(array_filter(
            (array) ($state['weekly_alert_keys'] ?? []),
            static fn (mixed $value): bool => is_string($value),
        ));
        $weeklyAlertSent = false;
        if (($weekly['state'] ?? null) === 'failed') {
            $key = (string) ($weekly['iso_week'] ?? 'unknown').'|'.(string) ($weekly['capability_revision'] ?? 'unknown');
            if (! in_array($key, $weeklyAlertKeys, true)) {
                $weeklyAlertKeys[] = $key;
                $weeklyAlertSent = true;
                $this->sendAlert('[Scheduler evidence alert] weekly receipt failed iso_week='.(string) ($weekly['iso_week'] ?? 'unknown').' reason='.(string) ($weekly['reason'] ?? 'unknown'));
            }
        }

        $this->writeState([
            'schema_version' => self::CONTRACT_VERSION,
            'heartbeat_status' => $currentHeartbeat,
            'weekly_alert_keys' => array_slice($weeklyAlertKeys, -16),
        ]);

        return [
            'heartbeat_transition' => $heartbeatTransition,
            'weekly_alert_sent' => $weeklyAlertSent,
            'notification_requested' => true,
        ];
    }

    private function sendAlert(string $message): void
    {
        try {
            OpsAlertService::send($message);
        } catch (Throwable) {
            // Monitoring verdict remains evidence-driven if the alert transport is unavailable.
        }
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $path = $this->alertStatePath();
        if (! is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return [];
        }
        try {
            $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $path = $this->alertStatePath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Scheduler evidence alert state is unavailable.');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $bytes = file_put_contents(
            $temporary,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
        if (! is_int($bytes) || $bytes < 1 || ! chmod($temporary, 0660) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Scheduler evidence alert state atomic write failed.');
        }
    }

    private function alertStatePath(): string
    {
        return $this->statePath ?? storage_path('app/ops/scheduler-evidence-monitor-state.json');
    }
}
