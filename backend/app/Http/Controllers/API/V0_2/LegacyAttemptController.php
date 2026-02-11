<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyMbtiAttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyAttemptController extends Controller
{
    public function __construct(private LegacyMbtiAttemptService $service)
    {
    }

    public function health(Request $request): JsonResponse
    {
        return $this->service->health();
    }

    public function scaleMeta(Request $request): JsonResponse
    {
        return $this->service->scaleMeta();
    }

    public function questions(Request $request): JsonResponse
    {
        return $this->service->questions();
    }

    public function storeAttempt(Request $request): JsonResponse
    {
        return $this->service->storeAttempt($request);
    }

    public function startAttempt(Request $request, ?string $id = null): JsonResponse
    {
        return $this->service->startAttempt($request, $id);
    }

    public function upsertResult(Request $request, string $id): JsonResponse
    {
        return $this->service->upsertResult($request, $id);
    }
}
