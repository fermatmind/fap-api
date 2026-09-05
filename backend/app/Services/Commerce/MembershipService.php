<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class MembershipService
{
    /** @return array<string,mixed> */
    public function status(int $orgId, ?string $userId, ?string $anonId): array
    {
        $paidReportCount = $this->eligibleReportOrders($orgId, $userId, $anonId)->count();
        $this->revokeUnearnedAnnual($orgId, $userId, $anonId, $paidReportCount);
        $this->grantEarnedAnnual($orgId, $userId, $anonId, $paidReportCount);
        $active = $this->activeGrant($orgId, $userId, $anonId);
        $creditUnit = (int) config('membership.report_credit_unit_cents', 199);
        $paidReportCents = $paidReportCount * $creditUnit;

        $plans = [];
        foreach ((array) config('membership.plans', []) as $planId => $plan) {
            if (! is_array($plan)) {
                continue;
            }
            $listPrice = (int) ($plan['list_price_cents'] ?? 0);
            $activePlan = $active !== null ? (string) ($this->meta($active->meta_json ?? null)['membership_plan'] ?? '') : '';
            $isLifetime = (string) $planId === 'lifetime';
            $isAnnualMember = $activePlan === 'annual';
            $isLifetimeMember = $activePlan === 'lifetime';
            $upgrade = $isLifetime && $isAnnualMember;
            $amountDue = $upgrade ? (int) ($plan['upgrade_price_cents'] ?? 999) : $listPrice;
            $available = ! $isLifetimeMember && ($isLifetime || $active === null);
            if ($isLifetime && ! $this->lifetimeInventoryAvailable($orgId)) {
                $available = false;
            }
            $plans[(string) $planId] = [
                'id' => (string) $planId,
                'title' => (string) ($plan['title'] ?? $planId),
                'list_price_cents' => $listPrice,
                'eligible_credit_cents' => $isAnnualMember ? 1000 : 0,
                'amount_due_cents' => $available ? $amountDue : 0,
                'display_price' => $this->displayPrice($available ? $amountDue : 0),
                'upgrade' => $upgrade,
                'available' => $available,
                'sku' => (string) ($upgrade ? ($plan['upgrade_sku'] ?? '') : ($plan['full_sku'] ?? '')),
                'quantity' => 1,
                'inventory_limit' => (int) ($plan['inventory_limit'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'membership' => [
                'active' => $active !== null,
                'plan_id' => $active !== null ? (string) ($this->meta($active->meta_json ?? null)['membership_plan'] ?? '') : '',
                'expires_at' => $active?->expires_at,
            ],
            'credit' => [
                'paid_report_count' => $paidReportCount,
                'paid_report_cents' => $paidReportCents,
            ],
            'plans' => array_values($plans),
        ];
    }

    /** @return array<string,mixed>|null */
    public function offer(int $orgId, ?string $userId, ?string $anonId, string $planId): ?array
    {
        $status = $this->status($orgId, $userId, $anonId);
        foreach ((array) ($status['plans'] ?? []) as $plan) {
            if (is_array($plan) && ($plan['id'] ?? '') === $planId && ($plan['available'] ?? false) === true) {
                return $plan;
            }
        }

        return null;
    }

    public function hasActiveMembership(int $orgId, ?string $userId, ?string $anonId): bool
    {
        $count = $this->eligibleReportOrders($orgId, $userId, $anonId)->count();
        $this->revokeUnearnedAnnual($orgId, $userId, $anonId, $count);
        $this->grantEarnedAnnual($orgId, $userId, $anonId, $count);

        return $this->activeGrant($orgId, $userId, $anonId) !== null;
    }

    private function grantEarnedAnnual(int $orgId, ?string $userId, ?string $anonId, int $paidReportCount): void
    {
        if ($paidReportCount < (int) config('membership.automatic_annual_report_count', 5)
            || $this->activeGrant($orgId, $userId, $anonId) !== null) {
            return;
        }
        $userId = trim((string) $userId);
        $anonId = trim((string) $anonId);
        $actorRef = $anonId !== '' ? $anonId : $userId;
        if ($actorRef === '') {
            return;
        }
        DB::transaction(function () use ($orgId, $userId, $anonId, $actorRef, $paidReportCount): void {
            if ($this->activeGrant($orgId, $userId, $anonId) !== null) {
                return;
            }
            $sourceOrderId = (string) Uuid::uuid5(
                '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                implode('|', ['five-paid-reports', $orgId, $actorRef])
            );
            $existing = DB::table('benefit_grants')
                ->where('source_order_id', $sourceOrderId)
                ->where('benefit_type', 'membership')
                ->where('benefit_ref', $actorRef)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (strtolower(trim((string) ($existing->status ?? ''))) === 'revoked') {
                    DB::table('benefit_grants')->where('id', $existing->id)->update([
                        'status' => 'active',
                        'revoked_at' => null,
                        'expires_at' => now()->addDays(365),
                        'updated_at' => now(),
                    ]);
                }

                return;
            }
            DB::table('benefit_grants')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'user_id' => $userId !== '' ? $userId : $actorRef,
                'benefit_code' => strtoupper((string) config('membership.benefit_code', 'FERMAT_MEMBER')),
                'scope' => 'org',
                'attempt_id' => null,
                'order_no' => null,
                'status' => 'active',
                'expires_at' => now()->addDays(365),
                'benefit_ref' => $actorRef,
                'benefit_type' => 'membership',
                'source_order_id' => $sourceOrderId,
                'source_event_id' => null,
                'meta_json' => json_encode([
                    'membership_plan' => 'annual',
                    'granted_via' => 'five_paid_reports',
                    'paid_report_count' => $paidReportCount,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function revokeUnearnedAnnual(int $orgId, ?string $userId, ?string $anonId, int $paidReportCount): void
    {
        if ($paidReportCount >= (int) config('membership.automatic_annual_report_count', 5)) {
            return;
        }
        $query = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', strtoupper((string) config('membership.benefit_code', 'FERMAT_MEMBER')))
            ->where('status', 'active')
            ->where('scope', 'org')
            ->whereNull('order_no')
            ->where('meta_json->granted_via', 'five_paid_reports');
        $this->grantActor($query, $userId, $anonId);
        $query->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
    }

    private function activeGrant(int $orgId, ?string $userId, ?string $anonId): ?object
    {
        $query = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', strtoupper((string) config('membership.benefit_code', 'FERMAT_MEMBER')))
            ->where('scope', 'org')
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        $this->grantActor($query, $userId, $anonId);

        return $query->orderByRaw('expires_at IS NULL DESC')->orderByDesc('expires_at')->first();
    }

    private function eligibleReportOrders(int $orgId, ?string $userId, ?string $anonId): Builder
    {
        $query = DB::table('orders')
            ->where('org_id', $orgId)
            ->whereIn('provider', ['wechat_mini_virtual', 'apple_iap'])
            ->whereIn('sku', array_map('strtoupper', (array) config('membership.report_credit_skus', [])))
            ->where('amount_cents', (int) config('membership.report_credit_unit_cents', 199))
            ->where('payment_state', Order::PAYMENT_STATE_PAID)
            ->where('grant_state', Order::GRANT_STATE_GRANTED)
            ->where('amount_refunded', 0)
            ->whereNull('refunded_at');
        $this->actor($query, $userId, $anonId);

        return $query;
    }

    private function actor(Builder $query, ?string $userId, ?string $anonId): void
    {
        $userId = trim((string) $userId);
        $anonId = trim((string) $anonId);
        $query->where(function (Builder $actor) use ($userId, $anonId): void {
            if ($userId !== '') {
                $actor->where('user_id', $userId);
                if ($anonId !== '') {
                    $actor->orWhere('anon_id', $anonId);
                }
            } elseif ($anonId !== '') {
                $actor->where('anon_id', $anonId);
            } else {
                $actor->whereRaw('1 = 0');
            }
        });
    }

    private function grantActor(Builder $query, ?string $userId, ?string $anonId): void
    {
        $userId = trim((string) $userId);
        $anonId = trim((string) $anonId);
        $query->where(function (Builder $actor) use ($userId, $anonId): void {
            if ($userId !== '') {
                $actor->where('user_id', $userId);
                if ($anonId !== '') {
                    $actor->orWhere('benefit_ref', $anonId);
                }
            } elseif ($anonId !== '') {
                $actor->where('benefit_ref', $anonId);
            } else {
                $actor->whereRaw('1 = 0');
            }
        });
    }

    /** @return array<string,mixed> */
    private function meta(mixed $value): array
    {
        $decoded = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);

        return is_array($decoded) ? $decoded : [];
    }

    private function displayPrice(int $cents): string
    {
        return '¥'.number_format($cents / 100, 2, '.', '');
    }

    private function lifetimeInventoryAvailable(int $orgId): bool
    {
        $limit = (int) config('membership.plans.lifetime.inventory_limit', 10000);
        if ($limit <= 0) {
            return true;
        }

        return DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('benefit_code', strtoupper((string) config('membership.benefit_code', 'FERMAT_MEMBER')))
            ->where('status', 'active')
            ->whereNull('expires_at')
            ->count() < $limit;
    }
}
