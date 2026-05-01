<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BigFive\BigFivePublicFormSummaryBuilder;
use App\Services\Commerce\Checkout\AlipayCheckoutService;
use App\Services\Commerce\Checkout\LemonSqueezyCheckoutService;
use App\Services\Commerce\Checkout\WechatPayCheckoutService;
use App\Services\Commerce\MbtiAccessHubBuilder;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\SkuCatalog;
use App\Services\Email\EmailCaptureService;
use App\Services\Mbti\MbtiPublicFormSummaryBuilder;
use App\Services\Payments\PaymentProviderRegistry;
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
use Yansongda\Pay\Pay;

class CommerceController extends Controller
{
    public function __construct(
        private OrgContext $orgContext,
        private RegionContext $regionContext,
        private OrderManager $orders,
        private EmailCaptureService $emailCaptures,
        private SkuCatalog $skus,
        private PaymentRouter $paymentRouter,
        private PaymentProviderRegistry $paymentProviders,
        private MbtiAccessHubBuilder $mbtiAccessHubBuilder,
        private MbtiPublicFormSummaryBuilder $mbtiPublicFormSummaryBuilder,
        private BigFivePublicFormSummaryBuilder $bigFivePublicFormSummaryBuilder,
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
            'channel' => ['nullable', 'string', 'max:64'],
            'provider_app' => ['nullable', 'string', 'max:128'],
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
            $this->resolveRequestId($request),
            [],
            [],
            $this->resolveOrderLedgerContext(
                $request,
                $payload,
                $payload['target_attempt_id'] ?? null,
                $provider
            )
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
        $requestedPaymentRecoveryToken = $this->resolvePaymentRecoveryToken($request);
        $result = $this->orders->getOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $order_no,
            false,
            $requestedPaymentRecoveryToken
        );
        if (! ($result['ok'] ?? false)) {
            $errorCode = (string) data_get($result, 'error_code', data_get($result, 'error', ''));
            $message = trim((string) ($result['message'] ?? ''));
            if (in_array($errorCode, ['PAYMENT_RECOVERY_TOKEN_INVALID', 'PAYMENT_RECOVERY_TOKEN_EXPIRED'], true)) {
                return response()->json([
                    'ok' => false,
                    'error_code' => $errorCode,
                    'message' => $message !== '' ? $message : 'payment recovery token invalid.',
                ], 403);
            }

            $status = $this->mapErrorStatus($errorCode);
            abort($status, $message !== '' ? $message : 'request failed.');
        }

        $order = $result['order'];
        $ownershipVerified = (bool) ($result['ownership_verified'] ?? true);
        $paymentRecoveryVerified = (bool) ($result['payment_recovery_verified'] ?? false);
        $status = $this->resolvePublicOrderStatus($order);
        $message = $this->publicOrderMessage($order);
        $delivery = $this->buildOrderDelivery($order);
        $paymentAttemptSummary = $this->orders->paymentAttemptSummary(
            (string) ($order->order_no ?? ''),
            (int) ($order->org_id ?? $orgId)
        );
        $paymentRecoveryToken = $paymentRecoveryVerified && $requestedPaymentRecoveryToken !== null
            ? $requestedPaymentRecoveryToken
            : $this->orders->issuePaymentRecoveryToken($order);
        $recoveryUrls = $this->orders->presentPaymentRecoveryUrls(
            $order,
            $paymentRecoveryToken,
            $this->resolveRequestedLocale($request)
        );
        $payment = $this->buildOrderPaymentPayload(
            $request,
            $order,
            $status === 'pending' && ($request->boolean('include_payment_action') || $paymentRecoveryVerified)
        );
        $exactResultEntry = $this->mbtiAccessHubBuilder->buildExactResultEntryForOrder($order);
        $big5FormSummary = $this->big5FormSummaryForOrder($order, $request);
        if (is_array($exactResultEntry) && is_array($big5FormSummary)) {
            $exactResultEntry['big5_form_v1'] = $big5FormSummary;
        }
        if ($status === 'pending') {
            $order = $this->orders->findOrderByOrderNo((string) ($order->order_no ?? ''), $orgId) ?? $order;
            $status = $this->resolvePublicOrderStatus($order);
            $message = $this->publicOrderMessage($order);
        }

