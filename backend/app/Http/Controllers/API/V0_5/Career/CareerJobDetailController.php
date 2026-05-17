<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Services\Career\Bundles\CareerCnProxyPublicOwnerSurfaceBuilder;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerJobDetailController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerJobDetailBundleBuilder $bundleBuilder,
        private readonly CareerCnProxyPublicOwnerSurfaceBuilder $cnProxySurfaceBuilder,
    ) {}

    public function show(Request $request, string $slug): JsonResponse|CareerJobDetailResource
    {
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $bundle = $this->bundleBuilder->buildBySlug($slug, $publicLocale);

        if ($bundle === null) {
            $cnProxySurface = $this->cnProxySurfaceBuilder->buildBySlug($slug, $publicLocale);
            if ($cnProxySurface !== null) {
                return response()->json($cnProxySurface);
            }

            return $this->notFoundResponse('career job detail bundle unavailable.');
        }

        return new CareerJobDetailResource($bundle);
    }
}
