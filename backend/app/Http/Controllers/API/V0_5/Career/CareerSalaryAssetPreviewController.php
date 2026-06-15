<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\CareerJobSalaryAsset;
use App\Services\Career\SalaryAssets\CareerSalaryAssetPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerSalaryAssetPreviewController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerSalaryAssetPreviewService $previewService,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $locale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $asset = $this->previewService->previewAsset($slug, $locale);

        if (! $asset instanceof CareerJobSalaryAsset) {
            return $this->notFoundResponse('career salary asset preview unavailable.');
        }

        return response()->json($this->previewService->publicPayload($asset));
    }
}
