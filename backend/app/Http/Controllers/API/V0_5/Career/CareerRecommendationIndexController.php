<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerRecommendationIndexItemResource;
use App\Services\Career\Bundles\CareerRecommendationIndexBundleBuilder;
use Illuminate\Http\JsonResponse;

final class CareerRecommendationIndexController extends Controller
{
    public function __construct(
        private readonly CareerRecommendationIndexBundleBuilder $bundleBuilder,
    ) {}

    public function index(): JsonResponse
    {
        $items = CareerRecommendationIndexItemResource::collection(
            $this->bundleBuilder->build()
        )->resolve();

        return response()->json([
            'bundle_kind' => 'career_recommendation_index',
            'bundle_version' => 'career.protocol.recommendation_index.v1',
            'items' => $items,
        ]);
    }
}
