<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\PaymentGateway\AppleIapGateway;
use App\Services\Commerce\PaymentGateway\WechatMiniVirtualGateway;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportGatekeeper;
use App\Support\PiiCipher;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class WechatMiniVirtualPaymentService
{
    private const API_ORIGIN = 'https://api.weixin.qq.com';

    public function __construct(
        private readonly OrderManager $orders,
        private readonly PaymentWebhookProcessor $webhooks,
        private readonly ReportGatekeeper $reportGatekeeper,
        private readonly PiiCipher $piiCipher,
    ) {}

    /**
     * @return array{ok:bool,error_code?:string,message?:string,status?:int}
     */
    public function validateOrderEligibility(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        ?string $targetAttemptId,
        string $sku,
        int $quantity,
        string $provider = WechatMiniVirtualGateway::PROVIDER
    ): array {
        if ($targetAttemptId === null || trim($targetAttemptId) === '') {
            return $this->error('ATTEMPT_REQUIRED', 'target_attempt_id is required.', 422);
        }
        if ($quantity !== 1) {
            return $this->error('QUANTITY_INVALID', 'virtual report unlock quantity must be 1.', 422);
        }

        $config = $this->config($provider);
        $expectedSku = strtoupper(trim((string) ($config['sku'] ?? '')));
        if ($expectedSku === '' || strtoupper(trim($sku)) !== $expectedSku) {
            return $this->error('SKU_NOT_SUPPORTED', 'sku is not supported by this provider.', 422);
        }

        $attempt = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('id', trim($targetAttemptId))
            ->first();
        if (! $attempt) {
            return $this->error('ATTEMPT_NOT_FOUND', 'target attempt not found.', 404);
        }

        $scale = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        $locale = trim((string) ($attempt->locale ?? ''));
        $rolloutScales = array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) config('report_unlock.rollout_scales', [ReportAccess::SCALE_MBTI])
        );
        $rolloutLocales = array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('report_unlock.supported_locales', ['zh-CN'])
        );
        if ($scale !== ReportAccess::SCALE_MBTI
            || ! in_array($scale, $rolloutScales, true)
            || ! in_array($locale, $rolloutLocales, true)) {
            return $this->error('ROLLOUT_DISABLED', 'virtual payment is unavailable for this attempt.', 403);
        }

        $access = $this->reportGatekeeper->ensureAccess(
            $orgId,
            trim($targetAttemptId),
            $userId,
            $anonId
        );
        if (($access['ok'] ?? false) !== true) {
            return $this->error(
                (string) ($access['error_code'] ?? $access['error'] ?? 'REPORT_ACCESS_UNAVAILABLE'),
                (string) ($access['message'] ?? 'report access unavailable.'),
                422
            );
        }
        if (($access['locked'] ?? true) !== true) {
            return $this->error('REPORT_ALREADY_FULL', 'report already has full access.', 409);
        }

        return ['ok' => true];
    }

    /**
     * @return array<string,mixed>
     */
    public function createPaymentAction(
        object $order,
        string $loginCode,
        string $provider = WechatMiniVirtualGateway::PROVIDER
    ): array {
        $provider = $this->normalizeProvider($provider);
        $config = $this->config($provider);
        $loginCode = trim($loginCode);
        if ($loginCode === '' || strlen($loginCode) > 128) {
            return $this->error('WX_LOGIN_CODE_REQUIRED', 'wx_login_code is required.', 422);
        }
        if (! $this->orderContractMatches($order, $config, $provider)) {
            return $this->error('ORDER_CONTRACT_MISMATCH', 'order does not match virtual payment contract.', 409);
        }

        $session = $this->exchangeLoginCode($loginCode, $config);
        if (($session['ok'] ?? false) !== true) {
            return $session;
        }

        $openid = (string) $session['openid'];
        $sessionKey = (string) $session['session_key'];
        $orgId = (int) ($order->org_id ?? 0);
        $orderNo = trim((string) ($order->order_no ?? ''));
        $attempt = $this->orders->latestPaymentAttemptForOrder($orderNo, $orgId);
        if ($attempt !== null && strtolower(trim((string) ($attempt->provider ?? ''))) !== $provider) {
            return $this->error('PAYMENT_ATTEMPT_PROVIDER_MISMATCH', 'payment attempt provider mismatch.', 409);
        }

        $openidHash = $this->openidHash($openid, (string) $config['app_key']);
        $externalOrderNo = trim((string) ($attempt->external_trade_no ?? ''));
        if ($attempt !== null) {
            $attemptMeta = $this->decodeJson($attempt->payload_meta_json ?? null);
            $boundHash = trim((string) ($attemptMeta['openid_hash'] ?? ''));
            if ($boundHash !== '' && ! hash_equals($boundHash, $openidHash)) {
                return $this->error('WECHAT_IDENTITY_MISMATCH', 'order is bound to another WeChat identity.', 409);
            }
        } else {
            $created = $this->orders->createPaymentAttempt(
                $orderNo,
                $orgId,
                $provider,
                'wechat_miniapp',
                (string) $config['app_id'],
                'mini_program',
                (int) ($order->amount_cents ?? 0),
                'CNY'
            );
            if (($created['ok'] ?? false) !== true || ! is_object($created['attempt'] ?? null)) {
                return $this->error(
                    (string) ($created['error_code'] ?? 'PAYMENT_ATTEMPT_CREATE_FAILED'),
                    (string) ($created['message'] ?? 'payment attempt could not be created.'),
                    409
                );
            }
            $attempt = $created['attempt'];
        }

        if ($externalOrderNo === '') {
            $externalOrderNo = 'fm'.strtolower(Str::random(30));
        }
        $signData = [
            'offerId' => (string) $config['offer_id'],
            'buyQuantity' => 1,
            'env' => (int) $config['environment'],
            'currencyType' => 'CNY',
            'productId' => (string) $config['product_id'],
            'goodsPrice' => (int) $config['price_cents'],
            'outTradeNo' => $externalOrderNo,
            'attach' => $orderNo,
        ];
        $signDataJson = json_encode($signData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $paySig = hash_hmac('sha256', 'requestVirtualPayment&'.$signDataJson, (string) $config['app_key']);
        $signature = hash_hmac('sha256', $signDataJson, $sessionKey);

        $attemptMeta = [
            'openid_enc' => $this->piiCipher->encrypt($openid),
            'openid_hash' => $openidHash,
            'app_id_hash' => hash('sha256', (string) $config['app_id']),
            'offer_id_hash' => hash('sha256', (string) $config['offer_id']),
            'product_id' => (string) $config['product_id'],
            'environment' => (int) $config['environment'],
            'channel' => $provider,
        ];
        $this->orders->advancePaymentAttempt((string) ($attempt->id ?? ''), [
            'state' => PaymentAttempt::STATE_CLIENT_PRESENTED,
            'external_trade_no' => $externalOrderNo,
            'payload_meta_json' => $attemptMeta,
        ]);
        DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->update([
                'external_trade_no' => $externalOrderNo,
                'channel' => 'wechat_miniapp',
                'provider_app' => (string) $config['app_id'],
                'payment_state' => Order::PAYMENT_STATE_PENDING,
                'status' => Order::STATUS_PENDING,
                'updated_at' => now(),
            ]);

        return [
            'ok' => true,
            'order_no' => $orderNo,
            'pay' => [
                'type' => $provider,
                'provider' => $provider,
                'params' => [
                    'signData' => $signDataJson,
                    'paySig' => $paySig,
                    'signature' => $signature,
                    'mode' => (string) $config['mode'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reconcile(
        object $order,
        string $provider = WechatMiniVirtualGateway::PROVIDER
    ): array {
        $provider = $this->normalizeProvider($provider);
        $config = $this->config($provider);
        if (! $this->orderContractMatches($order, $config, $provider)) {
            return $this->error('ORDER_CONTRACT_MISMATCH', 'order does not match virtual payment contract.', 409);
        }

        $verified = $this->queryVerifiedProviderOrder($order, $provider, $config);
        if (($verified['ok'] ?? false) !== true) {
            return $verified;
        }
        $attempt = $verified['attempt'];
        $accessToken = $verified['access_token'];
        $providerOrder = $verified['provider_order'];
        $status = (int) ($providerOrder['status'] ?? 0);
        $amount = (int) ($providerOrder['order_fee'] ?? 0);
        $paidAmount = (int) ($providerOrder['paid_fee'] ?? $amount);
        if (in_array($status, [2, 3, 4], true)
            && ($amount !== (int) ($order->amount_cents ?? 0) || $paidAmount !== (int) ($order->amount_cents ?? 0))) {
            return $this->error('AMOUNT_MISMATCH', 'provider amount mismatch.', 409);
        }

        $providerTradeNo = trim((string) (
            $provider === AppleIapGateway::PROVIDER
                ? ($providerOrder['channel_order_id'] ?? $providerOrder['wx_order_id'] ?? '')
                : ($providerOrder['wx_order_id'] ?? '')
        ));
        if ($providerTradeNo === '') {
            $providerTradeNo = (string) ($attempt->external_trade_no ?? '');
        }
        $eventPayload = [
            'provider_event_id' => $provider === AppleIapGateway::PROVIDER
                ? sprintf('query:%s:%s', in_array($status, [5, 8, 9, 10], true) ? 'refund' : 'payment', $providerTradeNo)
                : sprintf(
                    'query:%s:%d:%d',
                    $providerTradeNo,
                    $status,
                    (int) ($providerOrder['update_time'] ?? 0)
                ),
            'order_no' => (string) ($order->order_no ?? ''),
            'external_order_no' => (string) ($attempt->external_trade_no ?? ''),
            'provider_trade_no' => $providerTradeNo,
            'provider_status' => $status,
            'amount_cents' => $amount,
            'refund_amount_cents' => (int) ($providerOrder['refund_fee'] ?? 0),
            'paid_at' => (string) ($providerOrder['paid_time'] ?? ''),
        ];
        $this->orders->touchReconciledLedger((string) ($order->order_no ?? ''), (int) ($order->org_id ?? 0));

        if (in_array($status, [2, 3, 4, 5, 6, 8, 9, 10], true)) {
            $processed = $this->webhooks->handle(
                $provider,
                $eventPayload,
                (int) ($order->org_id ?? 0),
                isset($order->user_id) ? (string) $order->user_id : null,
                isset($order->anon_id) ? (string) $order->anon_id : null,
                true,
                ['source' => 'official_query_order']
            );
            if (($processed['ok'] ?? false) !== true) {
                return $processed;
            }
            if ($status === 2) {
                $this->notifyProvidedGoods(
                    (string) ($attempt->external_trade_no ?? ''),
                    (string) ($providerOrder['wx_order_id'] ?? $providerTradeNo),
                    (string) $accessToken,
                    $config
                );
            }
        }

        $fresh = $this->orders->findOrderByOrderNo(
            (string) ($order->order_no ?? ''),
            (int) ($order->org_id ?? 0)
        );

        return [
            'ok' => true,
            'provider_status' => $status,
            'payment_state' => $fresh ? $this->orders->resolvedPaymentState($fresh) : null,
            'grant_state' => $fresh ? $this->orders->resolvedGrantState($fresh) : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function handleCallback(
        Request $request,
        string $provider = WechatMiniVirtualGateway::PROVIDER
    ): array {
        $provider = $this->normalizeProvider($provider);
        $gateway = $provider === AppleIapGateway::PROVIDER
            ? new AppleIapGateway
            : new WechatMiniVirtualGateway;
        if (! $gateway->verifySignature($request)) {
            return $this->error('INVALID_SIGNATURE', 'invalid signature.', 400);
        }
        if (trim((string) $request->query('msg_signature', '')) !== '') {
            return $this->error('ENCRYPTED_CALLBACK_UNSUPPORTED', 'encrypted callback mode is not configured.', 400);
        }

        $raw = (string) $request->getContent();
        $isJson = str_contains(strtolower((string) $request->header('Content-Type', '')), 'json');
        $payload = $isJson ? json_decode($raw, true) : $this->decodeXml($raw);
        if (! is_array($payload) || $payload === []) {
            return $this->error('PAYLOAD_INVALID', 'callback payload invalid.', 400);
        }

        $eventType = trim((string) ($payload['Event'] ?? $payload['event'] ?? ''));
        if (! in_array($eventType, ['xpay_goods_deliver_notify', 'xpay_refund_notify'], true)) {
            return $this->error('EVENT_TYPE_NOT_ALLOWED', 'event type not allowed.', 404);
        }

        $externalOrderNo = trim((string) ($payload['OutTradeNo'] ?? $payload['out_trade_no'] ?? $payload['MchOrderId'] ?? ''));
        $order = DB::table('orders')
            ->where('provider', $provider)
            ->where('external_trade_no', $externalOrderNo)
            ->first();
        if (! $order) {
            return $this->error('ORDER_NOT_FOUND', 'order not found.', 404);
        }
        $validation = $this->validateCallbackContract($payload, $order, $provider);
        if (($validation['ok'] ?? false) !== true) {
            return $validation;
        }

        $verifiedProviderOrder = null;
        if ($provider === AppleIapGateway::PROVIDER) {
            $verified = $this->queryVerifiedProviderOrder($order, $provider, $this->config($provider));
            if (($verified['ok'] ?? false) !== true) {
                return $verified;
            }
            $verifiedProviderOrder = $verified['provider_order'];
            $verifiedStatus = (int) ($verifiedProviderOrder['status'] ?? 0);
            $verifiedOrderType = (int) ($verifiedProviderOrder['order_type'] ?? -1);
            $expectedStatuses = $eventType === 'xpay_refund_notify'
                ? [5, 8, 9, 10]
                : [2, 3, 4];
            $expectedOrderType = $eventType === 'xpay_refund_notify' ? 8 : 7;
            if ($verifiedOrderType !== $expectedOrderType || ! in_array($verifiedStatus, $expectedStatuses, true)) {
                return $this->error(
                    $eventType === 'xpay_refund_notify' ? 'REFUND_NOT_CONFIRMED' : 'PAYMENT_NOT_CONFIRMED',
                    'Apple-routed callback is not confirmed by official query_order.',
                    409
                );
            }
            $verifiedAmount = (int) ($verifiedProviderOrder['order_fee'] ?? 0);
            $verifiedPaidAmount = (int) ($verifiedProviderOrder['paid_fee'] ?? $verifiedAmount);
            if ($verifiedAmount !== (int) ($order->amount_cents ?? 0)
                || $verifiedPaidAmount !== (int) ($order->amount_cents ?? 0)) {
                return $this->error('AMOUNT_MISMATCH', 'provider amount mismatch.', 409);
            }
            if ($eventType === 'xpay_refund_notify') {
                $verifiedRefundAmount = (int) ($verifiedProviderOrder['refund_fee'] ?? 0);
                if ($verifiedRefundAmount <= 0 || $verifiedRefundAmount > (int) ($order->amount_cents ?? 0)) {
                    return $this->error('REFUND_MISMATCH', 'refund result mismatch.', 409);
                }
            }
        }

        $goods = is_array($payload['GoodsInfo'] ?? null) ? $payload['GoodsInfo'] : [];
        $wechatInfo = is_array($payload['WeChatPayInfo'] ?? null) ? $payload['WeChatPayInfo'] : [];
        $providerTradeNo = trim((string) (
            $verifiedProviderOrder['channel_order_id']
            ?? $verifiedProviderOrder['wx_order_id']
            ?? $wechatInfo['TransactionId']
            ?? $payload['channel_order_id']
            ?? $payload['channel_bill']
            ?? $payload['WxRefundId']
            ?? $payload['wx_refund_id']
            ?? $externalOrderNo
        ));
        $normalizedPayload = array_merge($payload, [
            'provider_event_id' => $provider === AppleIapGateway::PROVIDER
                ? sprintf('%s:%s', $eventType === 'xpay_refund_notify' ? 'refund' : 'payment', $providerTradeNo)
                : $eventType.':'.$providerTradeNo,
            'order_no' => (string) ($order->order_no ?? ''),
            'external_order_no' => $externalOrderNo,
            'provider_trade_no' => $providerTradeNo,
            'amount_cents' => (int) (
                $verifiedProviderOrder['order_fee']
                ?? $goods['ActualPrice']
                ?? $goods['actual_price']
                ?? $order->amount_cents
                ?? 0
            ),
            'refund_amount_cents' => (int) (
                $verifiedProviderOrder['refund_fee']
                ?? $payload['RefundFee']
                ?? $payload['refund_fee']
                ?? 0
            ),
            'event_type' => $eventType === 'xpay_refund_notify' ? 'refund_succeeded' : 'payment_succeeded',
        ]);
        $result = $this->webhooks->handle(
            $provider,
            $normalizedPayload,
            (int) ($order->org_id ?? 0),
            isset($order->user_id) ? (string) $order->user_id : null,
            isset($order->anon_id) ? (string) $order->anon_id : null,
            true,
            [
                'source' => 'wechat_message_push',
                'content_type' => $isJson ? 'json' : 'xml',
            ],
            hash('sha256', $raw),
            strlen($raw)
        );
        $result['ack_format'] = $isJson ? 'json' : 'xml';

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function queryVerifiedProviderOrder(object $order, string $provider, array $config): array
    {
        $attempt = $this->orders->latestPaymentAttemptForOrder(
            (string) ($order->order_no ?? ''),
            (int) ($order->org_id ?? 0)
        );
        if ($attempt === null || strtolower((string) ($attempt->provider ?? '')) !== $provider) {
            return $this->error('PAYMENT_ATTEMPT_NOT_FOUND', 'virtual payment attempt not found.', 409);
        }
        $meta = $this->decodeJson($attempt->payload_meta_json ?? null);
        $openid = $this->piiCipher->decrypt((string) ($meta['openid_enc'] ?? ''));
        if ($openid === null || ! hash_equals(
            (string) ($meta['openid_hash'] ?? ''),
            $this->openidHash($openid, (string) $config['app_key'])
        )) {
            return $this->error('WECHAT_IDENTITY_UNAVAILABLE', 'WeChat identity is unavailable.', 409);
        }

        $accessToken = $this->accessToken($config);
        if (($accessToken['ok'] ?? false) !== true) {
            return $accessToken;
        }

        try {
            $response = $this->signedPost(
                '/xpay/query_order',
                [
                    'openid' => $openid,
                    'env' => (int) $config['environment'],
                    'order_id' => (string) ($attempt->external_trade_no ?? ''),
                ],
                (string) $accessToken['access_token'],
                (string) $config['app_key'],
                $config
            );
        } catch (\Throwable) {
            return $this->error('WECHAT_QUERY_FAILED', 'WeChat API request failed.', 502);
        }
        $decoded = $this->decodeProviderResponse($response, 'WECHAT_QUERY_FAILED');
        if (($decoded['ok'] ?? false) !== true) {
            return $decoded;
        }

        $providerOrder = is_array($decoded['body']['order'] ?? null) ? $decoded['body']['order'] : [];
        if (trim((string) ($providerOrder['order_id'] ?? '')) !== (string) ($attempt->external_trade_no ?? '')) {
            return $this->error('ORDER_MISMATCH', 'provider order mismatch.', 409);
        }

        $orderType = (int) ($providerOrder['order_type'] ?? -1);
        $envType = (int) ($providerOrder['env_type'] ?? 0);
        if ($provider === AppleIapGateway::PROVIDER) {
            if (! in_array($orderType, [7, 8], true)) {
                return $this->error('PROVIDER_CHANNEL_MISMATCH', 'provider order is not an Apple-routed payment.', 409);
            }
            if ($envType !== 1) {
                return $this->error('PROVIDER_ENV_MISMATCH', 'provider environment mismatch.', 409);
            }
            $status = (int) ($providerOrder['status'] ?? 0);
            if ((in_array($status, [2, 3, 4], true) && $orderType !== 7)
                || (in_array($status, [5, 8, 9, 10], true) && $orderType !== 8)) {
                return $this->error('PROVIDER_CHANNEL_MISMATCH', 'provider order type does not match its state.', 409);
            }
            if (in_array($status, [2, 3, 4, 5, 8, 9, 10], true)
                && trim((string) ($providerOrder['channel_order_id'] ?? $providerOrder['wx_order_id'] ?? '')) === '') {
                return $this->error('PROVIDER_TRANSACTION_ID_MISSING', 'provider transaction identifier missing.', 409);
            }
        } else {
            $expectedEnvType = (int) $config['environment'] === 0 ? 1 : 2;
            if ($envType > 0 && $envType !== $expectedEnvType) {
                return $this->error('PROVIDER_ENV_MISMATCH', 'provider environment mismatch.', 409);
            }
        }

        return [
            'ok' => true,
            'attempt' => $attempt,
            'access_token' => (string) $accessToken['access_token'],
            'provider_order' => $providerOrder,
        ];
    }

    private function validateCallbackContract(array $payload, object $order, string $provider): array
    {
        $config = $this->config($provider);
        if (! $this->orderContractMatches($order, $config, $provider)) {
            return $this->error('ORDER_CONTRACT_MISMATCH', 'order contract mismatch.', 409);
        }
        $attempt = $this->orders->latestPaymentAttemptForOrder(
            (string) ($order->order_no ?? ''),
            (int) ($order->org_id ?? 0)
        );
        if (! $attempt) {
            return $this->error('PAYMENT_ATTEMPT_NOT_FOUND', 'payment attempt not found.', 409);
        }
        $meta = $this->decodeJson($attempt->payload_meta_json ?? null);
        foreach ([
            'app_id_hash' => hash('sha256', (string) $config['app_id']),
            'offer_id_hash' => hash('sha256', (string) $config['offer_id']),
        ] as $key => $expected) {
            if (! hash_equals($expected, (string) ($meta[$key] ?? ''))) {
                return $this->error('PROVIDER_IDENTITY_MISMATCH', 'provider identity mismatch.', 409);
            }
        }

        $eventType = trim((string) ($payload['Event'] ?? $payload['event'] ?? ''));
        $openid = trim((string) ($payload['OpenId'] ?? $payload['openid'] ?? ''));
        if ($openid === '' || ! hash_equals(
            (string) ($meta['openid_hash'] ?? ''),
            $this->openidHash($openid, (string) $config['app_key'])
        )) {
            return $this->error('WECHAT_IDENTITY_MISMATCH', 'WeChat identity mismatch.', 409);
        }

        if ($eventType === 'xpay_refund_notify') {
            $merchantOrderId = trim((string) ($payload['MchOrderId'] ?? $payload['mch_order_id'] ?? ''));
            $refundFee = (int) ($payload['RefundFee'] ?? $payload['refund_fee'] ?? 0);
            $retCode = (int) ($payload['RetCode'] ?? $payload['ret_code'] ?? -1);
            if ($merchantOrderId !== (string) ($order->external_trade_no ?? '')) {
                return $this->error('ORDER_MISMATCH', 'refund order mismatch.', 409);
            }
            if ($retCode !== 0 || $refundFee <= 0 || $refundFee > (int) ($order->amount_cents ?? 0)) {
                return $this->error('REFUND_MISMATCH', 'refund result mismatch.', 409);
            }

            return ['ok' => true];
        }

        $env = (int) ($payload['Env'] ?? $payload['env'] ?? -1);
        if ($env !== (int) $config['environment']) {
            return $this->error('PROVIDER_ENV_MISMATCH', 'provider environment mismatch.', 409);
        }
        if ($provider === AppleIapGateway::PROVIDER && $env !== 0) {
            return $this->error('PROVIDER_ENV_MISMATCH', 'Apple-routed payment must use the production environment.', 409);
        }
        $goods = is_array($payload['GoodsInfo'] ?? null) ? $payload['GoodsInfo'] : [];
        $productId = trim((string) ($goods['ProductId'] ?? $goods['product_id'] ?? ''));
        if ($productId !== (string) $config['product_id']
            || $productId !== (string) ($meta['product_id'] ?? '')) {
            return $this->error('PRODUCT_MISMATCH', 'product mismatch.', 409);
        }
        $actualPrice = (int) ($goods['ActualPrice'] ?? $goods['actual_price'] ?? 0);
        if ($actualPrice !== (int) ($order->amount_cents ?? 0)) {
            return $this->error('AMOUNT_MISMATCH', 'provider amount mismatch.', 409);
        }

        return ['ok' => true];
    }

    private function exchangeLoginCode(string $loginCode, array $config): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout($config))
                ->get(self::API_ORIGIN.'/sns/jscode2session', [
                    'appid' => (string) $config['app_id'],
                    'secret' => (string) $config['app_secret'],
                    'js_code' => $loginCode,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (\Throwable) {
            return $this->error('WX_LOGIN_EXCHANGE_FAILED', 'WeChat login request failed.', 502);
        }
        $decoded = $this->decodeProviderResponse($response, 'WX_LOGIN_EXCHANGE_FAILED');
        if (($decoded['ok'] ?? false) !== true) {
            return $decoded;
        }
        $openid = trim((string) ($decoded['body']['openid'] ?? ''));
        $sessionKey = trim((string) ($decoded['body']['session_key'] ?? ''));
        if ($openid === '' || $sessionKey === '') {
            return $this->error('WX_LOGIN_EXCHANGE_FAILED', 'WeChat login response incomplete.', 502);
        }

        return ['ok' => true, 'openid' => $openid, 'session_key' => $sessionKey];
    }

    private function accessToken(array $config): array
    {
        $cacheKey = 'wechat-mini-virtual:access-token:'.hash('sha256', (string) $config['app_id']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && trim($cached) !== '') {
            return ['ok' => true, 'access_token' => $cached];
        }

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout($config))
                ->get(self::API_ORIGIN.'/cgi-bin/token', [
                    'grant_type' => 'client_credential',
                    'appid' => (string) $config['app_id'],
                    'secret' => (string) $config['app_secret'],
                ]);
        } catch (\Throwable) {
            return $this->error('WECHAT_ACCESS_TOKEN_FAILED', 'WeChat access token request failed.', 502);
        }
        $decoded = $this->decodeProviderResponse($response, 'WECHAT_ACCESS_TOKEN_FAILED');
        if (($decoded['ok'] ?? false) !== true) {
            return $decoded;
        }
        $token = trim((string) ($decoded['body']['access_token'] ?? ''));
        if ($token === '') {
            return $this->error('WECHAT_ACCESS_TOKEN_FAILED', 'access token response incomplete.', 502);
        }
        Cache::put($cacheKey, $token, max(60, ((int) ($decoded['body']['expires_in'] ?? 7200)) - 300));

        return ['ok' => true, 'access_token' => $token];
    }

    private function signedPost(
        string $uri,
        array $body,
        string $accessToken,
        string $appKey,
        array $config
    ): Response {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $paySig = hash_hmac('sha256', $uri.'&'.$json, $appKey);

        return Http::acceptJson()
            ->timeout($this->timeout($config))
            ->withBody($json, 'application/json')
            ->post(self::API_ORIGIN.$uri.'?'.http_build_query([
                'access_token' => $accessToken,
                'pay_sig' => $paySig,
            ]));
    }

    private function notifyProvidedGoods(
        string $externalOrderNo,
        string $providerOrderNo,
        string $accessToken,
        array $config
    ): void {
        $body = [
            'order_id' => $externalOrderNo,
            'wx_order_id' => $providerOrderNo,
            'env' => (int) $config['environment'],
        ];
        try {
            Http::acceptJson()
                ->timeout($this->timeout($config))
                ->withBody(
                    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'application/json'
                )
                ->post(self::API_ORIGIN.'/xpay/notify_provide_goods?'.http_build_query([
                    'access_token' => $accessToken,
                ]));
        } catch (\Throwable) {
            // The next reconciliation retries delivery acknowledgement. Never
            // bubble provider URLs because they contain access_token.
        }
    }

    private function decodeProviderResponse(Response $response, string $errorCode): array
    {
        if (! $response->successful()) {
            return $this->error($errorCode, 'WeChat API request failed.', 502);
        }
        $body = $response->json();
        if (! is_array($body)) {
            return $this->error($errorCode, 'WeChat API response invalid.', 502);
        }
        $errcode = (int) ($body['errcode'] ?? 0);
        if ($errcode !== 0) {
            return $this->error($errorCode, 'WeChat API rejected the request.', 502);
        }

        return ['ok' => true, 'body' => $body];
    }

    private function orderContractMatches(object $order, array $config, string $provider): bool
    {
        return strtolower(trim((string) ($order->provider ?? ''))) === $provider
            && strtoupper(trim((string) ($order->sku ?? ''))) === strtoupper((string) $config['sku'])
            && (int) ($order->quantity ?? 0) === 1
            && (int) ($order->amount_cents ?? 0) === (int) $config['price_cents']
            && strtoupper(trim((string) ($order->currency ?? ''))) === 'CNY'
            && trim((string) ($order->target_attempt_id ?? '')) !== '';
    }

    private function openidHash(string $openid, string $appKey): string
    {
        return hash_hmac('sha256', trim($openid), $appKey);
    }

    private function decodeXml(string $raw): array
    {
        if (trim($raw) === '' || ! function_exists('simplexml_load_string')) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                return [];
            }
            $decoded = json_decode(json_encode($xml, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function config(string $provider): array
    {
        $config = config('payments.'.$this->normalizeProvider($provider), []);

        return is_array($config) ? $config : [];
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return $provider === AppleIapGateway::PROVIDER
            ? AppleIapGateway::PROVIDER
            : WechatMiniVirtualGateway::PROVIDER;
    }

    private function timeout(array $config): int
    {
        return min(20, max(1, (int) ($config['http_timeout_seconds'] ?? 8)));
    }

    private function error(string $code, string $message, int $status): array
    {
        return [
            'ok' => false,
            'error_code' => $code,
            'message' => $message,
            'status' => $status,
        ];
    }
}
