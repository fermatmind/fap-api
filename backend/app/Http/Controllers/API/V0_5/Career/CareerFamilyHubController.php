<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFamilyHubResource;
use App\Services\Career\Bundles\CareerFamilyHubBundleBuilder;
use Illuminate\Http\JsonResponse;

final class CareerFamilyHubController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFamilyHubBundleBuilder $bundleBuilder,
    ) {}

    public function show(string $slug): JsonResponse|CareerFamilyHubResource
    {
        $bundle = $this->bundleBuilder->buildBySlug($slug);

        if ($bundle === null) {
            return $this->notFoundResponse('career family hub unavailable.');
        }

        return new CareerFamilyHubResource($bundle);
    }
}
