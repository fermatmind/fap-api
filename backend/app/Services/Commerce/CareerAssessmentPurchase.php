<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Attempt;
use App\Models\Order;
use App\Services\Payments\PaymentProviderRegistry;
use Illuminate\Support\Facades\DB;

final class CareerAssessmentPurchase
{
    public const SCALE = 'CAREER94';

    public const BENEFIT = 'CAREER94_REPORT';

    public const PRICE = 299;

    public const SKUS = ['CNY' => 'CAREER94_REPORT_CNY_299', 'USD' => 'CAREER94_REPORT_USD_299'];

    public function hasAccess(string $attemptId, string $userId): bool
    {
        return DB::table('benefit_grants as grants')->join('orders', 'orders.order_no', '=', 'grants.order_no')
            ->where('grants.org_id', 0)->where('grants.attempt_id', $attemptId)->where('grants.user_id', $userId)
            ->where('grants.benefit_code', CareerAssessmentPurchase::BENEFIT)->where('grants.scope', 'attempt')
            ->where('grants.status', 'active')
            ->where(fn ($query) => $query->whereNull('grants.expires_at')->orWhere('grants.expires_at', '>', now()))
            ->where('orders.org_id', 0)->where('orders.target_attempt_id', $attemptId)->where('orders.user_id', $userId)
            ->whereIn('orders.sku', CareerAssessmentPurchase::SKUS)->where('orders.payment_state', 'paid')->exists();
    }

    public function offers(string $market): array
    {
        if (! config('payments.career94.enabled')) {
            return [];
        }

        $currency = $market === 'CN_MAINLAND' ? 'CNY' : 'USD';
        $registry = app(PaymentProviderRegistry::class);
        $providers = [];
        if ($registry->isEnabled('waffo') && config('payments.waffo.career94_product_id')) {
            $providers[] = 'waffo';
        }
        if ($currency === 'CNY' && $registry->isEnabled('alipay')
            && filled(config('pay.alipay.default.app_id')) && filled(config('pay.alipay.default.seller_id'))) {
            $providers[] = 'alipay';
        }

        return array_map(static fn (string $provider): array => [
            'provider' => $provider,
            'sku' => self::SKUS[$currency],
            'currency' => $currency,
            'amount_cents' => self::PRICE,
        ], $providers);
    }

    public function latestOrder(string $attemptId): ?object
    {
        return DB::table('orders')->where('org_id', 0)->where('target_attempt_id', $attemptId)
            ->whereIn('sku', array_values(self::SKUS))->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function createOrder(?string $attemptId, int $orgId, ?string $userId, string $sku, string $provider, int $quantity, callable $createRow): array
    {
        return DB::transaction(function () use ($attemptId, $orgId, $userId, $sku, $provider, $quantity, $createRow) {
            $attempt = $userId !== null && $orgId === 0
                ? Attempt::withoutGlobalScopes()->whereKey($attemptId)->where('org_id', 0)
                    ->where('user_id', $userId)->where('scale_code', self::SCALE)->whereNotNull('submitted_at')
                    ->lockForUpdate()->first()
                : null;
            if (! $attempt || $attempt->question_count !== 94 || $attempt->scale_version !== 'career_v1') {
                return ['ok' => false, 'error_code' => 'ATTEMPT_NOT_FOUND', 'message' => 'Career assessment result not found.'];
            }

            $offer = collect($this->offers($attempt->region))->firstWhere('provider', $provider);
            if ($quantity !== 1 || $offer === null || $offer['sku'] !== $sku
                || data_get($attempt->result_json, 'interest_shape.state') !== 'differentiated') {
                return ['ok' => false, 'error_code' => 'PAYMENT_PROVIDER_UNAVAILABLE', 'message' => 'Career report purchase is unavailable.'];
            }

            $existing = $this->latestOrder($attempt->id);
            if ($existing !== null) {
                $state = Order::normalizePaymentState($existing->payment_state, $existing->status);
                if (in_array($state, ['created', 'pending'], true)
                    && $existing->provider === $provider && $existing->sku === $sku
                    && (string) $existing->user_id === $userId) {
                    return ['ok' => true, 'order_no' => $existing->order_no, 'order' => $existing, 'idempotent' => true];
                }
                // A timeout or local failure is not proof that the provider stopped collecting this order.
                if ($state !== 'canceled') {
                    return ['ok' => false, 'error_code' => 'PAYMENT_PROVIDER_UNAVAILABLE', 'message' => 'A career report order already exists for this result.'];
                }
            }

            $row = $createRow();
            unset($row['idempotency_key']);
            $row['anon_id'] = null;
            DB::table('orders')->insert($row);

            return [
                'ok' => true,
                'order_no' => $row['order_no'],
                'order' => DB::table('orders')->where('order_no', $row['order_no'])->first(),
                'idempotent' => false,
            ];
        }, 3);
    }
}
