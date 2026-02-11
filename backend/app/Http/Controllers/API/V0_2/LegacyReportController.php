<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyReportController extends Controller
{
    public function __construct(private LegacyReportService $service)
    {
    }

    public function getResult(Request $request, string $attemptId): JsonResponse
    {
        $attempt = $this->service->ownedAttemptOrFail($attemptId, $request);
        $payload = $this->service->getResultPayload($attempt);

        $this->service->recordResultViewEvents($request, $attempt, $payload);

        return response()->json($payload, 200);
    }

    public function getReport(Request $request, string $attemptId): JsonResponse
    {
        $attempt = $this->service->ownedAttemptOrFail($attemptId, $request);
        $payload = $this->service->getReportPayload($attempt, $request);

        return response()->json($payload['body'], $payload['status']);
    }
}
