<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutQueueSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveRolloutQueueSummaryResource;

final class CareerFirstWaveRolloutQueueController extends Controller
{
    public function __construct(
        private readonly CareerFirstWaveRolloutQueueSummaryService $summaryService,
    ) {}

    public function show(): CareerFirstWaveRolloutQueueSummaryResource
    {
        return new CareerFirstWaveRolloutQueueSummaryResource(
            $this->summaryService->build()
        );
    }
}
