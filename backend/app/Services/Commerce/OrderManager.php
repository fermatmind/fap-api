<?php

namespace App\Services\Commerce;

use App\Services\Report\ReportAccess;
use App\Services\Scale\ScaleIdentityWriteProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderManager
{
    private const FINAL_STATUSES = ['fulfilled', 'failed', 'canceled', 'refunded'];

    private const MAX_ORDER_QUANTITY = 1000;

    private const MAX_INT32 = 2147483647;

    public function __construct(
        private SkuCatalog $skus,
        private ScaleIdentityWriteProjector $identityProjector,
    ) {}

    public function createOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $sku,
        int $quantity,
        ?string $targetAttemptId,
        string $provider,
        ?string $idempotencyKey = null,
        ?string $contactEmail = null
    ): array {
        $requestedSku = $this->skus->normalizeSku($sku);
        if ($requestedSku === '') {
            return $this->badRequest('SKU_REQUIRED', 'sku is required.');
        }

        $resolved = $this->skus->resolveSkuMeta($requestedSku);
        $effectiveSku = strtoupper(trim((string) ($resolved['effective_sku'] ?? '')));
        $entitlementId = $resolved['entitlement_id'] ?? null;
        $requestedSku = strtoupper(trim((string) ($resolved['requested_sku'] ?? $requestedSku)));

        $quantity = (int) $quantity;
        if ($quantity < 1 || $quantity > self::MAX_ORDER_QUANTITY) {
            return $this->badRequest('QUANTITY_INVALID', 'quantity out of range.');
        }

        $skuRow = $resolved['sku_row'] ?? null;
        if (! $skuRow) {
            return $this->notFound('SKU_NOT_FOUND', 'sku not found.');
        }

        $skuMeta = $this->decodeMeta($skuRow->meta_json ?? null);
        $modulesIncluded = $this->normalizeModulesIncluded($skuMeta['modules_included'] ?? null);

        $unitPriceCents = (int) ($skuRow->price_cents ?? 0);
        if ($unitPriceCents < 0) {
            return $this->badRequest('PRICE_INVALID', 'price invalid.');
        }
        if ($unitPriceCents > 0 && $quantity > intdiv(self::MAX_INT32, $unitPriceCents)) {
            return $this->badRequest('AMOUNT_TOO_LARGE', 'amount too large.');
        }

        $skuToLookup = $effectiveSku !== '' ? $effectiveSku : $requestedSku;
        $provider = strtolower(trim($provider));
        if ($provider === '' || ! in_array($provider, $this->allowedProviders(), true)) {
            return $this->badRequest('PROVIDER_NOT_SUPPORTED', 'provider not supported.');
        }

        $normalizedUserId = $this->trimOrNull($userId);
        $normalizedAnonId = $this->trimOrNull($anonId);
        $contactEmailHash = $this->hashContactEmail($contactEmail);
        if ($normalizedUserId === null && $normalizedAnonId === null && $contactEmailHash === null) {
            return $this->badRequest('EMAIL_REQUIRED', 'email is required.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $useIdempotency = $idempotencyKey !== '';

        $createRow = function () use (
            $orgId,
            $normalizedUserId,
            $normalizedAnonId,
            $skuToLookup,
            $quantity,
            $targetAttemptId,
            $provider,
            $skuRow,
            $unitPriceCents,
            $requestedSku,
            $effectiveSku,
            $entitlementId,
            $idempotencyKey,
            $useIdempotency,
            $modulesIncluded,
            $contactEmailHash
        ): array {
            $orderNo = 'ord_'.Str::uuid();
            $now = now();

            $orderMeta = [];
            if ($modulesIncluded !== []) {
                $orderMeta['modules_included'] = $modulesIncluded;
            }

            $row = [
                'id' => (string) Str::uuid(),
                'order_no' => $orderNo,
                'org_id' => $orgId,
                'user_id' => $normalizedUserId,
                'anon_id' => $normalizedAnonId,
                'sku' => $skuToLookup,
                'quantity' => $quantity,
                'target_attempt_id' => $this->trimOrNull($targetAttemptId),
                'amount_cents' => $unitPriceCents * $quantity,
                'currency' => (string) ($skuRow->currency ?? 'USD'),
                'status' => 'created',
                'provider' => $provider,
                'external_trade_no' => null,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'requested_sku' => $requestedSku,
                'effective_sku' => $effectiveSku !== '' ? $effectiveSku : $skuToLookup,
                'entitlement_id' => $entitlementId,
                'meta_json' => $orderMeta !== []
                    ? json_encode($orderMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ];
            if (Schema::hasColumn('orders', 'contact_email_hash')) {
                $row['contact_email_hash'] = $contactEmailHash;
            }

            if ($this->shouldWriteScaleIdentityColumns() && $this->ordersTableHasIdentityColumns()) {
                $identity = $this->resolveOrderScaleIdentity($orgId, $targetAttemptId);
                $row['scale_code_v2'] = $identity['scale_code_v2'];
                $row['scale_uid'] = $identity['scale_uid'];
            }

            if ($useIdempotency) {
                $row['idempotency_key'] = $idempotencyKey;
            }

            return $this->applyLegacyColumns($row);
        };

        if ($useIdempotency) {
            return DB::transaction(function () use ($orgId, $provider, $idempotencyKey, $createRow) {
                $existing = $this->findIdempotentOrder($orgId, $provider, $idempotencyKey, true);
                if ($existing) {
                    return [
                        'ok' => true,
                        'order_no' => $existing->order_no ?? null,
                        'order' => $existing,
                        'idempotent' => true,
                    ];
                }

                $row = $createRow();
                $inserted = DB::table('orders')->insertOrIgnore($row);
                if ((int) $inserted === 0) {
                    $existing = $this->findIdempotentOrder($orgId, $provider, $idempotencyKey, true);
                    if ($existing) {
                        return [
                            'ok' => true,
                            'order_no' => $existing->order_no ?? null,
                            'order' => $existing,
                            'idempotent' => true,
                        ];
                    }
                }

                $order = DB::table('orders')->where('order_no', $row['order_no'])->first();

                return [
                    'ok' => true,
                    'order_no' => $order->order_no ?? $row['order_no'],
                    'order' => $order ?? $row,
                    'idempotent' => false,
                ];
            });
        }

        $row = $createRow();
        DB::table('orders')->insert($row);
        $order = DB::table('orders')->where('order_no', $row['order_no'])->first();

        return [
            'ok' => true,
            'order_no' => $order->order_no ?? $row['order_no'],
            'order' => $order ?? $row,
        ];
    }

    public function findLookupOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $orderNo,
        ?string $contactEmailHash = null
    ): ?object {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return null;
        }

        $query = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId);

        $uid = $this->trimOrNull($userId);
        $aid = $this->trimOrNull($anonId);
        $emailHash = $this->normalizeEmailHash($contactEmailHash);
        $canMatchByEmailHash = $emailHash !== null && Schema::hasColumn('orders', 'contact_email_hash');
        if ($uid === null && $aid === null && ! $canMatchByEmailHash) {
            return null;
        }

        $query->where(function ($scoped) use ($uid, $aid, $canMatchByEmailHash, $emailHash): void {
            if ($uid !== null) {
                $scoped->orWhere('user_id', $uid);
            }

            if ($aid !== null) {
                $scoped->orWhere('anon_id', $aid);
            }

            if ($canMatchByEmailHash && $emailHash !== null) {
                $scoped->orWhere('contact_email_hash', $emailHash);
            }
        });

        return $query->first();
    }

    public function getOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $orderNo,
        bool $allowAnonymousFallback = false
    ): array {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $order = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->first();

        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $uid = $this->trimOrNull($userId);
        $aid = $this->trimOrNull($anonId);

        $ownedByUser = $uid !== null && $uid === $this->trimOrNull($order->user_id ?? null);
        $ownedByAnon = $aid !== null && $aid === $this->trimOrNull($order->anon_id ?? null);
        $ownershipVerified = $ownedByUser || $ownedByAnon;

        if (! $ownershipVerified && $uid === null && $aid === null && $allowAnonymousFallback) {
            return [
                'ok' => true,
                'order' => $order,
                'ownership_verified' => false,
            ];
        }

        if (! $ownershipVerified) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        return [
            'ok' => true,
            'order' => $order,
            'ownership_verified' => true,
        ];
    }

    public function transitionToPaidAtomic(
        string $orderNo,
        int $orgId,
        ?string $externalTradeNo = null,
        ?string $paidAt = null
    ): array {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $order = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->lockForUpdate()
            ->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $fromStatus = strtolower((string) ($order->status ?? ''));
        if ($fromStatus === '') {
            $fromStatus = 'created';
        }

        if (in_array($fromStatus, ['paid', 'fulfilled'], true)) {
            return [
                'ok' => true,
                'order' => $order,
                'already_paid' => true,
            ];
        }

        if (! $this->isTransitionAllowed($fromStatus, 'paid')) {
            return $this->conflict('ORDER_STATUS_INVALID', 'invalid order status transition.');
        }

        $now = now();
        $updates = [
            'status' => 'paid',
            'updated_at' => $now,
        ];

        if (empty($order->paid_at)) {
            $updates['paid_at'] = ($paidAt !== null && $paidAt !== '') ? $paidAt : $now;
        }

        if ($externalTradeNo) {
            $updates['external_trade_no'] = $externalTradeNo;
        }

        $updated = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->where('status', $fromStatus)
            ->update($updates);

        if ($updated === 0) {
            $current = DB::table('orders')->where('order_no', $orderNo)->where('org_id', $orgId)->first();
            if (! $current) {
                return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
            }
            $currentStatus = strtolower((string) ($current->status ?? ''));
            if (in_array($currentStatus, ['paid', 'fulfilled'], true)) {
                return [
                    'ok' => true,
                    'order' => $current,
                    'already_paid' => true,
                ];
            }

            return $this->conflict('ORDER_STATUS_CHANGED', 'order status changed.');
        }

        $order = DB::table('orders')->where('order_no', $orderNo)->where('org_id', $orgId)->first();

        return [
            'ok' => true,
            'order' => $order,
            'changed' => true,
        ];
    }

    public function transition(string $orderNo, string $toStatus, ?int $orgId = null): array
    {
        $orderNo = trim($orderNo);
        $toStatus = strtolower(trim($toStatus));
        if ($orderNo === '' || $toStatus === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no and status are required.');
        }

        $query = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId !== null) {
            $query->where('org_id', $orgId);
        }

        $order = $query->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $fromStatus = strtolower((string) ($order->status ?? ''));
        if ($fromStatus === '') {
            $fromStatus = 'created';
        }

        if ($fromStatus === $toStatus) {
            return [
                'ok' => true,
                'order' => $order,
            ];
        }

        if (! $this->isTransitionAllowed($fromStatus, $toStatus)) {
            return $this->conflict('ORDER_STATUS_INVALID', 'invalid order status transition.');
        }

        $updates = [
            'status' => $toStatus,
            'updated_at' => now(),
        ];

        if ($toStatus === 'paid') {
            $updates['paid_at'] = $updates['updated_at'];
        }

        if ($toStatus === 'fulfilled') {
            $updates['fulfilled_at'] = $updates['updated_at'];
        }

        if ($toStatus === 'refunded') {
            $updates['refunded_at'] = $updates['updated_at'];
        }

        $updateQuery = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId !== null) {
            $updateQuery->where('org_id', $orgId);
        }
        $updateQuery->where('status', $fromStatus);

        $updated = $updateQuery->update($updates);
        if ($updated === 0) {
            $current = DB::table('orders')->where('order_no', $orderNo)->first();
            if (! $current) {
                return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
            }

            $currentStatus = strtolower((string) ($current->status ?? ''));
            if ($currentStatus === $toStatus) {
                return [
                    'ok' => true,
                    'order' => $current,
                ];
            }

            return $this->conflict('ORDER_STATUS_CHANGED', 'order status changed.');
        }

        $order = DB::table('orders')->where('order_no', $orderNo)->first();

        return [
            'ok' => true,
            'order' => $order,
        ];
    }

    private function isTransitionAllowed(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === 'created' && in_array($toStatus, ['pending', 'paid', 'failed', 'canceled', 'refunded'], true)) {
            return true;
        }
        if ($fromStatus === 'pending' && in_array($toStatus, ['paid', 'failed', 'canceled', 'refunded'], true)) {
            return true;
        }
        if ($fromStatus === 'paid' && $toStatus === 'fulfilled') {
            return true;
        }
        if ($fromStatus === 'fulfilled' && $toStatus === 'refunded') {
            return true;
        }

        return false;
    }

    private function applyLegacyColumns(array $row): array
    {
        $row['amount_total'] = $row['amount_cents'];
        $row['amount_refunded'] = 0;
        $row['item_sku'] = $row['sku'];
        $row['provider_order_id'] = null;
        $row['device_id'] = null;
        $row['request_id'] = null;
        $row['created_ip'] = null;
        $row['fulfilled_at'] = null;
        $row['refunded_at'] = null;
        $row['refund_amount_cents'] = null;
        $row['refund_reason'] = null;

        return $row;
    }

    private function allowedProviders(): array
    {
        $providers = [];
        $configured = config('payments.providers', []);
        if (is_array($configured)) {
            foreach ($configured as $provider => $providerConfig) {
                if (! is_string($provider)) {
                    continue;
                }

                $provider = strtolower(trim($provider));
                if ($provider === '') {
                    continue;
                }

                $enabled = (bool) (is_array($providerConfig) ? ($providerConfig['enabled'] ?? false) : false);
                if (! $enabled) {
                    continue;
                }

                if ($provider === 'stub' && ! $this->isStubEnabled()) {
                    continue;
                }

                $providers[] = $provider;
            }
        }

        if ($providers === []) {
            $providers = ['stripe', 'billing'];
            if ($this->isStubEnabled()) {
                $providers[] = 'stub';
            }
        }

        return array_values(array_unique($providers));
    }

    private function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function conflict(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function trimOrNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function normalizeIdempotencyKey(?string $key): string
    {
        $key = $key !== null ? trim($key) : '';
        if ($key === '') {
            return '';
        }

        if (strlen($key) > 128) {
            $key = substr($key, 0, 128);
        }

        return $key;
    }

    private function hashContactEmail(?string $email): ?string
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        return hash('sha256', $normalized);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email), 'UTF-8');
        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function normalizeEmailHash(?string $hash): ?string
    {
        $hash = strtolower(trim((string) $hash));
        if ($hash === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }

        return $hash;
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function ordersTableHasIdentityColumns(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'scale_code_v2')
            && Schema::hasColumn('orders', 'scale_uid');
    }

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    private function resolveOrderScaleIdentity(int $orgId, ?string $targetAttemptId): array
    {
        $attemptId = $this->trimOrNull($targetAttemptId);
        if ($attemptId === null) {
            return [
                'scale_code_v2' => null,
                'scale_uid' => null,
            ];
        }

        $attempt = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if (! $attempt) {
            return [
                'scale_code_v2' => null,
                'scale_uid' => null,
            ];
        }

        return $this->identityProjector->projectFromCodes(
            (string) ($attempt->scale_code ?? ''),
            (string) ($attempt->scale_code_v2 ?? ''),
            (string) ($attempt->scale_uid ?? '')
        );
    }

    private function findIdempotentOrder(
        int $orgId,
        string $provider,
        string $idempotencyKey,
        bool $lockForUpdate = false
    ): ?object {
        $query = DB::table('orders')
            ->where('idempotency_key', $idempotencyKey)
            ->where('org_id', $orgId)
            ->where('provider', $provider);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
