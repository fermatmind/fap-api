<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveLifecycleSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveLifecycleSummaryResource;

final class CareerFirstWaveLifecycleController extends Controller
{
    public function __construct(
        private readonly CareerFirstWaveLifecycleSummaryService $summaryService,
    ) {}

    public function show(): CareerFirstWaveLifecycleSummaryResource
    {
        return new CareerFirstWaveLifecycleSummaryResource(
            $this->summaryService->build()
        );
    }
}
