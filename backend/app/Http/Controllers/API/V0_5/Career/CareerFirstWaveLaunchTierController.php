<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveLaunchTierSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveLaunchTierSummaryResource;

final class CareerFirstWaveLaunchTierController extends Controller
{
    public function __construct(
        private readonly CareerFirstWaveLaunchTierSummaryService $summaryService,
    ) {}

    public function show(): CareerFirstWaveLaunchTierSummaryResource
    {
        return new CareerFirstWaveLaunchTierSummaryResource(
            $this->summaryService->build()
        );
    }
}
