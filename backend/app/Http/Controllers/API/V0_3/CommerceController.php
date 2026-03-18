<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Commerce\Checkout\AlipayCheckoutService;
use App\Services\Commerce\Checkout\LemonSqueezyCheckoutService;
use App\Services\Commerce\Checkout\WechatPayCheckoutService;
use App\Services\Commerce\MbtiAccessHubBuilder;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\SkuCatalog;
use App\Services\Email\EmailCaptureService;
use App\Services\Payments\PaymentRouter;
use App\Services\Report\ReportAccess;
use App\Support\OrgContext;
use App\Support\RegionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Symfony\Component\HttpFoundation\Response;

class CommerceController extends Controller
{
    public function __construct(
        private OrgContext $orgContext,
        private RegionContext $regionContext,
        private OrderManager $orders,
        private EmailCaptureService $emailCaptures,
        private SkuCatalog $skus,
        private PaymentRouter $paymentRouter,
        private MbtiAccessHubBuilder $mbtiAccessHubBuilder,
        private LemonSqueezyCheckoutService $lemonSqueezyCheckout,
        private WechatPayCheckoutService $wechatPayCheckout,
        private AlipayCheckoutService $alipayCheckout,
    ) {}

    /**
     * GET /api/v0.3/skus?scale=MBTI
     */
    public function listSkus(Request $request): JsonResponse
    {
        $scale = strtoupper(trim((string) $request->query('scale', '')));
        if ($scale === '') {
            abort(400, 'scale is required.');
        }

        $items = $this->skus->listActiveSkus($scale, $this->orgContext->orgId());

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/v0.3/orders
     */
    public function createOrder(Request $request, ?string $providerFromRoute = null): JsonResponse
    {
        $payload = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'email' => ['nullable', 'string', 'max:320'],
            'target_attempt_id' => ['nullable', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'org_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'anon_id' => ['prohibited'],
        ]);

        $requestedProvider = $this->resolveRequestedProvider($payload, $providerFromRoute);
        $this->guardStubProvider($request, $requestedProvider);

        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $contactEmail = $this->resolveContactEmail($payload, $userId !== null ? (string) $userId : null);
        if ($userId === null && $anonId === null && $contactEmail === null) {
            abort(422, 'email is required.');
        }

        $provider = $this->resolveProvider($payload, $providerFromRoute);
        if ($provider === '') {
            abort(422, 'provider unavailable.');
        }

        $idempotencyKey = $this->resolveIdempotencyKey($request, $payload);

        $result = $this->orders->createOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            (string) $payload['sku'],
            (int) ($payload['quantity'] ?? 1),
            $payload['target_attempt_id'] ?? null,
            $provider,
            $idempotencyKey,
            $contactEmail,
            $this->resolveRequestId($request)
        );

        if (! ($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($result, 'error_code', data_get($result, 'error', '')));
            $message = trim((string) ($result['message'] ?? ''));
            abort($status, $message !== '' ? $message : 'request failed.');
        }

