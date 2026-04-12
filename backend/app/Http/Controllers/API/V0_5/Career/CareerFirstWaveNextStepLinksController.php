<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveNextStepLinksService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveNextStepLinksSummaryResource;
use Illuminate\Http\JsonResponse;

final class CareerFirstWaveNextStepLinksController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFirstWaveNextStepLinksService $summaryService,
    ) {}

    public function show(string $slug): JsonResponse|CareerFirstWaveNextStepLinksSummaryResource
    {
        $summary = $this->summaryService->buildBySlug($slug);

        if ($summary === null) {
            return $this->notFoundResponse('career next-step links unavailable.');
        }

        return new CareerFirstWaveNextStepLinksSummaryResource($summary);
    }
}
