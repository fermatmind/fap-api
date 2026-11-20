<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Models\Attempt;
use App\Services\Commerce\RiasecReportPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RiasecPurchaseController
{
    public function show(Request $request, string $id, RiasecReportPurchase $purchase): JsonResponse
    {
        $attempt = Attempt::withoutGlobalScopes()->where('org_id', 0)->whereKey($id)->firstOrFail();

        return response()->json($purchase->present($request, $attempt), 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function sample(Request $request, RiasecReportPurchase $purchase): JsonResponse
    {
        $locale = $request->query('locale') === 'en' ? 'en' : 'zh-CN';

        return response()->json($purchase->sample($locale), 200, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
