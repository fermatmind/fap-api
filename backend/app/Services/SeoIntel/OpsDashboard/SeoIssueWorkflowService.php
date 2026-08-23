<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SeoIssueWorkflowService
{
    public const ACTION_ASSIGN = 'assign';

    public const ACTION_FIXED = 'fixed';

    public const ACTION_VERIFY = 'verify';

    public const ACTION_IGNORE = 'ignore';

    public const ACTION_REOPEN = 'reopen';

    /**
     * @return array<string, mixed>
     */
    public function transition(
        string $issueUid,
        string $action,
        string $owner,
        ?string $ignoreReason = null,
        ?string $ignoredUntil = null,
    ): array {
        if (! in_array($action, [self::ACTION_ASSIGN, self::ACTION_FIXED, self::ACTION_VERIFY, self::ACTION_IGNORE, self::ACTION_REOPEN], true)) {
            throw ValidationException::withMessages(['workflowAction' => 'Unsupported SEO issue workflow action.']);
        }

        if (trim($issueUid) === '') {
            throw ValidationException::withMessages(['selectedIssueUid' => 'Select an SEO issue first.']);
        }

        return $this->connection()->transaction(function () use ($issueUid, $action, $owner, $ignoreReason, $ignoredUntil): array {
            $row = $this->connection()->table('seo_issue_queue')
                ->where('issue_uid', $issueUid)
                ->lockForUpdate()
                ->first();

            if (! is_object($row)) {
                throw ValidationException::withMessages(['selectedIssueUid' => 'SEO issue no longer exists.']);
            }

            $metadata = $this->decodeMetadata($row->metadata_json ?? null);
            $workflow = is_array($metadata['ops_workflow'] ?? null) ? $metadata['ops_workflow'] : [];
            $now = now();
            $owner = trim($owner) !== '' ? trim($owner) : 'operator';
            $updates = ['updated_at' => $now];

            if ($action === self::ACTION_ASSIGN) {
                $updates += [
                    'status' => 'assigned',
                    'lifecycle_state' => 'acknowledged',
                    'acknowledged_at' => $row->acknowledged_at ?? $now,
                    'resolved_at' => null,
                    'ignored_at' => null,
                ];
                $workflow['owner'] = $owner;
                $workflow['assigned_at'] = $now->toAtomString();
                $workflow['sla_due_at'] = $this->slaDueAt((string) ($row->severity ?? 'info'), $now)->toAtomString();
            }

            if ($action === self::ACTION_FIXED) {
                $updates += [
                    'status' => 'fixed',
                    'lifecycle_state' => 'acknowledged',
                    'acknowledged_at' => $row->acknowledged_at ?? $now,
                    'resolved_at' => null,
                    'ignored_at' => null,
                ];
                $workflow['owner'] = $workflow['owner'] ?? $owner;
                $workflow['fixed_at'] = $now->toAtomString();
                $workflow['verification_result'] = 'pending_recheck';
            }

            if ($action === self::ACTION_VERIFY) {
                if ((string) ($row->status ?? '') !== 'fixed') {
                    throw ValidationException::withMessages(['workflowAction' => 'Only a fixed issue can be verified.']);
                }
                $updates += [
                    'status' => 'verified',
                    'lifecycle_state' => 'resolved',
                    'resolved_at' => $now,
                    'ignored_at' => null,
                ];
                $workflow['owner'] = $workflow['owner'] ?? $owner;
                $workflow['verified_at'] = $now->toAtomString();
                $workflow['verification_result'] = 'passed_by_operator';
            }

            if ($action === self::ACTION_IGNORE) {
                $reason = trim((string) $ignoreReason);
                if ($reason === '') {
                    throw ValidationException::withMessages(['ignoreReason' => 'An ignore reason is required.']);
                }
                try {
                    $ignoreExpiry = Carbon::parse((string) $ignoredUntil)->endOfDay();
                } catch (Throwable) {
                    throw ValidationException::withMessages(['ignoredUntil' => 'A valid ignore expiry is required.']);
                }
                if ($ignoreExpiry->isPast()) {
                    throw ValidationException::withMessages(['ignoredUntil' => 'Ignore expiry must be in the future.']);
                }
                $updates += [
                    'status' => 'ignored',
                    'lifecycle_state' => 'ignored',
                    'ignored_at' => $now,
                    'resolved_at' => null,
                ];
                $workflow['owner'] = $workflow['owner'] ?? $owner;
                $workflow['ignore_reason'] = $reason;
                $workflow['ignored_until'] = $ignoreExpiry->toAtomString();
            }

            if ($action === self::ACTION_REOPEN) {
                $updates += [
                    'status' => 'new',
                    'lifecycle_state' => 'open',
                    'acknowledged_at' => null,
                    'resolved_at' => null,
                    'ignored_at' => null,
                ];
                $workflow['verification_result'] = 'reopened';
                $workflow['reopened_at'] = $now->toAtomString();
                unset($workflow['ignored_until'], $workflow['ignore_reason'], $workflow['verified_at']);
            }

            $workflow['last_action'] = $action;
            $workflow['last_action_at'] = $now->toAtomString();
            $metadata['ops_workflow'] = $workflow;
            $updates['metadata_json'] = json_encode($metadata, JSON_THROW_ON_ERROR);

            $this->connection()->table('seo_issue_queue')
                ->where('issue_uid', $issueUid)
                ->update($updates);

            return ['issue_uid' => $issueUid, 'action' => $action, 'status' => $updates['status']];
        });
    }

    private function connection(): ConnectionInterface
    {
        return app('db')->connection((string) config('seo_intel.connection', 'seo_intel'));
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) && trim($value) !== '' ? json_decode($value, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    private function slaDueAt(string $severity, Carbon $now): Carbon
    {
        return match ($severity) {
            'critical' => $now->copy()->addHours(4),
            'high' => $now->copy()->addDay(),
            'warning' => $now->copy()->addDays(3),
            default => $now->copy()->addDays(7),
        };
    }
}
