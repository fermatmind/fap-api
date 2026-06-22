<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Career\AiImpactAssets\CareerAiImpactPreviewDetailShellBuilder;
use App\Services\Career\Bundles\CareerCnProxyPublicOwnerSurfaceBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerJobDetailController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly CareerCnProxyPublicOwnerSurfaceBuilder $cnProxySurfaceBuilder,
        private readonly CareerAiImpactPreviewDetailShellBuilder $aiImpactPreviewDetailShellBuilder,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $payload = $this->responseCache->jobDetailPayload($slug, $publicLocale);

        if ($payload === null) {
            $cnProxySurface = $this->cnProxySurfaceBuilder->buildBySlug($slug, $publicLocale);
            if ($cnProxySurface !== null) {
                return response()->json($cnProxySurface);
            }

            $aiImpactPreviewShell = $this->aiImpactPreviewDetailShellBuilder->build($slug, $publicLocale);
            if ($aiImpactPreviewShell !== null) {
                return response()->json($aiImpactPreviewShell);
            }

            return $this->notFoundResponse('career job detail bundle unavailable.');
        }

        return response()->json($payload);
    }
}
