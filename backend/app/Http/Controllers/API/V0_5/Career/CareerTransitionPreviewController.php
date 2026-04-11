<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerTransitionPreviewResource;
use App\Services\Career\Bundles\CareerTransitionPreviewBundleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerTransitionPreviewController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerTransitionPreviewBundleBuilder $bundleBuilder,
    ) {}

    public function show(Request $request): JsonResponse|CareerTransitionPreviewResource
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'min:1', 'max:16'],
        ]);

        $bundle = $this->bundleBuilder->buildByType((string) $validated['type']);

        if ($bundle === null) {
            return $this->notFoundResponse('career transition preview unavailable.');
        }

        return new CareerTransitionPreviewResource($bundle);
    }
}
