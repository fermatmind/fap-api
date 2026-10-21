<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Services\Attempts\AttemptProgressService;
use App\Services\Commerce\MembershipService;
use Illuminate\Support\Facades\DB;

final class AccountAssetLinker
{
    private const SCALES = ['MBTI', 'BIG5_OCEAN', 'ENNEAGRAM', 'RIASEC', 'EQ_60', 'IQ_RAVEN', 'IQ_INTELLIGENCE_QUOTIENT'];

    public function __construct(private readonly AttemptProgressService $progress) {}

    /** A resume proof claims one result, never every asset sharing an anon id. */
    public function linkAttempts(string $userId, array $proofs): array
    {
        return DB::transaction(function () use ($userId, $proofs): array {
            abort_unless(DB::table('users')->where('id', $userId)->lockForUpdate()->exists(), 401);
            usort($proofs, fn (array $a, array $b): int => strcmp($a['attempt_id'], $b['attempt_id']));
            $linked = [];
            foreach ($proofs as $proof) {
                $attempt = DB::table('attempts')->where('org_id', 0)->where('id', $proof['attempt_id'])->lockForUpdate()->first();
                abort_if(! $attempt || ! in_array($attempt->scale_code, self::SCALES, true), 404, 'Result not found.');
                abort_if(! $this->progress->hasValidResumeToken($attempt->id, $proof['resume_token']), 403, 'A valid result ownership proof is required.');
                $this->claimResult($userId, $attempt);
                $linked[] = $attempt->id;
            }

            return ['linked_attempt_ids' => $linked];
        });
    }

    /** Email ownership is separately confirmed with a user-bound one-time challenge. */
    public function linkEmail(string $userId, string $emailHash): array
    {
        return DB::transaction(function () use ($userId, $emailHash): array {
            abort_unless(DB::table('users')->where('id', $userId)->lockForUpdate()->exists(), 401);
            $bindings = DB::table('attempt_email_bindings')->where('org_id', 0)
                ->where('email_hash', $emailHash)->where('status', 'active')->lockForUpdate()->get();
            $linked = [];
            foreach ($bindings as $binding) {
                abort_if($binding->bound_user_id !== null && (string) $binding->bound_user_id !== $userId, 409, 'This result belongs to another account.');
                $attempt = DB::table('attempts')->where('org_id', 0)->where('id', $binding->attempt_id)->lockForUpdate()->first();
                if ($attempt && in_array($attempt->scale_code, self::SCALES, true)) {
                    $this->claimResult($userId, $attempt);
                    $linked[] = $attempt->id;
                }
            }

            // A report beneficiary is not necessarily the purchaser (for example, gifts).
            $orders = DB::table('orders')->where('org_id', 0)->where('contact_email_hash', $emailHash)->lockForUpdate()->get();
            foreach ($orders as $order) {
                abort_if($order->user_id !== null && (string) $order->user_id !== $userId, 409, 'This order belongs to another account.');
                DB::table('orders')->where('id', $order->id)->where('org_id', 0)->update(['user_id' => $userId, 'updated_at' => now()]);
                // Keep the original beneficiary, benefit_ref, ledger IDs, statuses and expiries.
                DB::table('benefit_grants')->where('org_id', 0)->where('order_no', $order->order_no)
                    ->whereNull('attempt_id')->where('benefit_ref', (string) $order->anon_id)
                    ->where(function ($q) use ($order): void {
                        $q->whereNull('user_id')->orWhere('user_id', (string) $order->anon_id);
                    })
                    ->update(['user_id' => $userId, 'updated_at' => now()]);
            }

            foreach ($orders->pluck('anon_id')->filter()->unique() as $anonId) {
                app(MembershipService::class)->linkEarnedMembershipForVerifiedOrders(0, $userId, (string) $anonId);
            }

            return ['linked_attempt_ids' => array_values(array_unique($linked)), 'linked_orders' => $orders->count()];
        });
    }

    private function claimResult(string $userId, object $attempt): void
    {
        abort_if($attempt->user_id !== null && (string) $attempt->user_id !== $userId, 409, 'This result belongs to another account.');
        abort_if(DB::table('attempt_email_bindings')->where('org_id', 0)->where('attempt_id', $attempt->id)
            ->whereNotNull('bound_user_id')->where('bound_user_id', '!=', $userId)->lockForUpdate()->exists(), 409, 'This result is bound to another account.');
        DB::table('attempts')->where('org_id', 0)->where('id', $attempt->id)
            ->update(['user_id' => $userId, 'updated_at' => now()]);
        DB::table('attempt_email_bindings')->where('org_id', 0)->where('attempt_id', $attempt->id)
            ->where(function ($q) use ($userId): void {
                $q->whereNull('bound_user_id')->orWhere('bound_user_id', $userId);
            })
            ->update(['bound_user_id' => $userId, 'updated_at' => now()]);
        DB::table('benefit_grants')->where('org_id', 0)->where('attempt_id', $attempt->id)
            ->where('benefit_ref', (string) $attempt->anon_id)
            ->where(function ($q) use ($attempt): void {
                $q->whereNull('user_id')->orWhere('user_id', (string) $attempt->anon_id);
            })
            ->update(['user_id' => $userId, 'updated_at' => now()]);
    }
}
