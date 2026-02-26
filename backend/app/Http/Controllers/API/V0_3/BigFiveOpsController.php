<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\BigFiveOpsRequest;
use App\Services\Ops\BigFiveOpsActionService;
use Illuminate\Http\JsonResponse;

final class BigFiveOpsController extends Controller
{
    public function __construct(
        private readonly BigFiveOpsActionService $actionService,
    ) {}

    public function latest(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->latest($org_id, $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function latestAudits(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->latestAudits($org_id, $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function releases(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->releases($org_id, $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function audits(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->audits($org_id, $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function audit(BigFiveOpsRequest $request, int $org_id, string $audit_id): JsonResponse
    {
        $result = $this->actionService->audit($org_id, $audit_id);

        return response()->json($result['payload'], $result['status']);
    }

    public function release(BigFiveOpsRequest $request, int $org_id, string $release_id): JsonResponse
    {
        $result = $this->actionService->release($org_id, $release_id);

        return response()->json($result['payload'], $result['status']);
    }

    public function publish(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->publish($org_id, $request->validated(), $this->requestContext($request));

        return response()->json($result['payload'], $result['status']);
    }

    public function rollback(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->rollback($org_id, $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function rebuildNorms(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->rebuildNorms($org_id, $request->validated(), $this->requestContext($request));

        return response()->json($result['payload'], $result['status']);
    }

    public function driftCheckNorms(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->driftCheckNorms($org_id, $request->validated(), $this->requestContext($request));

        return response()->json($result['payload'], $result['status']);
    }

    public function activateNorms(BigFiveOpsRequest $request, int $org_id): JsonResponse
    {
        $result = $this->actionService->activateNorms($org_id, $request->validated(), $this->requestContext($request));

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * @return array<string,mixed>
     */
    private function requestContext(BigFiveOpsRequest $request): array
    {
        return [
            'fm_user_id' => $request->attributes->get('fm_user_id'),
            'ip' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'request_id' => (string) $request->headers->get('X-Request-Id', ''),
        ];
    }
}
