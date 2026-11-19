<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Attempt;
use App\Models\Order;
use App\Services\Payments\PaymentProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CareerAssessmentPurchase
{
    public const SCALE = 'CAREER94';

    public const BENEFIT = 'CAREER94_REPORT';

    public const PRICE = 299;

    public const SKUS = ['CNY' => 'CAREER94_REPORT_CNY_299', 'USD' => 'CAREER94_REPORT_USD_299'];

    public static function ownerKey(Request $request): ?string
    {
        if ((int) $request->attributes->get('fm_org_id', 0) !== 0) {
            return null;
        }
        $user = $request->attributes->get('fm_user_id');
        if (is_string($user) && $user !== '') {
            return $user;
        }
        $anon = self::guestAnonId($request);

        return $anon !== null ? 'guest:'.$anon : null;
    }

    public static function guestAnonId(Request $request): ?string
    {
        $key = (string) $request->header('X-Career-Guest-Token', '');

        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? 'c94_'.substr(hash('sha256', $key), 0, 48) : null;
    }

    public static function ownerKeyForOrder(Request $request, object $order): ?string
    {
        $anon = self::guestAnonId($request);

        return $order->user_id === null && $anon !== null ? 'guest:'.$anon : self::ownerKey($request);
    }

    public static function scopeOwner($query, string $owner, string $userColumn = 'user_id', string $anonColumn = 'anon_id')
    {
        return str_starts_with($owner, 'guest:')
            ? $query->whereNull($userColumn)->where($anonColumn, substr($owner, 6))
            : $query->where($userColumn, $owner);
    }

    public static function owns(object $record, string $owner): bool
    {
        return str_starts_with($owner, 'guest:')
            ? $record->user_id === null && (string) $record->anon_id === substr($owner, 6)
            : (string) $record->user_id === $owner;
    }

    public function hasAccess(string $attemptId, string $userId): bool
    {
        $query = DB::table('benefit_grants as grants')->join('orders', 'orders.order_no', '=', 'grants.order_no')
            ->where('grants.org_id', 0)->where('grants.attempt_id', $attemptId)
            ->where('grants.benefit_code', self::BENEFIT)->where('grants.scope', 'attempt')
            ->where('grants.status', 'active')
            ->where(fn ($query) => $query->whereNull('grants.expires_at')->orWhere('grants.expires_at', '>', now()))
            ->where('orders.org_id', 0)->where('orders.target_attempt_id', $attemptId)
            ->whereIn('orders.sku', self::SKUS)->where('orders.payment_state', 'paid');
        self::scopeOwner($query, $userId, 'orders.user_id', 'orders.anon_id');
        if (str_starts_with($userId, 'guest:')) {
            $query->where('grants.user_id', substr($userId, 6))->where('grants.benefit_ref', substr($userId, 6));
        } else {
            $query->where('grants.user_id', $userId);
        }

        return $query->exists();
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

    public function createOrder(?string $attemptId, int $orgId, ?string $userId, string $sku, string $provider, int $quantity, callable $createRow, ?string $anonId = null): array
    {
        $anonId = $userId === null ? self::guestAnonId(request()) : $anonId;

        return DB::transaction(function () use ($attemptId, $orgId, $userId, $sku, $provider, $quantity, $createRow, $anonId) {
            $owner = $userId ?? ($anonId ? 'guest:'.$anonId : null);
            $attempt = $owner !== null && $orgId === 0
                ? self::scopeOwner(Attempt::withoutGlobalScopes()->whereKey($attemptId)->where('org_id', 0), $owner)
                    ->where('scale_code', self::SCALE)->whereNotNull('submitted_at')->lockForUpdate()->first()
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
                    && self::owns($existing, $owner)) {
                    return ['ok' => true, 'order_no' => $existing->order_no, 'order' => $existing, 'idempotent' => true];
                }
                // A timeout or local failure is not proof that the provider stopped collecting this order.
                if ($state !== 'canceled') {
                    return ['ok' => false, 'error_code' => 'PAYMENT_PROVIDER_UNAVAILABLE', 'message' => 'A career report order already exists for this result.'];
                }
            }

            $row = $createRow();
            unset($row['idempotency_key']);
            $row['anon_id'] = $userId !== null ? null : $anonId;
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
