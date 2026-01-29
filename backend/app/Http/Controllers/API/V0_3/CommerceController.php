<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Commerce\OrderManager;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CommerceController extends Controller
{
    public function __construct(
        private OrgContext $orgContext,
        private OrderManager $orders,
    ) {
    }

    /**
     * GET /api/v0.3/skus?scale=MBTI
     */
    public function listSkus(Request $request): JsonResponse
    {
        if (!Schema::hasTable('skus')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'message' => 'skus table missing.',
            ], 500);
        }

        $scale = strtoupper(trim((string) $request->query('scale', '')));
        if ($scale === '') {
            return response()->json([
                'ok' => false,
                'error' => 'SCALE_REQUIRED',
                'message' => 'scale is required.',
            ], 400);
        }

        $items = DB::table('skus')
            ->where('scale_code', $scale)
            ->where('is_active', true)
            ->orderBy('sku')
            ->get();

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/v0.3/orders
     */
    public function createOrder(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'target_attempt_id' => ['nullable', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
        ]);

        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->orgContext->anonId();

        $result = $this->orders->createOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            (string) $payload['sku'],
            (int) ($payload['quantity'] ?? 1),
            $payload['target_attempt_id'] ?? null,
            (string) ($payload['provider'] ?? 'stub')
        );

        if (!($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) ($result['error'] ?? ''));
            return response()->json($result, $status);
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
        $result = $this->orders->getOrder($orgId, $order_no);
        if (!($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) ($result['error'] ?? ''));
            return response()->json($result, $status);
        }

        return response()->json([
            'ok' => true,
            'order' => $result['order'],
        ]);
    }

    private function mapErrorStatus(string $code): int
    {
        return match ($code) {
            'SKU_NOT_FOUND', 'ORDER_NOT_FOUND' => 404,
            'TABLE_MISSING' => 500,
            default => 400,
        };
    }
}
