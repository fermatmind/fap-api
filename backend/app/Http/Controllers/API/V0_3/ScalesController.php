<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Content\BigFivePackLoader;
use App\Services\Content\QuestionsService;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScalesController extends Controller
{
    public function __construct(
        private ScaleRegistry $registry,
        private OrgContext $orgContext,
    )
    {
    }

    /**
     * GET /api/v0.3/scales
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $items = $this->registry->listVisible($orgId);

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
        $orgId = $this->orgContext->orgId();
        $code = strtoupper(trim($scale_code));
        if ($code === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }

        $row = $this->registry->getByCode($code, $orgId);
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'item' => $row,
        ]);
    }

    /**
     * GET /api/v0.3/scales/{scale_code}/questions
     */
    public function questions(
        Request $request,
        string $scale_code,
        QuestionsService $questionsService,
        BigFivePackLoader $bigFivePackLoader
    ): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $code = strtoupper(trim($scale_code));
        if ($code === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }

        $row = $this->registry->getByCode($code, $orgId);
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $packId = (string) ($row['default_pack_id'] ?? '');
        $dirVersion = (string) ($row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'PACK_NOT_CONFIGURED',
                'message' => 'scale pack not configured.',
            ], 500);
        }

        $region = (string) ($request->query('region') ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($request->query('locale') ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        if ($code === 'BIG5_OCEAN') {
            $version = (string) ($row['default_dir_version'] ?? BigFivePackLoader::PACK_VERSION);
            $compiled = $bigFivePackLoader->readCompiledJson('questions.compiled.json', $version);
            if (!is_array($compiled)) {
                return response()->json([
                    'ok' => false,
                    'error_code' => 'COMPILED_MISSING',
                    'message' => 'BIG5_OCEAN compiled questions missing.',
                ], 500);
            }

            $questionsDoc = $compiled['questions_doc'] ?? null;
            if (!is_array($questionsDoc)) {
                return response()->json([
                    'ok' => false,
                    'error_code' => 'COMPILED_INVALID',
                    'message' => 'BIG5_OCEAN compiled questions invalid.',
                ], 500);
            }

            return response()->json([
                'ok' => true,
                'scale_code' => $code,
                'region' => $region,
                'locale' => $locale,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => (string) ($compiled['pack_version'] ?? $version),
                'questions' => $questionsDoc,
            ]);
        }

        $assetsBaseUrlOverride = $request->attributes->get('assets_base_url');
        $assetsBaseUrlOverride = is_string($assetsBaseUrlOverride) ? $assetsBaseUrlOverride : null;

        $loaded = $questionsService->loadByPack($packId, $dirVersion, $assetsBaseUrlOverride);
        if (!($loaded['ok'] ?? false)) {
            $error = (string) ($loaded['error_code'] ?? $loaded['error'] ?? 'READ_FAILED');
            $status = $error === 'NOT_FOUND' ? 404 : 500;
            return response()->json([
                'ok' => false,
                'error_code' => $error,
                'message' => (string) ($loaded['message'] ?? 'failed to load questions'),
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'scale_code' => $code,
            'region' => $region,
            'locale' => $locale,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => (string) ($loaded['content_package_version'] ?? ''),
            'questions' => $loaded['questions'],
        ]);
    }
}
