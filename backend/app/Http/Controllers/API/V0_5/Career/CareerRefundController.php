<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Requests\PurchaseRequestActor;
use App\Services\Commerce\CareerAssessmentPurchase;
use App\Services\Commerce\CareerRefundWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerRefundController
{
    public function show(Request $request, string $orderNo, CareerRefundWorkflow $workflow): JsonResponse
    {
        return $this->respond($workflow->forOwner($orderNo, $this->owner($request, $orderNo)));
    }

    public function store(Request $request, string $orderNo, CareerRefundWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => 'required|in:access_problem,duplicate_purchase,not_as_expected,other',
            'detail' => 'nullable|string|max:1000',
            'acknowledged' => 'required|accepted',
        ]);
        $workflow->request($orderNo, $this->owner($request, $orderNo), $data);

        return $this->show($request, $orderNo, $workflow);
    }

    public function withdraw(Request $request, string $orderNo, CareerRefundWorkflow $workflow): JsonResponse
    {
        $workflow->withdraw($orderNo, $this->owner($request, $orderNo));

        return $this->show($request, $orderNo, $workflow);
    }

    private function owner(Request $request, string $orderNo): string
    {
        $order = \Illuminate\Support\Facades\DB::table('orders')->where('org_id', 0)->where('order_no', $orderNo)->first();
        abort_unless($order, 404);
        $owner = CareerAssessmentPurchase::ownerKeyForOrder(PurchaseRequestActor::from($request), $order);
        abort_unless((int) $request->attributes->get('fm_org_id', 0) === 0 && is_string($owner) && $owner !== '', 404);

        return $owner;
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
