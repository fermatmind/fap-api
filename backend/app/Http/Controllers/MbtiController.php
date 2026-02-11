<?php

namespace App\Http\Controllers;

use App\Services\Legacy\LegacyMbtiAttemptService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MbtiController extends Controller
{
    public function __construct(private LegacyMbtiAttemptService $service)
    {
    }

    public function health(): JsonResponse
    {
        return $this->service->health();
    }

    public function scaleMeta(): JsonResponse
    {
        return $this->service->scaleMeta();
    }

    public function questions(): JsonResponse
    {
        return $this->service->questions();
    }

    public function startAttempt(Request $request): JsonResponse
    {
        return $this->service->startAttempt($request);
    }

    public function storeAttempt(Request $request): JsonResponse
    {
        return $this->service->storeAttempt($request);
    }

    public function upsertResult(Request $request, string $attemptId): JsonResponse
    {
        return $this->service->upsertResult($request, $attemptId);
    }
}
