<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Commerce\ReportGiftService;
use App\Services\Commerce\WechatMiniVirtualPaymentService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportGiftController extends Controller
{
    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly ReportGiftService $gifts,
        private readonly WechatMiniVirtualPaymentService $wechatMiniVirtual,
    ) {}

    public function store(Request $request, string $id): JsonResponse
    {
        $result = $this->gifts->createRequest(
            $this->orgContext->orgId(),
            $this->userId(),
            $this->anonId($request),
            $id
        );

        return $this->respond($result, 201);
    }

    public function showOwner(Request $request, string $id, string $gift_request_id): JsonResponse
    {
        return $this->respond($this->gifts->showOwner(
            $this->orgContext->orgId(),
            $this->userId(),
            $this->anonId($request),
            $id,
            $gift_request_id
        ));
    }

    public function cancel(Request $request, string $id, string $gift_request_id): JsonResponse
    {
        return $this->respond($this->gifts->cancel(
            $this->orgContext->orgId(),
            $this->userId(),
            $this->anonId($request),
            $id,
            $gift_request_id
        ));
    }

    public function showPublic(string $token): JsonResponse
    {
        return $this->respond($this->gifts->showPublic($token, $this->orgContext->orgId()));
    }

    public function purchaseWechatMiniVirtual(Request $request, string $token): JsonResponse
    {
        $payload = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'wx_login_code' => ['required', 'string', 'max:128'],
            'target_attempt_id' => ['prohibited'],
            'sku' => ['prohibited'],
            'amount_cents' => ['prohibited'],
            'currency' => ['prohibited'],
            'goodsPrice' => ['prohibited'],
            'org_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'anon_id' => ['prohibited'],
        ]);
        $idempotencyKey = trim((string) ($request->header('Idempotency-Key')
            ?: ($payload['idempotency_key'] ?? '')));

        $reserved = $this->gifts->reserveWechatMiniVirtualOrder(
            $token,
            $this->orgContext->orgId(),
            $this->userId(),
            $this->anonId($request),
            $idempotencyKey,
            $this->requestId($request)
        );
        if (($reserved['ok'] ?? false) !== true || ! is_object($reserved['order'] ?? null)) {
            return $this->respond($reserved);
        }

        $payment = $this->wechatMiniVirtual->createPaymentAction(
            $reserved['order'],
            (string) $payload['wx_login_code']
        );
        if (($payment['ok'] ?? false) !== true) {
            return $this->respond($payment);
        }

        return response()->json([
            'ok' => true,
            'order_no' => (string) $reserved['order_no'],
            'pay' => $payment['pay'] ?? null,
            'idempotent' => (bool) ($reserved['idempotent'] ?? false),
        ]);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function respond(array $result, int $successStatus = 200): JsonResponse
    {
        return response()->json(
            $result,
            ($result['ok'] ?? false) === true ? $successStatus : (int) ($result['status'] ?? 400)
        );
    }

    private function userId(): ?string
    {
        $userId = $this->orgContext->userId();

        return $userId !== null ? (string) $userId : null;
    }

    private function anonId(Request $request): ?string
    {
        foreach ([
            $this->orgContext->anonId(),
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->attributes->get('client_anon_id'),
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function requestId(Request $request): ?string
    {
        $requestId = trim((string) ($request->header('X-Request-Id')
            ?: $request->header('X-Request-ID', '')));

        return $requestId !== '' ? substr($requestId, 0, 128) : null;
    }
}
