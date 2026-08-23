<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\OpsDashboard;

use App\Models\AdminUser;
use App\Policies\SeoIssueWorkflowPolicy;
use Illuminate\Auth\Access\AuthorizationException;
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

    public function __construct(
        private readonly SeoIssueWorkflowPolicy $policy = new SeoIssueWorkflowPolicy,
    ) {}

    /** @return list<string> */
    public static function allowedStatusesFor(string $action): array
    {
        return match ($action) {
            self::ACTION_ASSIGN, self::ACTION_FIXED => ['open', 'in_progress'],
            self::ACTION_VERIFY => ['resolved'],
            self::ACTION_IGNORE => ['open', 'in_progress', 'resolved'],
            self::ACTION_REOPEN => ['ignored', 'resolved', 'closed'],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    public function transition(
        string $issueUid,
        string $action,
        AdminUser $actor,
        int $expectedLockVersion,
        ?string $operatorNote = null,
        ?string $ignoreReason = null,
        ?string $ignoredUntil = null,
        ?string $verificationNote = null,
    ): array {
        if (self::allowedStatusesFor($action) === []) {
            throw ValidationException::withMessages(['workflowAction' => 'Unsupported SEO issue workflow action.']);
        }
        if (trim($issueUid) === '') {
            throw ValidationException::withMessages(['selectedIssueUid' => 'Select an SEO issue first.']);
        }
        if ($expectedLockVersion < 0) {
            throw ValidationException::withMessages(['selectedLockVersion' => 'A valid issue version is required.']);
        }

        return $this->connection()->transaction(function () use ($issueUid, $action, $actor, $expectedLockVersion, $operatorNote, $ignoreReason, $ignoredUntil, $verificationNote): array {
            $row = $this->connection()->table('seo_issue_queue')
                ->where('issue_uid', $issueUid)
                ->lockForUpdate()
                ->first();
            if (! is_object($row)) {
                throw ValidationException::withMessages(['selectedIssueUid' => 'SEO issue no longer exists.']);
            }

            $currentLockVersion = (int) ($row->lock_version ?? 0);
            if ($currentLockVersion !== $expectedLockVersion) {
                throw ValidationException::withMessages(['selectedLockVersion' => 'SEO issue changed. Refresh and retry.']);
            }

            $fromStatus = $this->normalizeStatus((string) ($row->status ?? 'open'));
            if (! $this->policy->transition($actor, $action, $fromStatus)) {
                throw new AuthorizationException('SEO issue workflow transition is not authorized.');
            }

            $now = now();
            $updates = ['updated_at' => $now, 'lock_version' => $currentLockVersion + 1];
            $note = trim((string) $operatorNote);
            if ($note !== '') {
                $updates['operator_note'] = $note;
            }

            if ($action === self::ACTION_ASSIGN) {
                $updates += [
                    'status' => 'in_progress',
                    'lifecycle_state' => 'acknowledged',
                    'owner_admin_user_id' => (int) $actor->getKey(),
                    'sla_due_at' => $row->sla_due_at ?? $this->slaDueAt((string) ($row->severity ?? 'low'), $now),
                    'acknowledged_at' => $row->acknowledged_at ?? $now,
                    'resolved_at' => null,
                    'ignored_at' => null,
                    'ignore_reason' => null,
                    'ignore_until' => null,
                ];
            }

            if ($action === self::ACTION_FIXED) {
                $updates += [
                    'status' => 'resolved',
                    'lifecycle_state' => 'resolved',
                    'owner_admin_user_id' => $row->owner_admin_user_id ?? (int) $actor->getKey(),
                    'acknowledged_at' => $row->acknowledged_at ?? $now,
                    'resolved_at' => $now,
                    'ignored_at' => null,
                    'ignore_reason' => null,
                    'ignore_until' => null,
                ];
            }

            if ($action === self::ACTION_VERIFY) {
                $verification = trim((string) $verificationNote);
                if ($verification === '') {
                    throw ValidationException::withMessages(['verificationNote' => 'A verification note is required.']);
                }
                $updates += [
                    'status' => 'closed',
                    'lifecycle_state' => 'closed',
                    'verified_at' => $now,
                    'verified_by_admin_user_id' => (int) $actor->getKey(),
                    'verification_note' => $verification,
                    'ignored_at' => null,
                    'ignore_reason' => null,
                    'ignore_until' => null,
                ];
            }

            if ($action === self::ACTION_IGNORE) {
                $reason = trim((string) $ignoreReason);
                if ($reason === '') {
                    throw ValidationException::withMessages(['ignoreReason' => 'An ignore reason is required.']);
                }
                if (trim((string) $ignoredUntil) === '') {
                    throw ValidationException::withMessages(['ignoredUntil' => 'A valid ignore expiry is required.']);
                }
                try {
                    $ignoreExpiry = Carbon::parse((string) $ignoredUntil)->endOfDay();
                } catch (Throwable) {
                    throw ValidationException::withMessages(['ignoredUntil' => 'A valid ignore expiry is required.']);
                }
                if (! $ignoreExpiry->isFuture()) {
                    throw ValidationException::withMessages(['ignoredUntil' => 'Ignore expiry must be in the future.']);
                }
                $updates += [
                    'status' => 'ignored',
                    'lifecycle_state' => 'ignored',
                    'ignored_at' => $now,
                    'ignore_reason' => $reason,
                    'ignore_until' => $ignoreExpiry,
                ];
            }

            if ($action === self::ACTION_REOPEN) {
                $updates += $this->reopenUpdates($now);
            }

            $updated = $this->connection()->table('seo_issue_queue')
                ->where('issue_uid', $issueUid)
                ->where('lock_version', $expectedLockVersion)
                ->update($updates);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['selectedLockVersion' => 'SEO issue changed. Refresh and retry.']);
            }

            return [
                'issue_uid' => $issueUid,
                'action' => $action,
                'from_status' => $fromStatus,
                'status' => (string) $updates['status'],
                'actor_admin_user_id' => (int) $actor->getKey(),
                'previous_lock_version' => $currentLockVersion,
                'lock_version' => $currentLockVersion + 1,
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function reopenExpiredIgnores(AdminUser $actor): array
    {
        $candidates = $this->connection()->table('seo_issue_queue')
            ->select(['issue_uid'])
            ->where('status', 'ignored')
            ->whereNotNull('ignore_until')
            ->where('ignore_until', '<=', now())
            ->orderBy('issue_uid')
            ->limit(100)
            ->get();
        $results = [];

        foreach ($candidates as $candidate) {
            if (! $this->policy->transition($actor, self::ACTION_REOPEN, 'ignored')) {
                throw new AuthorizationException('Expired SEO ignore reopening is not authorized.');
            }

            $result = $this->connection()->transaction(function () use ($candidate, $actor): ?array {
                $row = $this->connection()->table('seo_issue_queue')
                    ->where('issue_uid', (string) $candidate->issue_uid)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($row)
                    || (string) ($row->status ?? '') !== 'ignored'
                    || $row->ignore_until === null
                    || Carbon::parse((string) $row->ignore_until)->isFuture()) {
                    return null;
                }

                $lockVersion = (int) ($row->lock_version ?? 0);
                $updates = $this->reopenUpdates(now()) + [
                    'updated_at' => now(),
                    'lock_version' => $lockVersion + 1,
                ];
                $updated = $this->connection()->table('seo_issue_queue')
                    ->where('issue_uid', (string) $row->issue_uid)
                    ->where('lock_version', $lockVersion)
                    ->update($updates);
                if ($updated !== 1) {
                    throw ValidationException::withMessages(['selectedLockVersion' => 'SEO issue changed during expiry reopening.']);
                }

                return [
                    'issue_uid' => (string) $row->issue_uid,
                    'action' => 'ignore_expired_reopen',
                    'from_status' => 'ignored',
                    'status' => 'open',
                    'actor_admin_user_id' => (int) $actor->getKey(),
                    'previous_lock_version' => $lockVersion,
                    'lock_version' => $lockVersion + 1,
                ];
            });

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function connection(): ConnectionInterface
    {
        return app('db')->connection((string) config('seo_intel.connection', 'seo_intel'));
    }

    /** @return array<string, mixed> */
    private function reopenUpdates(Carbon $now): array
    {
        return [
            'status' => 'open',
            'lifecycle_state' => 'open',
            'owner_admin_user_id' => null,
            'sla_due_at' => null,
            'acknowledged_at' => null,
            'resolved_at' => null,
            'ignored_at' => null,
            'ignore_reason' => null,
            'ignore_until' => null,
            'verified_at' => null,
            'verified_by_admin_user_id' => null,
            'verification_note' => null,
            'operator_note' => 'Reopened at '.$now->toAtomString(),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'new' => 'open',
            'assigned' => 'in_progress',
            'fixed' => 'resolved',
            'verified' => 'closed',
            default => $status,
        };
    }

    private function slaDueAt(string $severity, Carbon $now): Carbon
    {
        return match ($severity) {
            'critical' => $now->copy()->addDay(),
            'high' => $now->copy()->addDays(3),
            'warning', 'medium' => $now->copy()->addDays(7),
            default => $now->copy()->addDays(14),
        };
    }
}
