<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Psychometrics\NormsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PsychometricsController extends Controller
{
    public function __construct(private NormsRegistry $normsRegistry)
    {
    }

    public function listNorms(Request $request, string $scale): JsonResponse
    {
        $scale = strtoupper(trim($scale));
        if ($scale === '') {
            return response()->json([
                'ok' => false,
                'error' => 'SCALE_REQUIRED',
            ], 400);
        }

        $region = (string) ($request->query('region') ?? $request->header('X-Region') ?? config('content_packs.default_region', 'CN_MAINLAND'));
        $locale = (string) ($request->query('locale') ?? $request->header('X-Locale') ?? config('content_packs.default_locale', 'zh-CN'));

        $items = $this->normsRegistry->listAvailableNorms($scale, $region, $locale);
        $resolved = $this->normsRegistry->resolve($scale, $region, $locale);

        return response()->json([
            'ok' => true,
            'scale_code' => $scale,
            'region' => $region,
            'locale' => $locale,
            'items' => $items,
            'default' => $resolved['ok'] ? [
                'norm_id' => $resolved['norm_id'] ?? '',
                'version' => $resolved['version'] ?? '',
                'checksum' => $resolved['checksum'] ?? '',
                'bucket_keys' => $resolved['bucket_keys'] ?? [],
                'bucket' => $resolved['bucket'] ?? null,
            ] : null,
        ]);
    }

    public function quality(Request $request, string $id): JsonResponse
    {
        if (!Schema::hasTable('attempt_quality')) {
            return response()->json([
                'ok' => false,
                'error' => 'QUALITY_NOT_AVAILABLE',
            ], 404);
        }

        $row = DB::table('attempt_quality')->where('attempt_id', $id)->first();
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'QUALITY_NOT_FOUND',
            ], 404);
        }

        $checks = $row->checks_json ?? null;
        if (is_string($checks)) {
            $decoded = json_decode($checks, true);
            $checks = is_array($decoded) ? $decoded : null;
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => $id,
            'grade' => (string) ($row->grade ?? ''),
            'checks' => $checks,
        ]);
    }

    public function stats(Request $request, string $id): JsonResponse
    {
        $attempt = Attempt::find($id);
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
            ], 404);
        }

        $snapshot = $attempt->calculation_snapshot_json;
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true);
            $snapshot = is_array($decoded) ? $decoded : null;
        }

        $stats = is_array($snapshot) ? ($snapshot['stats'] ?? null) : null;

        if (!is_array($stats)) {
            return response()->json([
                'ok' => false,
                'error' => 'STATS_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => $id,
            'stats' => $stats,
            'norm' => $snapshot['norm'] ?? null,
        ]);
    }
}
