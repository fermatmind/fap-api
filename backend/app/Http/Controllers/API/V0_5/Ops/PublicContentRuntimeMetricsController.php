<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Ops;

use App\Http\Controllers\Controller;
use App\Services\Ops\PublicContentRuntimeMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicContentRuntimeMetricsController extends Controller
{
    public function __invoke(
        Request $request,
        PublicContentRuntimeMetricsService $metrics,
    ): JsonResponse {
        $validated = $request->validate([
            'window_minutes' => ['sometimes', 'integer', 'min:1', 'max:129600'],
            'route_family' => ['sometimes', 'string', 'max:64'],
            'locale' => ['sometimes', 'string', 'max:16'],
        ]);

        try {
            $payload = $metrics->query(
                (int) ($validated['window_minutes'] ?? 60),
                isset($validated['route_family']) ? (string) $validated['route_family'] : null,
                isset($validated['locale']) ? (string) $validated['locale'] : null,
            );
        } catch (\Throwable) {
            return response()->json([
                'ok' => false,
                'scope' => 'anonymous_org_0_public_get',
                'error_code' => 'metrics_unavailable',
                'items' => [],
            ], 503);
        }

        return response()->json($payload, ($payload['ok'] ?? false) === true ? 200 : 422);
    }
}
