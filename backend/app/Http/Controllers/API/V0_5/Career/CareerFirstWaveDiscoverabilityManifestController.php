<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerFirstWaveDiscoverabilityManifestService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerFirstWaveDiscoverabilityManifestResource;

final class CareerFirstWaveDiscoverabilityManifestController extends Controller
{
    public function __construct(
        private readonly CareerFirstWaveDiscoverabilityManifestService $manifestService,
    ) {}

    public function show(): CareerFirstWaveDiscoverabilityManifestResource
    {
        return new CareerFirstWaveDiscoverabilityManifestResource(
            $this->manifestService->build()
        );
    }
}