        return response()->json([
            'ok' => true,
            'order_no' => $result['order_no'] ?? null,
        ]);
    }

    /**
     * GET /api/v0.3/orders/{order_no}
     */
    public function getOrder(Request $request, string $order_no): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $result = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $order_no, false);
        if (! ($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($result, 'error_code', data_get($result, 'error', '')));
            $message = trim((string) ($result['message'] ?? ''));
            abort($status, $message !== '' ? $message : 'request failed.');
        }

        $order = $result['order'];
        $ownershipVerified = (bool) ($result['ownership_verified'] ?? true);
        $status = $this->normalizePublicOrderStatus((string) ($order->status ?? ''));
        $message = $this->publicOrderMessage((string) ($order->status ?? ''));
        $delivery = $this->buildOrderDelivery($order);
        $payment = $this->buildOrderPaymentPayload(
            $request,
            $order,
            $status === 'pending' && $request->boolean('include_payment_action')
        );

        if (! $ownershipVerified) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        $payload = [
            'ok' => true,
            'ownership_verified' => true,
            'order' => $order,
            'order_no' => $order->order_no ?? null,
            'attempt_id' => $delivery['attempt_id'],
            'status' => $status,
            'message' => $message,
            'amount_cents' => $order->amount_cents ?? $order->amount_total ?? null,
            'currency' => $order->currency ?? null,
            'provider' => $payment['provider'],
            'pay' => $payment['pay'],
            'checkout_url' => $payment['checkout_url'],
            'delivery' => $delivery['delivery'],
        ];

        $mbtiAccessHub = $this->mbtiAccessHubBuilder->buildForOrderContext($order);
        if ($mbtiAccessHub !== null) {
            $payload[ReportAccess::ACCESS_HUB_KEY] = $mbtiAccessHub;
        }

        return response()->json($payload);
    }

    /**
     * POST /api/v0.3/orders/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'attempt_id' => ['nullable', 'string', 'max:64'],
            'sku' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:320'],
            'marketing_consent' => ['nullable', 'boolean'],
            'transactional_recovery_enabled' => ['nullable', 'boolean'],
            'surface' => ['nullable', 'string', 'max:64'],
            'order_no' => ['nullable', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'share_id' => ['nullable', 'string', 'max:128'],
            'compare_invite_id' => ['nullable', 'string', 'max:128'],
            'share_click_id' => ['nullable', 'string', 'max:128'],
            'entrypoint' => ['nullable', 'string', 'max:128'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:512'],
            'utm.medium' => ['nullable', 'string', 'max:512'],
            'utm.campaign' => ['nullable', 'string', 'max:512'],
            'utm.term' => ['nullable', 'string', 'max:512'],
            'utm.content' => ['nullable', 'string', 'max:512'],
            'utm_source' => ['nullable', 'string', 'max:512'],
            'utm_medium' => ['nullable', 'string', 'max:512'],
            'utm_campaign' => ['nullable', 'string', 'max:512'],
            'utm_term' => ['nullable', 'string', 'max:512'],
            'utm_content' => ['nullable', 'string', 'max:512'],
        ]);
        $attribution = $this->extractAttribution($request, $payload);

        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $contactEmail = $this->resolveContactEmail($payload, $userId !== null ? (string) $userId : null);
        if ($userId === null && $anonId === null && $contactEmail === null) {
            abort(422, 'email is required.');
        }

        $subscriberCapture = $contactEmail !== null
            ? $this->emailCaptures->capture(
                $contactEmail,
                $this->extractEmailCaptureContext($payload, $attribution)
            )
            : [];
        $emailCapture = $this->buildCheckoutEmailCapture($contactEmail, $payload, $attribution, $subscriberCapture);

        $existingOrderNo = trim((string) ($payload['order_no'] ?? ''));
        if ($existingOrderNo !== '') {
            $existing = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $existingOrderNo);
            if ($existing['ok'] ?? false) {
                $order = $existing['order'];
                if ($attribution !== [] || $emailCapture !== []) {
                    $this->orders->mergeCheckoutContext(
                        (string) ($order->order_no ?? $existingOrderNo),
                        $orgId,
                        $attribution,
                        $emailCapture
                    );
                }

                $provider = strtolower(trim((string) ($order->provider ?? '')));
                if ($provider === '') {
                    $provider = (string) $this->paymentRouter->primaryProviderForRegion($this->regionContext->region());
                }
                $payment = $this->buildOrderPaymentPayload(
                    $request,
                    $order,
                    $this->normalizePublicOrderStatus((string) ($order->status ?? '')) === 'pending'
                );

                return response()->json([
                    'ok' => true,
                    'order_no' => $order->order_no ?? $existingOrderNo,
                    'attempt_id' => $order->target_attempt_id ?? null,
                    'status' => $this->normalizePublicOrderStatus((string) ($order->status ?? '')),
                    'message' => $this->publicOrderMessage((string) ($order->status ?? '')),
                    'provider' => $payment['provider'] ?? $provider,
                    'pay' => $payment['pay'],
                    'checkout_url' => $payment['checkout_url'],
                ]);
            }
        }

        $sku = trim((string) ($payload['sku'] ?? ''));
        if ($sku === '') {
            $sku = $this->resolveDefaultCheckoutSku($orgId);
        }
        if ($sku === '') {
            abort(422, 'sku unavailable.');
        }

        $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
        if ($provider === '') {
            $provider = (string) $this->paymentRouter->primaryProviderForRegion($this->regionContext->region());
        }
        if ($provider === '' || ! in_array($provider, $this->allowedProviders(), true)) {
            abort(422, 'provider unavailable.');
        }
        $this->guardStubProvider($request, $provider);

        $idempotencyKey = $this->resolveIdempotencyKey($request, $payload);
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));

        $created = $this->orders->createOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            $sku,
            1,
            $attemptId !== '' ? $attemptId : null,
            $provider,
            $idempotencyKey,
            $contactEmail,
            $this->resolveRequestId($request),
            $attribution,
            $emailCapture
        );

        if (! ($created['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($created, 'error_code', data_get($created, 'error', '')));
            $message = trim((string) ($created['message'] ?? 'request failed.'));
            abort($status, $message);
        }

        $orderNoForAttribution = trim((string) data_get($created, 'order.order_no', $created['order_no'] ?? ''));
        if ($orderNoForAttribution !== '' && ($attribution !== [] || $emailCapture !== [])) {
            $this->orders->mergeCheckoutContext($orderNoForAttribution, $orgId, $attribution, $emailCapture);
        }

        $order = $created['order'] ?? null;
        $orderNo = trim((string) data_get($order, 'order_no', $created['order_no'] ?? ''));
        $attemptIdFromOrder = trim((string) data_get($order, 'target_attempt_id', $attemptId));
        $amountCents = (int) data_get($order, 'amount_cents', 0);
        $currency = strtoupper(trim((string) data_get($order, 'currency', 'USD')));
        $description = strtoupper(trim((string) data_get($order, 'sku', $sku)));
        if ($description === '') {
            $description = 'FermatMind Order';
        }

        $payAction = $this->resolveCheckoutPayAction(
            $provider,
            $orderNo,
            $attemptIdFromOrder !== '' ? $attemptIdFromOrder : null,
            $amountCents,
            $currency,
            $description,
            $contactEmail,
            (string) $request->userAgent()
        );

        if (($payAction['ok'] ?? true) !== true) {
            $status = (int) ($payAction['status'] ?? 502);
            abort($status >= 400 ? $status : 502, (string) ($payAction['message'] ?? 'payment unavailable.'));
        }

        $payType = strtolower(trim((string) ($payAction['type'] ?? '')));
        $payValue = trim((string) ($payAction['value'] ?? ''));
        $checkoutUrl = null;
        $payPayload = null;
        if ($payType !== '' && $payValue !== '') {
            $payPayload = [
                'type' => $payType,
                'value' => $payValue,
                'provider' => $provider,
            ];
            if ($payType === 'redirect') {
                $checkoutUrl = $payValue;
            }
        }

        if ($order !== null) {
            $this->persistOrderPaymentPayload(
                $order,
                $this->resolvePaymentActionScene((string) $request->userAgent()),
                [
                    'provider' => $provider,
                    'pay' => $payPayload,
                    'checkout_url' => $checkoutUrl,
                ]
            );
        }

        return response()->json([
            'ok' => true,
            'order_no' => $orderNo !== '' ? $orderNo : ($created['order_no'] ?? null),
            'attempt_id' => $attemptIdFromOrder !== '' ? $attemptIdFromOrder : null,
            'provider' => $provider,
            'status' => 'pending',
            'message' => 'Order created, waiting for payment.',
            'pay' => $payPayload,
            'checkout_url' => $checkoutUrl,
        ]);
    }

    /**
     * POST /api/v0.3/orders/lookup
     */
    public function lookup(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_no' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:320'],
        ]);

        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $contactEmailHash = $this->hashContactEmail((string) ($payload['email'] ?? ''));

        if ($userId === null && $anonId === null && $contactEmailHash === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'message' => 'email is required when identity is missing.',
            ], 422);
        }

        $order = $this->orders->findLookupOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $orderNo,
            $contactEmailHash
        );
        if (! $order) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        $delivery = $this->buildOrderDelivery($order);
        $status = $this->normalizePublicOrderStatus((string) ($order->status ?? ''));
        $payment = $this->buildOrderPaymentPayload(
            $request,
            $order,
            $status === 'pending'
        );

        $payload = [
            'ok' => true,
            'order_no' => $order->order_no ?? $orderNo,
            'status' => $status,
            'attempt_id' => $delivery['attempt_id'],
            'provider' => $payment['provider'],
            'pay' => $payment['pay'],
            'checkout_url' => $payment['checkout_url'],
            'delivery' => $delivery['delivery'],
        ];

        $mbtiAccessHub = $this->mbtiAccessHubBuilder->buildForLookupHit($order);
        if ($mbtiAccessHub !== null) {
            $payload[ReportAccess::ACCESS_HUB_KEY] = $mbtiAccessHub;
        }

        return response()->json($payload);
    }

    /**
     * POST /api/v0.3/orders/{order_no}/resend
     */
    public function resend(Request $request, string $order_no): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $resent = $this->orders->resendDelivery($orgId, $userId !== null ? (string) $userId : null, $anonId, $order_no);
        if (! ($resent['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Delivery notice has been queued.',
        ]);
    }

    /**
     * GET /api/v0.3/orders/{order_no}/pay/alipay
     */
    public function launchAlipay(Request $request, string $order_no): Response
    {
        if (! $this->isProviderEnabled('alipay')) {
            abort(404);
        }

        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $found = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $order_no);
        if (! ($found['ok'] ?? false)) {
            abort(404);
        }

        $order = $found['order'];
        $provider = strtolower(trim((string) ($order->provider ?? '')));
        if ($provider !== 'alipay') {
            abort(404);
        }

        $scene = strtolower(trim((string) $request->query('scene', 'desktop')));
        if (! in_array($scene, ['desktop', 'mobile'], true)) {
            $scene = 'desktop';
        }

        try {
            $launch = $this->alipayCheckout->launch((array) $order, $scene);
            $response = $this->toHttpResponse($launch);
            if ($response instanceof Response) {
                return $response;
            }
        } catch (\Throwable $e) {
            Log::error('ALIPAY_LAUNCH_FAILED', [
                'order_no' => $order_no,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok' => false,
            'error_code' => 'PAYMENT_PROVIDER_ERROR',
            'message' => 'failed to launch alipay checkout.',
        ], 502);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveCheckoutPayAction(
        string $provider,
        string $orderNo,
        ?string $attemptId,
        int $amountCents,
        string $currency,
        string $description,
        ?string $contactEmail,
        string $userAgent
    ): array {
        return match ($provider) {
            'lemonsqueezy' => $this->resolveLemonCheckoutAction(
                $orderNo,
                $attemptId,
                $amountCents,
                $currency,
                $contactEmail
            ),
            'wechatpay' => $this->wechatPayCheckout->createCheckoutAction(
                $orderNo,
                $description,
                $amountCents,
                $currency,
                $userAgent
            ),
            'alipay' => $this->alipayCheckout->createCheckoutAction(
                $orderNo,
                $userAgent
            ),
            default => ['ok' => true],
        };
    }

    /**
     * @return array{
     *     provider:?string,
     *     pay:?array{type:string,value:string,provider:string},
     *     checkout_url:?string
     * }
     */
    private function buildOrderPaymentPayload(Request $request, object $order, bool $includePaymentAction): array
    {
        $provider = strtolower(trim((string) ($order->provider ?? '')));
        if ($provider === '') {
            $provider = (string) $this->paymentRouter->primaryProviderForRegion($this->regionContext->region());
        }

        $normalizedProvider = $provider !== '' ? $provider : null;
        if (! $includePaymentAction) {
            return [
                'provider' => $normalizedProvider,
                'pay' => null,
                'checkout_url' => null,
            ];
        }

        $scene = $this->resolvePaymentActionScene((string) $request->userAgent());
        $cached = $this->resolveCachedOrderPaymentPayload($order, $normalizedProvider, $scene);
        if ($cached !== null) {
            return $cached;
        }

        if ($normalizedProvider === null || ! $this->isProviderEnabled($normalizedProvider)) {
            return [
                'provider' => $normalizedProvider,
                'pay' => null,
                'checkout_url' => null,
            ];
        }

        $orderNo = trim((string) ($order->order_no ?? ''));
        if ($orderNo === '') {
            return [
                'provider' => $normalizedProvider,
                'pay' => null,
                'checkout_url' => null,
            ];
        }

        $amountCents = (int) ($order->amount_cents ?? $order->amount_total ?? 0);
        $currency = strtoupper(trim((string) ($order->currency ?? 'USD')));
        $description = strtoupper(trim((string) ($order->sku ?? '')));
        if ($description === '') {
            $description = 'FermatMind Order';
        }

        $payAction = $this->resolveCheckoutPayAction(
            $normalizedProvider,
            $orderNo,
            $this->trimNullableString($order->target_attempt_id ?? null),
            $amountCents,
            $currency,
            $description,
            null,
            (string) $request->userAgent()
        );

        if (($payAction['ok'] ?? true) !== true) {
            Log::warning('ORDER_PAYMENT_ACTION_UNAVAILABLE', [
                'order_no' => $orderNo,
                'provider' => $normalizedProvider,
                'status' => (string) ($order->status ?? ''),
                'reason' => $payAction['error_code'] ?? $payAction['message'] ?? 'unknown',
            ]);

            return [
                'provider' => $normalizedProvider,
                'pay' => null,
                'checkout_url' => null,
            ];
        }

        $presented = $this->presentCheckoutPayAction($normalizedProvider, $payAction);
        $this->persistOrderPaymentPayload($order, $scene, $presented);

        return $presented;
    }

    /**
     * @param  array<string,mixed>  $payAction
     * @return array{
     *     provider:?string,
     *     pay:?array{type:string,value:string,provider:string},
     *     checkout_url:?string
     * }
     */
    private function presentCheckoutPayAction(?string $provider, array $payAction): array
    {
        $payType = strtolower(trim((string) ($payAction['type'] ?? '')));
        $payValue = trim((string) ($payAction['value'] ?? ''));

        if ($payType === '' || $payValue === '') {
            return [
                'provider' => $provider,
                'pay' => null,
                'checkout_url' => null,
            ];
        }

        return [
            'provider' => $provider,
            'pay' => [
                'type' => $payType,
                'value' => $payValue,
                'provider' => $provider ?? '',
            ],
            'checkout_url' => $payType === 'redirect' ? $payValue : null,
        ];
    }

    /**
     * @return array{
     *     provider:?string,
     *     pay:?array{type:string,value:string,provider:string},
     *     checkout_url:?string
     * }|null
     */
    private function resolveCachedOrderPaymentPayload(object $order, ?string $provider, string $scene): ?array
    {
        if ($provider === null || $scene === '') {
            return null;
        }

        $meta = $this->decodeMeta($order->meta_json ?? null);
        $scenes = $meta['payment_action_cache'][$provider] ?? null;
        if (! is_array($scenes)) {
            return null;
        }

        $payload = $scenes[$scene] ?? null;
        if (! is_array($payload)) {
            return null;
        }

        $pay = $payload['pay'] ?? null;
        if (! is_array($pay)) {
            return null;
        }

        $payType = strtolower(trim((string) ($pay['type'] ?? '')));
        $payValue = trim((string) ($pay['value'] ?? ''));
        if ($payType === '' || $payValue === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'pay' => [
                'type' => $payType,
                'value' => $payValue,
                'provider' => $provider,
            ],
            'checkout_url' => $payType === 'redirect'
                ? ($payload['checkout_url'] ?? $payValue)
                : ($payload['checkout_url'] ?? null),
        ];
    }

    /**
     * @param  array{
     *     provider:?string,
     *     pay:?array{type:string,value:string,provider:string},
     *     checkout_url:?string
     * }  $payload
     */
    private function persistOrderPaymentPayload(object $order, string $scene, array $payload): void
    {
        $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
        $pay = $payload['pay'] ?? null;
        $orderNo = trim((string) ($order->order_no ?? ''));
        $orgId = (int) ($order->org_id ?? 0);

        if ($provider === '' || $scene === '' || $orderNo === '' || ! is_array($pay)) {
            return;
        }

        $payType = strtolower(trim((string) ($pay['type'] ?? '')));
        $payValue = trim((string) ($pay['value'] ?? ''));
        if ($payType === '' || $payValue === '') {
            return;
        }

        $meta = $this->decodeMeta($order->meta_json ?? null);
        $cache = is_array($meta['payment_action_cache'] ?? null) ? $meta['payment_action_cache'] : [];
        $providerCache = is_array($cache[$provider] ?? null) ? $cache[$provider] : [];
        $providerCache[$scene] = [
            'provider' => $provider,
            'pay' => [
                'type' => $payType,
                'value' => $payValue,
                'provider' => $provider,
            ],
            'checkout_url' => $payload['checkout_url'] ?? null,
            'cached_at' => now()->toIso8601String(),
        ];
        $cache[$provider] = $providerCache;
        $meta['payment_action_cache'] = $cache;

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->update([
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function resolvePaymentActionScene(string $userAgent): string
    {
        return preg_match('/android|iphone|ipad|ipod|mobile|micromessenger/i', $userAgent) === 1
            ? 'mobile'
            : 'desktop';
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

    private function toHttpResponse(mixed $gatewayResponse): ?Response
    {
        if ($gatewayResponse instanceof Response) {
            return $gatewayResponse;
        }

        if ($gatewayResponse instanceof PsrResponseInterface) {
            $headers = [];
            foreach ($gatewayResponse->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            return response((string) $gatewayResponse->getBody(), $gatewayResponse->getStatusCode(), $headers);
        }

        if (is_object($gatewayResponse) && method_exists($gatewayResponse, 'getContent')) {
            $content = (string) $gatewayResponse->getContent();
            $status = method_exists($gatewayResponse, 'getStatusCode') ? (int) $gatewayResponse->getStatusCode() : 200;

            return response($content, $status);
        }

        if (is_string($gatewayResponse) && trim($gatewayResponse) !== '') {
            return response($gatewayResponse, 200);
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveLemonCheckoutAction(
        string $orderNo,
        ?string $attemptId,
        int $amountCents,
        string $currency,
        ?string $contactEmail
    ): array {
        $result = $this->lemonSqueezyCheckout->createCheckout(
            $orderNo,
            $attemptId,
            $amountCents,
            $currency,
            $contactEmail
        );
        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        return [
            'ok' => true,
            'type' => 'redirect',
            'value' => (string) ($result['url'] ?? ''),
        ];
    }

    private function mapErrorStatus(string $code): int
    {
        return match ($code) {
            'SKU_NOT_FOUND', 'ORDER_NOT_FOUND' => 404,
            'EMAIL_REQUIRED' => 422,
            'TABLE_MISSING' => 500,
            default => 400,
        };
    }

    private function resolveAnonId(Request $request): ?string
    {
        $candidates = [
            $this->orgContext->anonId(),
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->attributes->get('client_anon_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolveIdempotencyKey(Request $request, array $payload): ?string
    {
        $header = trim((string) $request->header('Idempotency-Key', ''));
        if ($header !== '') {
            return $header;
        }

        $body = trim((string) ($payload['idempotency_key'] ?? ''));

        return $body !== '' ? $body : null;
    }

    private function resolveProvider(array $payload, ?string $providerFromRoute): string
    {
        $provider = $this->resolveRequestedProvider($payload, $providerFromRoute);

        return in_array($provider, $this->allowedProviders(), true) ? $provider : '';
    }

    private function resolveRequestedProvider(array $payload, ?string $providerFromRoute): string
    {
        $provider = trim((string) ($providerFromRoute ?? ''));
        if ($provider === '') {
            $provider = trim((string) ($payload['provider'] ?? ''));
        }
        if ($provider === '') {
            $provider = (string) $this->paymentRouter->primaryProviderForRegion($this->regionContext->region());
        }

        return strtolower(trim($provider));
    }

    private function guardStubProvider(Request $request, string $provider): void
    {
        if ($provider !== 'stub' || $this->isStubEnabled()) {
            return;
        }

        Log::warning('SECURITY_STUB_PROVIDER_BLOCKED', [
            'request_id' => $this->resolveRequestId($request),
            'provider' => $provider,
            'ip' => $request->ip(),
        ]);

        abort(404);
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

    private function isProviderEnabled(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), $this->allowedProviders(), true);
    }

    private function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-ID', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->input('request_id', ''));

        return $requestId !== '' ? $requestId : (string) Str::uuid();
    }

    private function resolveDefaultCheckoutSku(int $orgId): string
    {
        $items = $this->skus->listActiveSkus(null, $orgId);
        if ($items === []) {
            return '';
        }

        usort($items, static function (array $left, array $right): int {
            $priceCompare = ((int) ($left['price_cents'] ?? 0)) <=> ((int) ($right['price_cents'] ?? 0));
            if ($priceCompare !== 0) {
                return $priceCompare;
            }

            return strcmp(
                strtoupper(trim((string) ($left['sku'] ?? ''))),
                strtoupper(trim((string) ($right['sku'] ?? '')))
            );
        });

        $sku = trim((string) ($items[0]['sku'] ?? ''));

        return $sku !== '' ? strtoupper($sku) : '';
    }

    private function resolveContactEmail(array $payload, ?string $userId): ?string
    {
        $inputEmail = $this->normalizeEmail((string) ($payload['email'] ?? ''));
        if ($inputEmail !== null) {
            return $inputEmail;
        }

        $uid = trim((string) $userId);
        if ($uid === '' || preg_match('/^\d+$/', $uid) !== 1) {
            return null;
        }

        $dbEmail = DB::table('users')->where('id', (int) $uid)->value('email');

        return $this->normalizeEmail((string) ($dbEmail ?? ''));
    }

    private function hashContactEmail(string $email): ?string
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        return hash('sha256', $normalized);
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function trimNullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array{attempt_id:?string,delivery:array<string,mixed>}
     */
    private function buildOrderDelivery(object $order): array
    {
        return $this->orders->presentOrderDelivery($order);
    }

    private function normalizePublicOrderStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'paid', 'fulfilled' => 'paid',
            'failed' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    private function publicOrderMessage(string $status): string
    {
        return match ($this->normalizePublicOrderStatus($status)) {
            'paid' => 'Payment confirmed.',
            'failed' => 'Payment failed.',
            'canceled' => 'Order canceled.',
            'refunded' => 'Order refunded.',
            default => 'Confirming your payment...',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractAttribution(Request $request, array $payload): array
    {
        $attribution = [];

        foreach ([
            'share_id' => 128,
            'compare_invite_id' => 128,
            'share_click_id' => 128,
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->truncateNullableString($payload[$field] ?? $request->input($field), $maxLength);
            if ($value !== null) {
                $attribution[$field] = $value;
            }
        }

        $utm = $this->normalizeUtm($payload['utm'] ?? $request->input('utm'));
        $flatUtm = $this->normalizeFlatUtm($request, $payload);
        $mergedUtm = array_replace($utm ?? [], $flatUtm);
        if ($mergedUtm !== []) {
            $attribution['utm'] = $mergedUtm;
        }

        return $attribution;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $attribution
     * @return array<string,mixed>
     */
    private function extractEmailCaptureContext(array $payload, array $attribution): array
    {
        $context = [];

        foreach ([
            'attempt_id' => 64,
            'order_no' => 64,
            'surface' => 64,
        ] as $field => $maxLength) {
            $value = $this->truncateNullableString($payload[$field] ?? null, $maxLength);
            if ($value !== null) {
                $context[$field] = $value;
            }
        }

        foreach (['marketing_consent', 'transactional_recovery_enabled'] as $field) {
            if (array_key_exists($field, $payload)) {
                $context[$field] = (bool) $payload[$field];
            }
        }

        return array_replace($context, $attribution);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $attribution
     * @param  array<string,mixed>  $subscriberCapture
     * @return array<string,mixed>
     */
    private function buildCheckoutEmailCapture(
        ?string $contactEmail,
        array $payload,
        array $attribution,
        array $subscriberCapture
    ): array {
        if ($contactEmail === null) {
            return [];
        }

        $emailCapture = [
            'contact_email_hash' => hash('sha256', mb_strtolower(trim($contactEmail), 'UTF-8')),
            'subscriber_status' => (string) ($subscriberCapture['subscriber_status'] ?? 'active'),
            'marketing_consent' => (bool) ($subscriberCapture['marketing_consent'] ?? false),
            'transactional_recovery_enabled' => (bool) ($subscriberCapture['transactional_recovery_enabled'] ?? true),
        ];

        $capturedAt = trim((string) ($subscriberCapture['captured_at'] ?? ''));
        if ($capturedAt !== '') {
            $emailCapture['captured_at'] = $capturedAt;
        }

        $surface = $this->truncateNullableString($payload['surface'] ?? null, 64);
        if ($surface !== null) {
            $emailCapture['surface'] = $surface;
        }

        $attemptId = $this->truncateNullableString($payload['attempt_id'] ?? null, 64);
        if ($attemptId !== null) {
            $emailCapture['attempt_id'] = $attemptId;
        }

        return array_replace($emailCapture, $attribution);
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeUtm(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $candidate = $this->truncateNullableString($value[$key] ?? null, 512);
            if ($candidate !== null) {
                $normalized[$key] = $candidate;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function normalizeFlatUtm(Request $request, array $payload): array
    {
        $normalized = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $candidate = $this->truncateNullableString($payload['utm_'.$key] ?? $request->input('utm_'.$key), 512);
            if ($candidate !== null) {
                $normalized[$key] = $candidate;
            }
        }

        return $normalized;
    }

    private function truncateNullableString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }
}
