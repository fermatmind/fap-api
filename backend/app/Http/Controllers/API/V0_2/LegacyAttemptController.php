<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyMbtiAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyAttemptController extends Controller
{
    public function __construct(private LegacyMbtiAttemptService $service) {}

    public function health(Request $request): JsonResponse
    {
        return response()->json($this->service->health());
    }

    public function scaleMeta(Request $request): JsonResponse
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

    public function storeAttempt(Request $request): JsonResponse
    {
        return $this->toJson($this->service->storeAttempt($request));
    }

    public function startAttempt(Request $request, ?string $id = null): JsonResponse
    {
        return $this->toJson($this->service->startAttempt($request, $id));
    }

    public function upsertResult(Request $request, string $id): JsonResponse
    {
        return $this->toJson($this->service->upsertResult($request, $id));
    }

    private function toJson(array $payload): JsonResponse
    {
        $status = (int) ($payload['status_code'] ?? 200);
        unset($payload['status_code']);

        return response()->json($payload, $status);
    }
}
