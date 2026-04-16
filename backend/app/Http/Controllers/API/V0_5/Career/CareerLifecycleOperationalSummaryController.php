<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Publish\CareerLifecycleOperationalSummaryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class CareerLifecycleOperationalSummaryController extends Controller
{
    public function __construct(
        private readonly CareerLifecycleOperationalSummaryService $summaryService,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->summaryService->build()->toArray());
    }
}
