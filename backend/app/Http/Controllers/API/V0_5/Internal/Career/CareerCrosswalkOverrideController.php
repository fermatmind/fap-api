<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Internal\Career;

use App\Domain\Career\Operations\CareerCrosswalkOverrideReadModelService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\Internal\CareerCrosswalkOverrideResource;

final class CareerCrosswalkOverrideController extends Controller
{
    public function __construct(
        private readonly CareerCrosswalkOverrideReadModelService $readModelService,
    ) {}

    public function show(string $slug): CareerCrosswalkOverrideResource
    {
        $summary = $this->readModelService->forSubject($slug);
        abort_if(! is_array($summary), 404, 'career_crosswalk_override_not_found');

        return new CareerCrosswalkOverrideResource($summary);
    }
}