        $payload = [
            'ok' => true,
            'ownership_verified' => $ownershipVerified,
            'order_no' => $order->order_no ?? null,
            'attempt_id' => $delivery['attempt_id'],
            'status' => $status,
            'payment_state' => $this->orders->resolvedPaymentState($order),
            'grant_state' => $this->orders->resolvedGrantState($order),
            'message' => $message,
            'amount_cents' => $order->amount_cents ?? $order->amount_total ?? null,
            'currency' => $order->currency ?? null,
            'provider' => $payment['provider'],
            'channel' => $order->channel ?? null,
            'last_reconciled_at' => $order->last_reconciled_at ?? null,
            'payment_recovery_token' => $paymentRecoveryToken,
            'wait_url' => $recoveryUrls['wait_url'],
            'result_url' => $recoveryUrls['result_url'],
            'pay' => $payment['pay'],
            'checkout_url' => $payment['checkout_url'],
            'payment_attempts_count' => $paymentAttemptSummary['count'],
            'latest_payment_attempt' => $paymentAttemptSummary['latest'],
            'delivery' => $delivery['delivery'],
            'exact_result_entry' => $exactResultEntry,
            'mbti_form_v1' => $this->mbtiFormSummaryForOrder($order, $request),
            'big5_form_v1' => $big5FormSummary,
        ];

        if ($ownershipVerified) {
            $payload['order'] = $order;

            $mbtiAccessHub = $this->mbtiAccessHubBuilder->buildForOrderContext($order);
            if ($mbtiAccessHub !== null) {
                $payload[ReportAccess::ACCESS_HUB_KEY] = $mbtiAccessHub;
            }
        }

