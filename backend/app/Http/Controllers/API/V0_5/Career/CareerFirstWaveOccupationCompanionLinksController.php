<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveOccupationCompanionLinksService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveOccupationCompanionLinksSummaryResource;
use Illuminate\Http\JsonResponse;

final class CareerFirstWaveOccupationCompanionLinksController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFirstWaveOccupationCompanionLinksService $summaryService,
    ) {}

    public function show(string $slug): JsonResponse|CareerFirstWaveOccupationCompanionLinksSummaryResource
    {
        $summary = $this->summaryService->buildBySlug($slug);

        if ($summary === null) {
            return $this->notFoundResponse('career companion links unavailable.');
        }

        return new CareerFirstWaveOccupationCompanionLinksSummaryResource($summary);
    }
}
