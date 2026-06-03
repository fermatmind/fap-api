<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Ops\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\SeoDashboardApiReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SeoIntelDashboardController
{
    public function __construct(
        private readonly SeoDashboardApiReadService $readService,
    ) {}

    public function overview(): JsonResponse
    {
        return $this->respond($this->readService->overview());
    }

    public function urlTruth(): JsonResponse
    {
        return $this->respond($this->readService->urlTruth());
    }

    public function issues(Request $request): JsonResponse
    {
        return $this->respond($this->readService->issues($this->limit($request)));
    }

    public function trends(Request $request): JsonResponse
    {
        return $this->respond($this->readService->trends($this->limit($request)));
    }

    public function pagePerformance(Request $request): JsonResponse
    {
        return $this->respond($this->readService->pagePerformance($this->limit($request)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'contract_version' => 'seo-dash-api-01.v1',
                'read_only' => true,
                'authority' => 'fap-api seo_intel read model',
            ],
        ]);
    }

    private function limit(Request $request): int
    {
        $raw = $request->query('limit', 25);
        $limit = is_numeric($raw) ? (int) $raw : 25;

        return max(1, min($limit, 100));
    }
}
