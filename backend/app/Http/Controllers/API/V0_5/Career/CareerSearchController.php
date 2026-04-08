<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Http\Resources\Career\CareerSearchResultResource;
use App\Services\Career\Bundles\CareerSearchBundleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CareerSearchController extends Controller
{
    public function __construct(
        private readonly CareerSearchBundleBuilder $bundleBuilder,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->merge([
            'q' => is_string($request->query('q')) ? trim((string) $request->query('q')) : $request->query('q'),
        ]);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'en-US', 'zh', 'zh-CN'])],
            'mode' => ['nullable', 'string', Rule::in(['auto', 'exact', 'prefix'])],
        ]);

        $items = CareerSearchResultResource::collection(
            $this->bundleBuilder->build(
                query: (string) $validated['q'],
                limit: (int) ($validated['limit'] ?? 10),
                locale: isset($validated['locale']) ? (string) $validated['locale'] : null,
                mode: (string) ($validated['mode'] ?? 'auto'),
            )
        )->resolve();

        return response()->json([
            'bundle_kind' => 'career_search_results',
            'bundle_version' => 'career.protocol.search_results.v1',
            'query' => [
                'q' => (string) $validated['q'],
                'limit' => (int) ($validated['limit'] ?? 10),
                'locale' => isset($validated['locale']) ? (string) $validated['locale'] : null,
                'mode' => (string) ($validated['mode'] ?? 'auto'),
            ],
            'items' => $items,
        ]);
    }
}
