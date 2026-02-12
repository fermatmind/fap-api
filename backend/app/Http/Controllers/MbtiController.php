<?php

namespace App\Http\Controllers;

use App\Services\Legacy\LegacyMbtiAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MbtiController extends Controller
{
    public function __construct(private LegacyMbtiAttemptService $service) {}

    public function health(): JsonResponse
    {
        return response()->json($this->service->health());
    }

    public function scaleMeta(): JsonResponse
    {
        return response()->json($this->service->scaleMeta());
    }

    public function questions(Request $request): JsonResponse
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? $request->header('X-Request-Id', $request->header('X-Request-ID', ''))));

        $payload = $this->service->questions(
            (string) ($request->header('X-Region') ?: $request->input('region') ?: ''),
            (string) ($request->header('X-Locale') ?: $request->input('locale') ?: ''),
            $requestId !== '' ? $requestId : null,
        );

        return response()->json($payload);
    }

    public function startAttempt(Request $request): JsonResponse
    {
        return $this->toJson($this->service->startAttempt($request));
    }

    public function storeAttempt(Request $request): JsonResponse
    {
        return $this->toJson($this->service->storeAttempt($request));
    }

    public function upsertResult(Request $request, string $attemptId): JsonResponse
    {
        return $this->toJson($this->service->upsertResult($request, $attemptId));
    }

    private function toJson(array $payload): JsonResponse
    {
        $status = (int) ($payload['status_code'] ?? 200);
        unset($payload['status_code']);

        return response()->json($payload, $status);
    }
}
