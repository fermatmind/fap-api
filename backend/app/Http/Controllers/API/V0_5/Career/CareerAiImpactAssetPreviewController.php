<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\CareerJobAiImpactAsset;
use App\Services\Career\AiImpactAssets\CareerAiImpactAssetPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerAiImpactAssetPreviewController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerAiImpactAssetPreviewService $previewService,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $locale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $asset = $this->previewService->previewAsset($slug, $locale);

        if (! $asset instanceof CareerJobAiImpactAsset) {
            return $this->notFoundResponse('career AI impact asset preview unavailable.');
        }

        return response()->json($this->previewService->publicPayload($asset));
    }
}
