<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Ops\SeoIntel;

use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SeoCouncilMissionController
{
    public function storeApi(Request $request, ApiMissionAdapter $adapter): JsonResponse
    {
        return $this->submit($adapter, $request->all());
    }

    public function storeUi(Request $request, SeoOperationsUiMissionAdapter $adapter): JsonResponse
    {
        $input = [
            'mission_id' => (string) $request->input('mission_id'),
            'idempotency_key' => (string) $request->input('idempotency_key'),
            'mission_type' => (string) $request->input('mission_type'),
            'family' => (string) $request->input('family'),
            'locale' => (string) $request->input('locale'),
            'review_domain' => $request->filled('review_domain') ? (string) $request->input('review_domain') : null,
            'requested_role' => null,
            'evidence_bundle_refs' => [],
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];

        return $this->submit($adapter, $input);
    }

    /** @param ApiMissionAdapter|SeoOperationsUiMissionAdapter $adapter @param array<string, mixed> $input */
    private function submit(object $adapter, array $input): JsonResponse
    {
        try {
            $receipt = $adapter->submit($input);
            $status = ($receipt['status'] ?? null) === 'IDEMPOTENCY_CONFLICT' ? 409 : 202;

            return response()->json([
                'ok' => true,
                'data' => $receipt,
                'meta' => [
                    'contract_version' => 'seo.run_receipt.v1',
                    'authority' => 'fap-api unique deterministic SEO Council Orchestrator',
                    'execution_allowed' => false,
                ],
            ], $status);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'error_code' => 'MISSION_REQUEST_INVALID',
                'message' => preg_match('/^[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                    ? $exception->getMessage()
                    : 'MISSION_REQUEST_INVALID',
            ], 422);
        }
    }
}
