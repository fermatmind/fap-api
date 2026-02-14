<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScalesLookupController extends Controller
{
    public function __construct(
        private ScaleRegistry $registry,
        private OrgContext $orgContext,
    ) {}

    /**
     * GET /api/v0.3/scales/lookup?slug=xxx
     */
    public function lookup(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $slug = (string) $request->query('slug', '');
        $slug = trim(strtolower($slug));
        if ($slug === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SLUG_REQUIRED',
                'message' => 'slug is required.',
            ], 400);
        }
        if (! preg_match('/^[a-z0-9-]{0,127}$/', $slug)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $row = $this->registry->lookupBySlug($slug, $orgId);
        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'scale_code' => $row['code'] ?? '',
            'primary_slug' => $row['primary_slug'] ?? '',
            'pack_id' => $row['default_pack_id'] ?? null,
            'dir_version' => $row['default_dir_version'] ?? null,
            'region' => $row['default_region'] ?? null,
            'locale' => $row['default_locale'] ?? null,
            'driver_type' => $row['driver_type'] ?? '',
            'view_policy' => $row['view_policy_json'] ?? null,
            'capabilities' => $row['capabilities_json'] ?? null,
            'commercial' => $row['commercial_json'] ?? null,
            'seo_schema_json' => $row['seo_schema_json'] ?? null,
            'seo_schema' => $row['seo_schema_json'] ?? null,
        ]);
    }
}
