<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerDatasetMethodResource;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;

final class CareerDatasetMethodController extends Controller
{
    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $contractBuilder,
    ) {}

    public function show(): CareerDatasetMethodResource
    {
        return new CareerDatasetMethodResource($this->contractBuilder->buildMethodContract());
    }
}
