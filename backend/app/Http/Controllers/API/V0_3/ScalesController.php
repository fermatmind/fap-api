<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScalesController extends Controller
{
    public function __construct(private ScaleRegistry $registry)
    {
    }

    /**
     * GET /api/v0.3/scales
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = 0;
        $items = $this->registry->listActivePublic($orgId);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.3/scales/{scale_code}
     */
    public function show(Request $request, string $scale_code): JsonResponse
    {
        $orgId = 0;
        $code = strtoupper(trim($scale_code));
        if ($code === '') {
            return response()->json([
                'ok' => false,
                'error' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }

        $row = $this->registry->getByCode($code, $orgId);
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'item' => $row,
        ]);
    }
}
