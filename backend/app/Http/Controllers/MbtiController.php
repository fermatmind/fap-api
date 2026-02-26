<?php

namespace App\Http\Controllers;

use App\DTO\Legacy\LegacyRequestContext;
use App\Http\Requests\V0_3\LegacyStartAttemptRequest;
use App\Http\Requests\V0_3\LegacyStoreAttemptRequest;
use App\Services\Legacy\LegacyMbtiAttemptService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MbtiController extends Controller
{
    public function __construct(
        private readonly LegacyMbtiAttemptService $service,
        private readonly OrgContext $orgContext,
    ) {}

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

    public function startAttempt(LegacyStartAttemptRequest $request): JsonResponse
    {
        return $this->toJson(
            $this->service->startAttempt(
                $request->validated(),
                LegacyRequestContext::fromRequest($request, $this->orgContext),
            )
        );
    }

    public function storeAttempt(LegacyStoreAttemptRequest $request): JsonResponse
    {
        return $this->toJson(
            $this->service->storeAttempt(
                $request->validated(),
                LegacyRequestContext::fromRequest($request, $this->orgContext),
            )
        );
    }

    public function upsertResult(LegacyStoreAttemptRequest $request, string $attemptId): JsonResponse
    {
        return $this->toJson(
            $this->service->upsertResult(
                $request->validated(),
                $attemptId,
                LegacyRequestContext::fromRequest($request, $this->orgContext),
            )
        );
    }

    private function toJson(array $payload): JsonResponse
    {
        $status = (int) ($payload['status_code'] ?? 200);
        unset($payload['status_code']);

        return response()->json($payload, $status);
    }
}