        return response()->json($payload);
    }

    /**
     * GET /api/v0.3/orders/{order_no}/recover/alipay-return
     */
    public function recoverAlipayReturn(Request $request, string $order_no): JsonResponse
    {
        if (! $this->paymentProviders->isEnabled('alipay')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'PROVIDER_DISABLED',
                'message' => 'provider not enabled.',
            ], 404);
        }

        if (! class_exists(Pay::class)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_NOT_INSTALLED',
                'message' => 'alipay sdk is not installed.',
            ], 503);
        }

        try {
            Pay::config(config('pay'));
            $payload = $this->normalizeSdkPayload(Pay::alipay()->callback($request->query()));
        } catch (\Throwable $e) {
            Log::warning('ALIPAY_RETURN_RECOVERY_INVALID_SIGNATURE', [
                'order_no' => $order_no,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_SIGNATURE',
                'message' => 'invalid signature.',
            ], 400);
        }

        $normalizedRouteOrderNo = trim($order_no);
        $normalizedReturnOrderNo = $this->trimNullableString($payload['out_trade_no'] ?? null);
        if ($normalizedRouteOrderNo === '' || $normalizedReturnOrderNo === null || $normalizedRouteOrderNo !== $normalizedReturnOrderNo) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_MISMATCH',
                'message' => 'returned order does not match the requested order.',
            ], 422);
        }

        $order = $this->orders->findOrderByOrderNo($normalizedRouteOrderNo, $this->orgContext->orgId());
        if ($order === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        $bindingError = $this->validateAlipayReturnRecoveryBinding($payload, $order);
        if ($bindingError !== null) {
            Log::warning('ALIPAY_RETURN_RECOVERY_BINDING_REJECTED', [
                'order_no' => $normalizedRouteOrderNo,
                'reason' => $bindingError['error_code'],
            ]);

            return response()->json([
                'ok' => false,
                'error_code' => $bindingError['error_code'],
                'message' => $bindingError['message'],
            ], $bindingError['status']);
        }

        $paymentRecoveryToken = $this->orders->issuePaymentRecoveryToken($order);
        $recoveryUrls = $this->orders->presentPaymentRecoveryUrls(
            $order,
            $paymentRecoveryToken,
            $this->resolveRequestedLocale($request)
        );

        return response()->json([
            'ok' => true,
            'order_no' => $normalizedRouteOrderNo,
            'payment_recovery_token' => $paymentRecoveryToken,
            'wait_url' => $recoveryUrls['wait_url'],
            'result_url' => $recoveryUrls['result_url'],
        ]);
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
            'channel' => ['nullable', 'string', 'max:64'],
            'provider_app' => ['nullable', 'string', 'max:128'],
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
                $paymentRecoveryToken = $this->orders->issuePaymentRecoveryToken($order);
                $recoveryUrls = $this->orders->presentPaymentRecoveryUrls(
                    $order,
                    $paymentRecoveryToken,
                    $this->resolveRequestedLocale($request)
                );
                $payment = $this->buildOrderPaymentPayload(
                    $request,
                    $order,
                    $this->resolvePublicOrderStatus($order) === 'pending'
                );
                $order = $this->orders->findOrderByOrderNo((string) ($order->order_no ?? $existingOrderNo), $orgId) ?? $order;
                $paymentAttemptSummary = $this->orders->paymentAttemptSummary(
                    (string) ($order->order_no ?? $existingOrderNo),
                    (int) ($order->org_id ?? $orgId)
                );

                return response()->json([
                    'ok' => true,
                    'order_no' => $order->order_no ?? $existingOrderNo,
                    'attempt_id' => $order->target_attempt_id ?? null,
                    'status' => $this->resolvePublicOrderStatus($order),
                    'payment_state' => $this->orders->resolvedPaymentState($order),
                    'grant_state' => $this->orders->resolvedGrantState($order),
                    'message' => $this->publicOrderMessage($order),
                    'provider' => $payment['provider'] ?? $provider,
                    'channel' => $order->channel ?? null,
                    'last_reconciled_at' => $order->last_reconciled_at ?? null,
                    'payment_recovery_token' => $paymentRecoveryToken,
                    'wait_url' => $recoveryUrls['wait_url'],
                    'result_url' => $recoveryUrls['result_url'],
                    'pay' => $payment['pay'],
                    'checkout_url' => $payment['checkout_url'],
                    'payment_attempts_count' => $paymentAttemptSummary['count'],
                    'latest_payment_attempt' => $paymentAttemptSummary['latest'],
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
        $ledgerContext = $this->resolveOrderLedgerContext(
            $request,
            $payload,
            $attemptId !== '' ? $attemptId : null,
            $provider
        );

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
            $emailCapture,
            $ledgerContext
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
        $paymentScene = $this->resolvePaymentActionScene((string) $request->userAgent());
        $paymentAttempt = $order !== null
            ? $this->createPaymentAttemptForOrder($order, $provider, $paymentScene)
            : null;

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
            if ($paymentAttempt !== null) {
                $this->orders->advancePaymentAttempt((string) ($paymentAttempt->id ?? ''), [
                    'state' => \App\Models\PaymentAttempt::STATE_FAILED,
                    'last_error_code' => $this->trimNullableString($payAction['error_code'] ?? null),
                    'last_error_message' => $this->trimNullableString($payAction['message'] ?? null),
                ]);
            }
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
            $freshOrder = $this->orders->findOrderByOrderNo(
                (string) ($order->order_no ?? ''),
                (int) ($order->org_id ?? $orgId)
            ) ?? $order;
            $this->orders->markPaymentPending(
                (string) ($freshOrder->order_no ?? ''),
                (int) ($freshOrder->org_id ?? $orgId),
                $ledgerContext['channel'] ?? null,
                $ledgerContext['provider_app'] ?? null
            );
            $paymentAttempt = $this->recordPaymentAttemptPresentation(
                $paymentAttempt,
                $freshOrder,
                $paymentScene,
                $payAction,
                $payPayload,
                $checkoutUrl
            );
            $this->persistOrderPaymentPayload(
                $freshOrder,
                $paymentScene,
                [
                    'provider' => $provider,
                    'pay' => $payPayload,
                    'checkout_url' => $checkoutUrl,
                ],
                $this->trimNullableString($paymentAttempt->id ?? null)
            );
            $order = $freshOrder;
        }

        $paymentRecoveryToken = $order !== null ? $this->orders->issuePaymentRecoveryToken($order) : null;
        $recoveryUrls = $order !== null
            ? $this->orders->presentPaymentRecoveryUrls(
                $order,
                $paymentRecoveryToken,
                $this->resolveRequestedLocale($request)
            )
            : ['wait_url' => null, 'result_url' => null];
        $presentedPayment = [
            'provider' => $provider,
            'pay' => $payPayload,
            'checkout_url' => $checkoutUrl,
        ];

        return response()->json([
            'ok' => true,
            'order_no' => $orderNo !== '' ? $orderNo : ($created['order_no'] ?? null),
            'attempt_id' => $attemptIdFromOrder !== '' ? $attemptIdFromOrder : null,
            'provider' => $provider,
            'status' => 'pending',
            'payment_state' => 'pending',
            'grant_state' => 'not_started',
            'message' => 'Order created, waiting for payment.',
            'channel' => $ledgerContext['channel'] ?? null,
            'last_reconciled_at' => $order->last_reconciled_at ?? null,
            'payment_recovery_token' => $paymentRecoveryToken,
            'wait_url' => $recoveryUrls['wait_url'],
            'result_url' => $recoveryUrls['result_url'],
            'pay' => $presentedPayment['pay'],
            'checkout_url' => $presentedPayment['checkout_url'],
            'payment_attempts_count' => $order !== null
                ? $this->orders->paymentAttemptSummary(
                    (string) ($order->order_no ?? ''),
                    (int) ($order->org_id ?? 0)
                )['count']
                : 0,
            'latest_payment_attempt' => $order !== null
                ? $this->orders->paymentAttemptSummary(
                    (string) ($order->order_no ?? ''),
                    (int) ($order->org_id ?? 0)
                )['latest']
                : null,
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
        $status = $this->resolvePublicOrderStatus($order);
        $paymentRecoveryToken = $status === 'pending'
            ? $this->orders->issuePaymentRecoveryToken($order)
            : null;
        $recoveryUrls = $paymentRecoveryToken !== null
            ? $this->orders->presentPaymentRecoveryUrls(
                $order,
                $paymentRecoveryToken,
                $this->resolveRequestedLocale($request)
            )
            : ['wait_url' => null, 'result_url' => $delivery['delivery']['result_url'] ?? null];
        $payment = $this->buildOrderPaymentPayload(
            $request,
            $order,
            $status === 'pending'
        );
        $exactResultEntry = $this->mbtiAccessHubBuilder->buildExactResultEntryForOrder($order);
        $big5FormSummary = $this->big5FormSummaryForOrder($order, $request);
        if (is_array($exactResultEntry) && is_array($big5FormSummary)) {
            $exactResultEntry['big5_form_v1'] = $big5FormSummary;
        }
        if ($status === 'pending') {
            $order = $this->orders->findOrderByOrderNo((string) ($order->order_no ?? $orderNo), $orgId) ?? $order;
            $status = $this->resolvePublicOrderStatus($order);
        }
        $paymentAttemptSummary = $this->orders->paymentAttemptSummary(
            (string) ($order->order_no ?? $orderNo),
            (int) ($order->org_id ?? $orgId)
        );

        $payload = [
            'ok' => true,
            'order_no' => $order->order_no ?? $orderNo,
            'status' => $status,
            'payment_state' => $this->orders->resolvedPaymentState($order),
            'grant_state' => $this->orders->resolvedGrantState($order),
            'attempt_id' => $delivery['attempt_id'],
            'provider' => $payment['provider'],
            'channel' => $order->channel ?? null,
            'last_reconciled_at' => $order->last_reconciled_at ?? null,
            'payment_recovery_token' => $paymentRecoveryToken,
            'wait_url' => $recoveryUrls['wait_url'],
            'result_url' => $recoveryUrls['result_url'],
            'pay' => $payment['pay'],
            'checkout_url' => $payment['checkout_url'],
            'payment_attempts_count' => $paymentAttemptSummary['count'],
            'latest_payment_attempt' => $paymentAttemptSummary['latest'],
            'delivery' => $delivery['delivery'],
            'exact_result_entry' => $exactResultEntry,
            'mbti_form_v1' => $this->mbtiFormSummaryForOrder($order, $request),
            'big5_form_v1' => $big5FormSummary,
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
        $requestedPaymentRecoveryToken = $this->resolvePaymentRecoveryToken($request);
        $found = $this->orders->getOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId,
            $order_no,
            false,
            $requestedPaymentRecoveryToken
        );
        if (! ($found['ok'] ?? false)) {
            $errorCode = (string) data_get($found, 'error_code', data_get($found, 'error', ''));
            if (in_array($errorCode, ['PAYMENT_RECOVERY_TOKEN_INVALID', 'PAYMENT_RECOVERY_TOKEN_EXPIRED'], true)) {
                abort(403);
            }

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
        $paymentRecoveryVerified = (bool) ($found['payment_recovery_verified'] ?? false);
        $paymentRecoveryToken = $paymentRecoveryVerified && $requestedPaymentRecoveryToken !== null
            ? $requestedPaymentRecoveryToken
            : $this->orders->issuePaymentRecoveryToken($order);
        $recoveryUrls = $this->orders->presentPaymentRecoveryUrls(
            $order,
            $paymentRecoveryToken,
            $this->resolveRequestedLocale($request)
        );
        $launchOrder = (array) $order;
        $enrichedReturnUrl = $this->buildAlipayReturnUrl(
            $order,
            $recoveryUrls
        );
        if ($enrichedReturnUrl !== null) {
            $launchOrder['return_url'] = $enrichedReturnUrl;
        }

        try {
            $launch = $this->alipayCheckout->launch($launchOrder, $scene);
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
    private function buildOrderPaymentPayload(
        Request $request,
        object $order,
        bool $includePaymentAction
    ): array {
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
            $this->orders->markPaymentPending(
                (string) ($order->order_no ?? ''),
                (int) ($order->org_id ?? 0),
                $this->trimNullableString($order->channel ?? null),
                $this->trimNullableString($order->provider_app ?? null)
            );

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
            if ($paymentAttempt !== null) {
                $this->orders->advancePaymentAttempt((string) ($paymentAttempt->id ?? ''), [
                    'state' => \App\Models\PaymentAttempt::STATE_FAILED,
                    'last_error_code' => $this->trimNullableString($payAction['error_code'] ?? null),
                    'last_error_message' => $this->trimNullableString($payAction['message'] ?? null),
                ]);
            }

            return [
                'provider' => $normalizedProvider,
                'pay' => null,
                'checkout_url' => null,
            ];
        }

        $presented = $this->presentCheckoutPayAction($normalizedProvider, $payAction);
        if (! is_array($presented['pay'] ?? null) && $this->trimNullableString($presented['checkout_url'] ?? null) === null) {
            return $presented;
        }

        $paymentAttempt = $this->createPaymentAttemptForOrder($order, $normalizedProvider, $scene);
        $this->orders->markPaymentPending(
            $orderNo,
            (int) ($order->org_id ?? 0),
            $this->trimNullableString($order->channel ?? null),
            $this->trimNullableString($order->provider_app ?? null)
        );
        $freshOrder = $this->orders->findOrderByOrderNo($orderNo, (int) ($order->org_id ?? 0)) ?? $order;
        $paymentAttempt = $this->recordPaymentAttemptPresentation(
            $paymentAttempt,
            $freshOrder,
            $scene,
            $payAction,
            is_array($presented['pay'] ?? null) ? $presented['pay'] : null,
            $this->trimNullableString($presented['checkout_url'] ?? null)
        );
        $this->persistOrderPaymentPayload(
            $freshOrder,
            $scene,
            $presented,
            $this->trimNullableString($paymentAttempt->id ?? null)
        );

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
        $payValue = $this->stripPaymentRecoveryTokenFromUrl($pay['value'] ?? null) ?? '';
        if ($payType === '' || $payValue === '') {
            return null;
        }
        $checkoutUrl = $this->stripPaymentRecoveryTokenFromUrl($payload['checkout_url'] ?? null);

        return [
            'provider' => $provider,
            'pay' => [
                'type' => $payType,
                'value' => $payValue,
                'provider' => $provider,
            ],
            'checkout_url' => $payType === 'redirect'
                ? ($checkoutUrl ?? $payValue)
                : $checkoutUrl,
        ];
    }

    /**
     * @param  array{
     *     provider:?string,
     *     pay:?array{type:string,value:string,provider:string},
     *     checkout_url:?string
     * }  $payload
     */
    private function persistOrderPaymentPayload(object $order, string $scene, array $payload, ?string $paymentAttemptId = null): void
    {
        $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
        $pay = $payload['pay'] ?? null;
        $orderNo = trim((string) ($order->order_no ?? ''));
        $orgId = (int) ($order->org_id ?? 0);

        if ($provider === '' || $scene === '' || $orderNo === '' || ! is_array($pay)) {
            return;
        }

        $payType = strtolower(trim((string) ($pay['type'] ?? '')));
        $payValue = $this->stripPaymentRecoveryTokenFromUrl($pay['value'] ?? null) ?? '';
        if ($payType === '' || $payValue === '') {
            return;
        }
        $checkoutUrl = $this->stripPaymentRecoveryTokenFromUrl($payload['checkout_url'] ?? null);

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
            'checkout_url' => $checkoutUrl,
            'payment_attempt_id' => $paymentAttemptId,
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

    private function createPaymentAttemptForOrder(object $order, string $provider, string $scene): ?object
    {
        $created = $this->orders->createPaymentAttempt(
            (string) ($order->order_no ?? ''),
            (int) ($order->org_id ?? 0),
            $provider,
            $this->trimNullableString($order->channel ?? null),
            $this->trimNullableString($order->provider_app ?? null),
            $scene,
            (int) ($order->amount_cents ?? $order->amount_total ?? 0),
            (string) ($order->currency ?? 'USD'),
            [
                'source' => 'order_payment_action',
                'scene' => $scene,
            ]
        );

        if (($created['ok'] ?? false) !== true) {
            Log::warning('ORDER_PAYMENT_ATTEMPT_CREATE_FAILED', [
                'order_no' => (string) ($order->order_no ?? ''),
                'provider' => $provider,
                'error_code' => $created['error_code'] ?? $created['error'] ?? 'unknown',
            ]);

            return null;
        }

        return is_object($created['attempt'] ?? null) ? $created['attempt'] : null;
    }

    private function recordPaymentAttemptPresentation(
        ?object $paymentAttempt,
        object $order,
        string $scene,
        array $payAction,
        ?array $payPayload,
        ?string $checkoutUrl
    ): ?object {
        if ($paymentAttempt === null) {
            return null;
        }

        $provider = strtolower(trim((string) ($order->provider ?? '')));
        $safePayPayload = $payPayload;
        if (is_array($safePayPayload)) {
            $safePayValue = $this->stripPaymentRecoveryTokenFromUrl($safePayPayload['value'] ?? null);
            if ($safePayValue !== null) {
                $safePayPayload['value'] = $safePayValue;
            }
        }
        $safeCheckoutUrl = $this->stripPaymentRecoveryTokenFromUrl($checkoutUrl);
        $payloadMeta = [
            'scene' => $scene,
            'pay_type' => strtolower(trim((string) ($safePayPayload['type'] ?? ''))),
            'pay_value_sha256' => $this->digestNullableString($safePayPayload['value'] ?? null),
            'checkout_url_sha256' => $this->digestNullableString($safeCheckoutUrl),
            'has_checkout_url' => $safeCheckoutUrl !== null && trim($safeCheckoutUrl) !== '',
        ];
        $externalTradeNo = $this->trimNullableString($payAction['external_trade_no'] ?? null)
            ?? $this->trimNullableString($order->external_trade_no ?? null);
        $providerSessionRef = $this->resolveProviderSessionRef(
            $provider,
            $safePayPayload,
            $safeCheckoutUrl,
            $payAction
        );

        if ($safePayPayload !== null) {
            $paymentAttempt = $this->orders->advancePaymentAttempt((string) ($paymentAttempt->id ?? ''), [
                'state' => \App\Models\PaymentAttempt::STATE_PROVIDER_CREATED,
                'external_trade_no' => $externalTradeNo,
                'provider_session_ref' => $providerSessionRef,
                'provider_created_at' => now()->toDateTimeString(),
                'payload_meta_json' => $payloadMeta,
            ]);

            return $this->orders->advancePaymentAttempt((string) ($paymentAttempt->id ?? ''), [
                'state' => \App\Models\PaymentAttempt::STATE_CLIENT_PRESENTED,
                'client_presented_at' => now()->toDateTimeString(),
                'payload_meta_json' => $payloadMeta,
            ]);
        }

        return $this->orders->advancePaymentAttempt((string) ($paymentAttempt->id ?? ''), [
            'payload_meta_json' => $payloadMeta,
        ]);
    }

    private function resolveProviderSessionRef(
        string $provider,
        ?array $payPayload,
        ?string $checkoutUrl,
        array $payAction
    ): ?string {
        $provider = strtolower(trim($provider));

        if ($provider === 'lemonsqueezy') {
            $path = trim((string) parse_url((string) ($checkoutUrl ?? ''), PHP_URL_PATH));
            if ($path !== '') {
                $segment = basename($path);
                if ($segment !== '') {
                    return substr($segment, 0, 191);
                }
            }
        }

        $explicit = $this->trimNullableString($payAction['provider_session_ref'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        return $this->trimNullableString($payPayload['provider_session_ref'] ?? null);
    }

    private function digestNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return hash('sha256', $normalized);
    }

    private function stripPaymentRecoveryTokenFromUrl(mixed $value): ?string
    {
        $url = $this->trimNullableString($value);
        if ($url === null) {
            return null;
        }

        if (! str_contains($url, 'payment_recovery_token') && ! str_contains($url, 'paymentRecoveryToken')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $query = (string) ($parts['query'] ?? '');
        if ($query === '') {
            return $url;
        }

        parse_str($query, $params);
        unset($params['payment_recovery_token'], $params['paymentRecoveryToken']);

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'].'://';
        } elseif (isset($parts['host']) && str_starts_with($url, '//')) {
            $rebuilt .= '//';
        }

        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':'.$parts['pass'];
            }
            $rebuilt .= '@';
        }

        if (isset($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';
        $cleanQuery = http_build_query($params);
        if ($cleanQuery !== '') {
            $rebuilt .= '?'.$cleanQuery;
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt !== '' ? $rebuilt : $url;
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

    private function resolvePaymentRecoveryToken(Request $request): ?string
    {
        foreach ([
            $request->query('payment_recovery_token'),
            $request->query('paymentRecoveryToken'),
            $request->header('X-Payment-Recovery-Token', ''),
        ] as $candidate) {
            $normalized = $this->trimNullableString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function resolveRequestedLocale(Request $request): ?string
    {
        foreach ([
            $request->query('locale'),
            $request->header('X-Fap-Locale', ''),
            $request->header('X-Locale', ''),
            $request->getLocale(),
        ] as $candidate) {
            $normalized = $this->trimNullableString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function mbtiFormSummaryForOrder(object $order, Request $request): ?array
    {
        $attemptId = $this->trimNullableString($order->target_attempt_id ?? null);
        if ($attemptId === null) {
            return null;
        }

        return $this->mbtiPublicFormSummaryBuilder->summarizeForAttemptId(
            $attemptId,
            (int) ($order->org_id ?? $this->orgContext->orgId()),
            $this->resolveRequestedLocale($request)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function big5FormSummaryForOrder(object $order, Request $request): ?array
    {
        $attemptId = $this->trimNullableString($order->target_attempt_id ?? null);
        if ($attemptId === null) {
            return null;
        }

        return $this->bigFivePublicFormSummaryBuilder->summarizeForAttemptId(
            $attemptId,
            (int) ($order->org_id ?? $this->orgContext->orgId()),
            $this->resolveRequestedLocale($request)
        );
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
        return $this->paymentProviders->enabledProviders();
    }

    private function isProviderEnabled(string $provider): bool
    {
        return $this->paymentProviders->isEnabled($provider);
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

    /**
     * @param  array<string,mixed>  $payload
     * @return array{channel:?string,provider_app:?string}
     */
    private function resolveOrderLedgerContext(
        Request $request,
        array $payload,
        ?string $attemptId,
        string $provider
    ): array {
        $explicitChannel = Order::normalizeChannel(
            $this->trimNullableString($payload['channel'] ?? $request->header('X-Channel', ''))
        );
        $attemptChannel = $this->resolveAttemptOrderChannel($attemptId);
        $channel = $explicitChannel ?? $attemptChannel ?? 'web';

        $providerApp = $this->trimNullableString($payload['provider_app'] ?? $request->header('X-Provider-App', ''));
        if ($providerApp === null) {
            $providerApp = match (strtolower(trim($provider))) {
                'wechatpay' => $channel === 'wechat_miniapp'
                    ? $this->trimNullableString(config('pay.wechat.default.mini_app_id', config('pay.wechat.default.mp_app_id', config('pay.wechat.default.app_id', ''))))
                    : null,
                'alipay' => $channel === 'alipay_miniapp'
                    ? $this->trimNullableString(config('pay.alipay.default.app_id', ''))
                    : null,
                default => null,
            };
        }

        return [
            'channel' => $channel,
            'provider_app' => $providerApp,
        ];
    }

    private function resolveAttemptOrderChannel(?string $attemptId): ?string
    {
        $normalizedAttemptId = $this->trimNullableString($attemptId);
        if ($normalizedAttemptId === null) {
            return null;
        }

        $attemptChannel = DB::table('attempts')
            ->where('id', $normalizedAttemptId)
            ->value('channel');

        return Order::normalizeChannel(is_scalar($attemptChannel) ? (string) $attemptChannel : null);
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
     * @return array<string,mixed>
     */
    private function normalizeSdkPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if ($payload instanceof \JsonSerializable) {
            $serialized = $payload->jsonSerialize();
            if (is_array($serialized)) {
                return $serialized;
            }
        }

        if ($payload instanceof PsrResponseInterface) {
            $decoded = json_decode((string) $payload->getBody(), true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{status:int,error_code:string,message:string}|null
     */
    private function validateAlipayReturnRecoveryBinding(array $payload, object $order): ?array
    {
        if (strtolower(trim((string) ($order->provider ?? ''))) !== 'alipay') {
            return $this->alipayReturnBindingMismatch();
        }

        $expectedAppId = $this->trimNullableString(config('pay.alipay.default.app_id', ''));
        if ($expectedAppId === null) {
            return [
                'status' => 503,
                'error_code' => 'PAYMENT_RETURN_BINDING_UNAVAILABLE',
                'message' => 'payment return binding is not configured.',
            ];
        }

        $returnedAppId = $this->firstPayloadString($payload, ['app_id', 'auth_app_id', 'appId']);
        if ($returnedAppId === null || ! hash_equals($expectedAppId, $returnedAppId)) {
            return $this->alipayReturnBindingMismatch();
        }

        $expectedSellerId = $this->trimNullableString(config('pay.alipay.default.seller_id', ''));
        if ($expectedSellerId === null) {
            return [
                'status' => 503,
                'error_code' => 'PAYMENT_RETURN_BINDING_UNAVAILABLE',
                'message' => 'payment return binding is not configured.',
            ];
        }

        $returnedSellerId = $this->firstPayloadString($payload, ['seller_id', 'sellerId']);
        if ($returnedSellerId === null || ! hash_equals($expectedSellerId, $returnedSellerId)) {
            return $this->alipayReturnBindingMismatch();
        }

        $orderProviderApp = $this->trimNullableString($order->provider_app ?? null);
        if ($orderProviderApp !== null && ! hash_equals($expectedAppId, $orderProviderApp)) {
            return $this->alipayReturnBindingMismatch();
        }

        $paymentState = Order::normalizePaymentState(
            $this->trimNullableString($order->payment_state ?? null),
            $this->trimNullableString($order->status ?? null)
        );
        if (! in_array($paymentState, [
            Order::PAYMENT_STATE_CREATED,
            Order::PAYMENT_STATE_PENDING,
            Order::PAYMENT_STATE_PAID,
        ], true)) {
            return $this->alipayReturnBindingMismatch();
        }

        $tradeStatus = strtoupper((string) ($this->trimNullableString($payload['trade_status'] ?? null) ?? ''));
        if (! in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return $this->alipayReturnBindingMismatch();
        }

        $returnedAmountCents = $this->parseAlipayYuanAmountCents($payload['total_amount'] ?? null);
        $orderAmountCents = (int) ($order->amount_cents ?? $order->amount_total ?? 0);
        if ($returnedAmountCents === null || $orderAmountCents <= 0 || $returnedAmountCents !== $orderAmountCents) {
            return $this->alipayReturnBindingMismatch();
        }

        $orderCurrency = strtoupper((string) ($this->trimNullableString($order->currency ?? null) ?? ''));
        if ($orderCurrency !== '' && $orderCurrency !== 'CNY') {
            return $this->alipayReturnBindingMismatch();
        }

        $returnedCurrency = strtoupper((string) ($this->firstPayloadString($payload, ['currency', 'trans_currency']) ?? 'CNY'));
        if ($returnedCurrency !== 'CNY') {
            return $this->alipayReturnBindingMismatch();
        }

        $returnedTradeNo = $this->firstPayloadString($payload, ['trade_no', 'external_trade_no', 'provider_trade_no']);
        if ($returnedTradeNo !== null) {
            foreach (['external_trade_no', 'provider_trade_no'] as $field) {
                $storedTradeNo = $this->trimNullableString($order->{$field} ?? null);
                if ($storedTradeNo !== null && ! hash_equals($storedTradeNo, $returnedTradeNo)) {
                    return $this->alipayReturnBindingMismatch();
                }
            }
        }

        return null;
    }

    /**
     * @return array{status:int,error_code:string,message:string}
     */
    private function alipayReturnBindingMismatch(): array
    {
        return [
            'status' => 422,
            'error_code' => 'PAYMENT_RETURN_BINDING_MISMATCH',
            'message' => 'payment return does not match order binding.',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstPayloadString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->trimNullableString($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function parseAlipayYuanAmountCents(mixed $value): ?int
    {
        $amount = $this->trimNullableString($value);
        if ($amount === null || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            return null;
        }

        [$yuan, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $yuan * 100) + (int) str_pad($fraction, 2, '0');
    }

    /**
     * @param  array{wait_url:?string,result_url:?string}  $recoveryUrls
     */
    private function buildAlipayReturnUrl(object $order, array $recoveryUrls): ?string
    {
        $baseReturnUrl = $this->trimNullableString(data_get(config('pay.alipay.default', []), 'return_url', ''))
            ?? $this->deriveDefaultAlipayReturnUrl($recoveryUrls);
        $orderNo = $this->trimNullableString($order->order_no ?? null);

        if ($baseReturnUrl === null || $orderNo === null) {
            return $baseReturnUrl;
        }

        $waitUrl = $this->trimNullableString($recoveryUrls['wait_url'] ?? null);
        $resultUrl = $this->trimNullableString($recoveryUrls['result_url'] ?? null);
        $fragment = parse_url($baseReturnUrl, PHP_URL_FRAGMENT);
        $query = [];
        $existingQuery = parse_url($baseReturnUrl, PHP_URL_QUERY);
        if (is_string($existingQuery) && $existingQuery !== '') {
            parse_str($existingQuery, $query);
        }

        $query['order_no'] = $orderNo;
        if ($waitUrl !== null) {
            $query['wait_url'] = $waitUrl;
        }
        if ($resultUrl !== null) {
            $query['result_url'] = $resultUrl;
        }

        $baseWithoutQuery = preg_replace('/[?#].*$/', '', $baseReturnUrl) ?: $baseReturnUrl;
        $built = $baseWithoutQuery;
        if ($query !== []) {
            $built .= '?'.http_build_query($query);
        }
        if (is_string($fragment) && $fragment !== '') {
            $built .= '#'.$fragment;
        }

        return $built;
    }

    /**
     * @param  array{wait_url:?string,result_url:?string}  $recoveryUrls
     */
    private function deriveDefaultAlipayReturnUrl(array $recoveryUrls): ?string
    {
        foreach ([
            $this->trimNullableString($recoveryUrls['wait_url'] ?? null),
            $this->trimNullableString($recoveryUrls['result_url'] ?? null),
        ] as $candidateUrl) {
            if ($candidateUrl === null) {
                continue;
            }

            $scheme = parse_url($candidateUrl, PHP_URL_SCHEME);
            $host = parse_url($candidateUrl, PHP_URL_HOST);
            $port = parse_url($candidateUrl, PHP_URL_PORT);
            $path = trim((string) parse_url($candidateUrl, PHP_URL_PATH));

            if (! is_string($scheme) || $scheme === '' || ! is_string($host) || $host === '' || $path === '') {
                continue;
            }

            $segments = explode('/', ltrim($path, '/'));
            $locale = in_array($segments[0] ?? '', ['en', 'zh'], true) ? $segments[0] : null;
            if ($locale === null) {
                continue;
            }

            $base = $scheme.'://'.$host;
            if (is_int($port)) {
                $base .= ':'.$port;
            }

            return $base.'/'.$locale.'/pay/return/alipay';
        }

        return null;
    }

    /**
     * @return array{attempt_id:?string,delivery:array<string,mixed>}
     */
    private function buildOrderDelivery(object $order): array
    {
        return $this->orders->presentOrderDelivery($order);
    }

    private function resolvePublicOrderStatus(object $order): string
    {
        return $this->normalizePublicOrderStatus(
            $this->orders->resolvedPaymentState($order)
        );
    }

    private function normalizePublicOrderStatus(string $paymentState): string
    {
        $status = strtolower(trim($paymentState));

        return match ($status) {
            'paid' => 'paid',
            'failed' => 'failed',
            'canceled', 'cancelled', 'expired' => 'canceled',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    private function publicOrderMessage(object $order): string
    {
        return match ($this->resolvePublicOrderStatus($order)) {
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
