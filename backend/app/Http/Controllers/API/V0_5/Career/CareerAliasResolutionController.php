<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerAliasResolutionResource;
use App\Services\Career\Bundles\CareerAliasResolutionBundleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CareerAliasResolutionController extends Controller
{
    public function __construct(
        private readonly CareerAliasResolutionBundleBuilder $bundleBuilder,
    ) {}

    public function show(Request $request): JsonResponse|CareerAliasResolutionResource
    {
        $request->merge([
            'q' => is_string($request->query('q')) ? trim((string) $request->query('q')) : $request->query('q'),
        ]);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'en-US', 'zh', 'zh-CN'])],
        ]);

        $bundle = $this->bundleBuilder->build(
            query: (string) $validated['q'],
            locale: isset($validated['locale']) ? (string) $validated['locale'] : null,
        );

        return new CareerAliasResolutionResource($bundle);
    }
}
