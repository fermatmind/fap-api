<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerJobListItemResource;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use Illuminate\Http\JsonResponse;

final class CareerJobListController extends Controller
{
    public function __construct(
        private readonly CareerJobListBundleBuilder $bundleBuilder,
    ) {}

    public function index(): JsonResponse
    {
        $items = CareerJobListItemResource::collection(
            $this->bundleBuilder->build()
        )->resolve();

        return response()->json([
            'bundle_kind' => 'career_job_index',
            'bundle_version' => 'career.protocol.job_index.v1',
            'items' => $items,
        ]);
    }
}
