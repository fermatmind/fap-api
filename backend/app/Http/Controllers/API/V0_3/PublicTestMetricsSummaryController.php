<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Analytics\PublicTestMetricsSummaryService;
use Illuminate\Http\JsonResponse;

final class PublicTestMetricsSummaryController extends Controller
{
    public function __invoke(PublicTestMetricsSummaryService $summaryService): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'test_metrics_summary' => $summaryService->summarize(),
        ]);
    }
}
