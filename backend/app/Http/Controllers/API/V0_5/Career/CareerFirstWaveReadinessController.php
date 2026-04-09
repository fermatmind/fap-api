<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\FirstWaveReadinessSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\FirstWaveReadinessSummaryResource;

final class CareerFirstWaveReadinessController extends Controller
{
    public function __construct(
        private readonly FirstWaveReadinessSummaryService $summaryService,
    ) {}

    public function show(): FirstWaveReadinessSummaryResource
    {
        return new FirstWaveReadinessSummaryResource(
            $this->summaryService->build()
        );
    }
}
