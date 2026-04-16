<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class CareerLaunchGovernanceClosureController extends Controller
{
    public function __construct(
        private readonly CareerLaunchGovernanceClosureService $closureService,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->closureService->build()->toArray());
    }
}
