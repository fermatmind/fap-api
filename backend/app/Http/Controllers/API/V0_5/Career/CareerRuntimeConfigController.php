<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerRuntimeConfigResource;
use App\Services\Career\Config\CareerThresholdExperimentAuthorityService;

final class CareerRuntimeConfigController extends Controller
{
    public function __construct(
        private readonly CareerThresholdExperimentAuthorityService $authorityService,
    ) {}

    public function show(): CareerRuntimeConfigResource
    {
        return new CareerRuntimeConfigResource($this->authorityService->buildAuthority());
    }
}
