<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use Illuminate\Http\JsonResponse;

final class CareerJobDetailController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerJobDetailBundleBuilder $bundleBuilder,
    ) {}

    public function show(string $slug): JsonResponse|CareerJobDetailResource
    {
        $bundle = $this->bundleBuilder->buildBySlug($slug);

        if ($bundle === null) {
            return $this->notFoundResponse('career job detail bundle unavailable.');
        }

        return new CareerJobDetailResource($bundle);
    }
}
