<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\PublicSurface\PublicGatewaySurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicGatewaySurfaceController extends Controller
{
    public function __construct(
        private readonly PublicGatewaySurfaceService $publicGatewaySurfaceService,
    ) {}

    public function home(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'landing_surface_v1' => $this->publicGatewaySurfaceService->buildHomeSurface(
                $this->resolveLocale($request)
            ),
        ]);
    }

    public function tests(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'landing_surface_v1' => $this->publicGatewaySurfaceService->buildTestsIndexSurface(
                $this->resolveLocale($request)
            ),
        ]);
    }

    public function help(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'landing_surface_v1' => $this->publicGatewaySurfaceService->buildHelpIndexSurface(
                $this->resolveLocale($request)
            ),
        ]);
    }

    public function helpDetail(Request $request, string $slug): JsonResponse
    {
        $payload = $this->publicGatewaySurfaceService->buildHelpDetailSurface(
            $this->resolveLocale($request),
            $slug
        );

        if ($payload === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'help page not found.',
            ], 404);
        }

        return response()->json(array_merge(['ok' => true], $payload));
    }

    private function resolveLocale(Request $request): string
    {
        $raw = trim((string) (
            $request->query('locale')
            ?? $request->header('X-FAP-Locale')
            ?? 'en'
        ));

        $normalized = strtolower(str_replace('_', '-', $raw));

        return str_starts_with($normalized, 'zh') ? 'zh-CN' : 'en';
    }
}
