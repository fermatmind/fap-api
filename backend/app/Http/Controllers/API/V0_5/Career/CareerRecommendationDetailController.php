<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerRecommendationDetailResource;
use App\Services\Career\Bundles\CareerRecommendationDetailBundleBuilder;
use Illuminate\Http\JsonResponse;

final class CareerRecommendationDetailController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerRecommendationDetailBundleBuilder $bundleBuilder,
    ) {}

    public function show(string $type): JsonResponse|CareerRecommendationDetailResource
    {
        $bundle = $this->bundleBuilder->buildByType($type);

        if ($bundle === null) {
            return $this->notFoundResponse('career recommendation detail bundle unavailable.');
        }

        return new CareerRecommendationDetailResource($bundle);
    }
}
