<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerExplainabilitySummaryResource;
use App\Services\Career\Explainability\CareerExplainabilitySummaryBuilder;
use Illuminate\Http\JsonResponse;

final class CareerJobExplainabilityController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerExplainabilitySummaryBuilder $summaryBuilder,
    ) {}

    public function show(string $slug): JsonResponse|CareerExplainabilitySummaryResource
    {
        $summary = $this->summaryBuilder->buildForJobSlug($slug);

        if ($summary === null) {
            return $this->notFoundResponse('career explainability summary unavailable.');
        }

        return new CareerExplainabilitySummaryResource($summary);
    }
}
